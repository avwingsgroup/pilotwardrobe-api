<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Enums\MediaType;
use App\Exceptions\ApiException;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use App\Services\Media\MediaService;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Buyer ↔ seller messaging (VB10). Bodies are plain text (control characters stripped,
 * ≤ 4,000 chars). Messages that look like off-platform payment solicitation are flagged
 * and raise an automated Trust & Safety report.
 */
final class ConversationService
{
    private const PATTERNS = [
        'phone_number' => '/(?:\+?\d[\s\-().]?){10,14}\d/',
        'email' => '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        'bank_details' => '/\b(?:acct|account|a\/c)\s*(?:no\.?|number|#)?\s*:?\s*\d{8,12}\b|\b(?:iban|sort code|routing)\b/i',
        'off_platform_payment' => '/\b(?:pay(?:ment)?\s+(?:outside|off[- ]platform|directly)|bank transfer|send (?:the )?money|western union|moneygram|crypto|usdt|whats\s?app me|paypal friends|cash app|outside (?:pilotwardrobe|the app|escrow))\b/i',
    ];

    public function __construct(
        private readonly MediaService $media,
        private readonly NotificationService $notifications,
    ) {}

    /** Opens (or returns the existing) thread between a buyer and the listing's seller. */
    public function open(User $buyer, Listing $listing): Conversation
    {
        if ($buyer->id === $listing->seller_id) {
            throw ApiException::conflict("You can't message yourself about your own listing.");
        }

        $existing = Conversation::query()->where('listing_id', $listing->id)->where('buyer_id', $buyer->id)->where('seller_id', $listing->seller_id)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => Conversation::query()->create([
                'listing_id' => $listing->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $listing->seller_id,
            ]));
        } catch (UniqueConstraintViolationException) {
            return Conversation::query()->where('listing_id', $listing->id)->where('buyer_id', $buyer->id)->where('seller_id', $listing->seller_id)->firstOrFail();
        }
    }

    /** @param list<string> $attachmentIds */
    public function send(Conversation $conversation, User $sender, string $body, array $attachmentIds = []): Message
    {
        if (! $conversation->isParticipant($sender)) {
            throw ApiException::notFound('Conversation not found.');
        }

        $body = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body));
        if ($body === '' && $attachmentIds === []) {
            throw ApiException::validation(['body' => 'Write a message first.']);
        }
        if (mb_strlen($body) > (int) config('platform.messages.max_length')) {
            throw ApiException::validation(['body' => 'Messages can be up to 4,000 characters.']);
        }

        $flags = $this->flags($body);

        $message = DB::transaction(function () use ($conversation, $sender, $body, $attachmentIds, $flags) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'kind' => 'text',
                'body' => $body,
                'flags' => $flags ?: null,
            ]);

            foreach ($this->media->claim($sender, $attachmentIds, [MediaType::Message, MediaType::Listing], 'attachment_ids', $message) as $i => $file) {
                $file->attachTo($message, 'attachments', $i);
            }

            $readColumn = $sender->id === $conversation->buyer_id ? 'buyer_last_read_at' : 'seller_last_read_at';
            $conversation->forceFill([
                'last_message_preview' => mb_substr($body !== '' ? $body : 'Attachment', 0, 200),
                'last_message_at' => now(),
                $readColumn => now(),
                'is_flagged' => $conversation->is_flagged || in_array('off_platform_payment', $flags, true),
            ])->save();

            if (in_array('off_platform_payment', $flags, true)) {
                Report::query()->create([
                    'reporter_id' => null,
                    'target_type' => 'message',
                    'target_id' => $message->id,
                    'reason' => 'off_platform_payment',
                    'detail' => 'Automated: possible off-platform payment solicitation ('.implode(', ', $flags).').',
                    'severity' => 'high',
                    'source' => 'automated',
                ]);
            }

            return $message;
        });

        $this->notifyRecipient($conversation, $sender, $body);

        return $message;
    }

    /** Offer/swap/system events posted into the listing thread. */
    public function systemMessage(Listing $listing, User $buyer, string $kind, string $body, array $context = []): void
    {
        $conversation = $this->open($buyer, $listing);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $kind === 'system' ? null : $buyer->id,
            'kind' => $kind,
            'body' => $body,
            'context' => $context ?: null,
        ]);
        $conversation->forceFill(['last_message_preview' => mb_substr($body, 0, 200), 'last_message_at' => now()])->save();
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        $column = $user->id === $conversation->buyer_id ? 'buyer_last_read_at' : 'seller_last_read_at';
        $conversation->forceFill([$column => now()])->save();

        Message::query()->where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->whereNull('sender_id')->orWhere('sender_id', '!=', $user->id))
            ->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** @return list<string> */
    public function flags(string $body): array
    {
        $flags = [];
        foreach (self::PATTERNS as $flag => $pattern) {
            if (preg_match($pattern, $body)) {
                $flags[] = $flag;
            }
        }
        // Contact details alone are fine; contact details plus payment talk is solicitation.
        if (! in_array('off_platform_payment', $flags, true) && in_array('bank_details', $flags, true)) {
            $flags[] = 'off_platform_payment';
        }

        return $flags;
    }

    /** Email digest at most once per 15 minutes per conversation; push every time. */
    private function notifyRecipient(Conversation $conversation, User $sender, string $body): void
    {
        $recipientId = $conversation->otherParticipantId($sender);
        $recipient = User::query()->find($recipientId);
        if ($recipient === null) {
            return;
        }

        $column = $recipientId === $conversation->buyer_id ? 'buyer_notified_at' : 'seller_notified_at';
        $window = (int) config('platform.messages.email_digest_minutes');
        $last = $conversation->{$column};
        if ($last !== null && $last->gt(now()->subMinutes($window))) {
            return;
        }

        $conversation->forceFill([$column => now()])->save();
        $this->notifications->notify($recipient, 'message.received', [
            'sender' => $sender->shortName(),
            'preview' => mb_substr($body, 0, 120),
            'conversation_id' => $conversation->id,
        ], "message.received:{$conversation->id}:".now()->format('YmdHi'));
    }
}
