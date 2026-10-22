<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Jobs\Notifications\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Single entry point for user-facing notifications (contract §3, handoff VB11).
 *
 * One call fans out to every channel the template defines (config/notifications.php),
 * filtered by the recipient's preferences. In-app rows are written immediately; each
 * external channel becomes a `notification_deliveries` row plus a queued job dispatched
 * after the surrounding transaction commits. A unique `dedupe_key` per channel makes
 * repeated dispatches (retried jobs, replayed webhooks) deliver at most once.
 *
 * Writes run inside a nested transaction (a SAVEPOINT), so a duplicate key rolls back
 * only the notification — PostgreSQL would otherwise abort the caller's transaction.
 * Notification failures never fail the business operation that triggered them.
 */
final class NotificationService
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * @param  array<string, scalar|null>  $data  placeholder values (already formatted)
     * @param  string|null  $dedupeKey  stable key for "this event" (e.g. `order.paid:ord_…`)
     */
    public function notify(User $user, string $template, array $data = [], ?string $dedupeKey = null): ?Notification
    {
        $definition = $this->definition($template);
        $data = ['first_name' => $user->first_name, 'name' => $user->displayName()] + $data;
        $dedupeKey ??= $template.':'.$user->id.':'.Str::ulid();

        try {
            return DB::transaction(fn () => $this->fanOut($user, $template, $definition, $data, $dedupeKey));
        } catch (UniqueConstraintViolationException) {
            return Notification::query()->where('dedupe_key', $dedupeKey)->first();
        } catch (Throwable $e) {
            Log::error('notifications.dispatch_failed', ['template' => $template, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Email to an address that may not belong to an account (invitations, contact receipts, newsletter). */
    public function emailAddress(string $email, string $template, array $data = [], ?string $name = null, ?string $dedupeKey = null): void
    {
        $definition = $this->definition($template);
        if (empty($definition['email'])) {
            throw new InvalidArgumentException("Template {$template} has no email part.");
        }

        $this->safely($template, fn () => $this->queue('email', $template, $email, null, null, $dedupeKey ?? $template.':'.Str::ulid(), [
            'email' => TemplateRenderer::email($definition['email'], $data),
            'name' => $name,
            'unsubscribable' => false,
            'locale' => app()->getLocale(),
        ]));
    }

    /** SMS security codes (2FA). Bypasses preferences; still logged, deduplicated and queued. */
    public function smsCode(User $user, string $template, array $data, string $dedupeKey): void
    {
        $definition = $this->definition($template);

        $this->safely($template, fn () => $this->queue('sms', $template, (string) $user->phone, $user, null, $dedupeKey, [
            'text' => TemplateRenderer::text($definition['sms'], $data),
        ]));
    }

    /** @return array{email: bool, push: bool, sms: bool, whatsapp: bool} */
    public function preference(User $user, NotificationCategory $category): array
    {
        $row = NotificationPreference::query()->where('user_id', $user->id)->where('category', $category->value)->first();

        return [
            'email' => $row->email ?? true,
            'push' => $row->push ?? true,
            'sms' => $row->sms ?? false,
            'whatsapp' => $row->whatsapp ?? false,
        ];
    }

    private function fanOut(User $user, string $template, array $definition, array $data, string $dedupeKey): ?Notification
    {
        $category = NotificationCategory::tryFrom($definition['category'] ?? 'account') ?? NotificationCategory::Account;
        $preference = $this->preference($user, $category);
        $transactional = (bool) ($definition['transactional'] ?? false);

        $notification = ! empty($definition['in_app'])
            ? $this->createInApp($user, $template, $definition, $data, $category, $dedupeKey)
            : null;

        if (! empty($definition['email']) && ($transactional || $category->emailAlwaysOn() || $preference['email'])) {
            $this->queue('email', $template, $user->email, $user, $notification, $dedupeKey, [
                'email' => TemplateRenderer::email($definition['email'], $data),
                'name' => $user->displayName(),
                'unsubscribable' => ! $transactional,
                'locale' => $user->locale,
            ]);
        }

        if (! empty($definition['push']) && $preference['push']) {
            $this->queue('push', $template, null, $user, $notification, $dedupeKey, [
                'title' => TemplateRenderer::text($definition['title'] ?? '', $data),
                'body' => TemplateRenderer::text($definition['body'] ?? '', $data),
                'link' => TemplateRenderer::path($definition['link'] ?? '/', $data),
            ]);
        }

        // Transactional order/payout SMS still require opt-in; only security codes bypass it (smsCode()).
        if (! empty($definition['sms']) && $user->phone && $preference['sms']) {
            $this->queue('sms', $template, $user->phone, $user, $notification, $dedupeKey, [
                'text' => TemplateRenderer::text($definition['sms'], $data),
            ]);
        }

        if (! empty($definition['whatsapp']) && $user->phone && $preference['whatsapp']
            && $this->settings->featureEnabled('whatsapp_notifications')) {
            $this->queue('whatsapp', $template, $user->phone, $user, $notification, $dedupeKey, [
                'template' => $definition['whatsapp'],
                'parameters' => array_values(array_map('strval', array_filter(
                    array_diff_key($data, array_flip(['first_name', 'name'])),
                    fn ($v) => is_scalar($v),
                ))),
            ]);
        }

        return $notification;
    }

    private function createInApp(User $user, string $template, array $definition, array $data, NotificationCategory $category, string $dedupeKey): Notification
    {
        $actions = null;
        if (! empty($definition['actions'])) {
            $actions = array_map(fn (array $a) => [
                'label' => $a['label'],
                'to' => TemplateRenderer::path($a['to'], $data),
                'variant' => $a['variant'] ?? 'primary',
            ], $definition['actions']);
        }

        return Notification::query()->create([
            'user_id' => $user->id,
            'type' => $template,
            'category' => $category->value,
            'icon' => $definition['icon'] ?? 'verified',
            'title' => mb_substr(TemplateRenderer::text($definition['title'] ?? $template, $data), 0, 200),
            'body' => mb_substr(TemplateRenderer::text($definition['body'] ?? '', $data), 0, 500) ?: null,
            'link' => isset($definition['link']) ? TemplateRenderer::path($definition['link'], $data) : null,
            'actions' => $actions,
            'data' => array_intersect_key($data, array_flip(['order_id', 'offer_id', 'swap_id', 'dispute_id', 'listing_id', 'conversation_id'])) ?: null,
            'dedupe_key' => $dedupeKey,
        ]);
    }

    private function queue(string $channel, string $template, ?string $recipient, ?User $user, ?Notification $notification, string $dedupeKey, array $payload): void
    {
        $delivery = NotificationDelivery::query()->create([
            'notification_id' => $notification?->id,
            'user_id' => $user?->id,
            'channel' => $channel,
            'template' => $template,
            'recipient' => $recipient,
            'payload' => $payload,
            'status' => 'queued',
            'dedupe_key' => mb_substr($dedupeKey.':'.$channel, 0, 191),
        ]);

        DeliverNotification::dispatch($delivery->id)->afterCommit();
    }

    private function safely(string $template, callable $write): void
    {
        try {
            DB::transaction($write);
        } catch (UniqueConstraintViolationException) {
            // already queued for this event
        } catch (Throwable $e) {
            Log::error('notifications.dispatch_failed', ['template' => $template, 'error' => $e->getMessage()]);
        }
    }

    private function definition(string $template): array
    {
        // Keys contain dots (`order.paid`), so read the whole map rather than using dot notation.
        $definition = config('notifications')[$template] ?? null;
        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown notification template [{$template}].");
        }

        return $definition;
    }
}
