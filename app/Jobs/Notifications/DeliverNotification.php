<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use App\Integrations\ProviderException;
use App\Integrations\Push\InvalidDeviceToken;
use App\Integrations\Push\PushProvider;
use App\Integrations\Sms\FailoverSmsSender;
use App\Integrations\WhatsApp\WhatsAppProvider;
use App\Mail\TransactionalMail;
use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one `notification_deliveries` row over its channel.
 *
 * Idempotent: a row already `sent` is skipped, and a short lock stops two workers
 * delivering the same row concurrently. Provider errors classified as permanent
 * fail fast; transient ones retry with backoff. Exhausted rows end as `failed`
 * and stay visible for support (the triggering business action is unaffected).
 */
final class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(FailoverSmsSender $sms, PushProvider $push, WhatsAppProvider $whatsapp): void
    {
        $lock = Cache::lock("delivery:{$this->deliveryId}", $this->timeout);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $delivery = NotificationDelivery::query()->find($this->deliveryId);
            if ($delivery === null || in_array($delivery->status, ['sent', 'delivered', 'skipped'], true)) {
                return;
            }

            $delivery->increment('attempts');

            try {
                [$provider, $messageId, $status] = match ($delivery->channel) {
                    'email' => $this->sendEmail($delivery),
                    'sms' => $this->sendSms($delivery, $sms),
                    'push' => $this->sendPush($delivery, $push),
                    'whatsapp' => $this->sendWhatsApp($delivery, $whatsapp),
                    default => [null, null, 'skipped'],
                };

                $delivery->forceFill([
                    'status' => $status,
                    'provider' => $provider,
                    'provider_message_id' => $messageId,
                    'sent_at' => $status === 'sent' ? now() : null,
                    'error' => null,
                ])->save();
                self::redactIfSensitive($delivery);
            } catch (ProviderException $e) {
                $delivery->forceFill(['error' => mb_substr($e->getMessage(), 0, 1000)])->save();
                if (! $e->retryable) {
                    $delivery->forceFill(['status' => 'failed'])->save();
                    self::redactIfSensitive($delivery);
                    Log::warning('notifications.delivery_failed_permanently', ['delivery_id' => $delivery->id, 'channel' => $delivery->channel]);

                    return;
                }

                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $e): void
    {
        NotificationDelivery::query()->whereKey($this->deliveryId)
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        if ($delivery = NotificationDelivery::query()->find($this->deliveryId)) {
            self::redactIfSensitive($delivery);
        }
    }

    /** Codes and sign-in links must not outlive their delivery in the database. */
    private static function redactIfSensitive(NotificationDelivery $delivery): void
    {
        if (config('notifications')[$delivery->template]['sensitive'] ?? false) {
            $delivery->forceFill(['payload' => ['redacted' => true]])->save();
        }
    }

    private function sendEmail(NotificationDelivery $delivery): array
    {
        $payload = $delivery->payload;
        $mail = new TransactionalMail($payload['email'], (bool) ($payload['unsubscribable'] ?? false), (string) $delivery->id);

        try {
            Mail::to($delivery->recipient, $payload['name'] ?? null)->locale($payload['locale'] ?? 'en')->send($mail);
        } catch (Throwable $e) {
            throw ProviderException::transient('mail', $e->getMessage(), $e);
        }

        return [config('mail.default'), null, 'sent'];
    }

    private function sendSms(NotificationDelivery $delivery, FailoverSmsSender $sms): array
    {
        $result = $sms->send((string) $delivery->recipient, (string) $delivery->payload['text']);

        return [$result['provider'], $result['message_id'], 'sent'];
    }

    private function sendPush(NotificationDelivery $delivery, PushProvider $push): array
    {
        $tokens = DeviceToken::query()->where('user_id', $delivery->user_id)->whereNull('invalidated_at')->get();
        if ($tokens->isEmpty()) {
            return ['fcm', null, 'skipped'];
        }

        $payload = $delivery->payload;
        $sent = 0;
        $lastId = null;
        foreach ($tokens as $token) {
            try {
                $lastId = $push->send($token->token, (string) $payload['title'], (string) $payload['body'], [
                    'link' => (string) ($payload['link'] ?? '/'),
                    'template' => $delivery->template,
                    'notification_id' => (string) $delivery->notification_id,
                ]);
                $token->forceFill(['last_used_at' => now()])->save();
                $sent++;
            } catch (InvalidDeviceToken) {
                $token->forceFill(['invalidated_at' => now()])->save();
            }
        }

        return ['fcm', $lastId, $sent > 0 ? 'sent' : 'skipped'];
    }

    private function sendWhatsApp(NotificationDelivery $delivery, WhatsAppProvider $whatsapp): array
    {
        $payload = $delivery->payload;
        $id = $whatsapp->sendTemplate((string) $delivery->recipient, (string) $payload['template'], config('services.whatsapp.language', 'en'), $payload['parameters'] ?? []);

        return ['whatsapp', $id, 'sent'];
    }
}
