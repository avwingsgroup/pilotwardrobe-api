<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Audience resolution for AD14 broadcasts. Marketing email respects opt-outs. */
final class BroadcastService
{
    /** @param array{roles?: list<string>, countries?: list<string>, kyc?: string, sellers_only?: bool, buyers_only?: bool} $audience */
    public function audienceQuery(array $audience, array $channels): Builder
    {
        $query = User::query()->whereIn('role', $audience['roles'] ?? ['user', 'aviator'])->where('status', 'active');

        if (! empty($audience['countries'])) {
            $query->whereIn('country_code', array_map('strtoupper', $audience['countries']));
        }
        if (($audience['kyc'] ?? null) === 'verified') {
            $query->whereHas('kyc', fn ($q) => $q->where('status', 'approved'));
        } elseif (($audience['kyc'] ?? null) === 'unverified') {
            $query->whereDoesntHave('kyc', fn ($q) => $q->where('status', 'approved'));
        }
        if (! empty($audience['sellers_only'])) {
            $query->where('sales_count', '>', 0);
        }
        if (! empty($audience['buyers_only'])) {
            $query->where('sales_count', 0);
        }
        if ($channels === ['email']) {
            // Pure email campaigns only reach people who opted in to marketing.
            $query->where('marketing_opt_in', true);
        }

        return $query;
    }

    /** @return array{total: int, email: int, push: int, in_app: int} */
    public function reach(array $audience, array $channels): array
    {
        $base = $this->audienceQuery($audience, $channels);
        $total = (clone $base)->count();

        return [
            'total' => $total,
            'email' => in_array('email', $channels, true) ? (clone $base)->where('marketing_opt_in', true)
                ->whereDoesntHave('notificationPreferences', fn ($q) => $q->where('category', 'marketing')->where('email', false))->count() : 0,
            'push' => in_array('push', $channels, true) ? (clone $base)->whereHas('deviceTokens', fn ($q) => $q->whereNull('invalidated_at'))->count() : 0,
            'in_app' => in_array('in_app', $channels, true) ? $total : 0,
        ];
    }

    public function present(Broadcast $b): array
    {
        return [
            'id' => $b->id,
            'title' => $b->title,
            'body' => $b->body,
            'channels' => $b->channels,
            'audience' => $b->audience,
            'link' => $b->link,
            'status' => $b->status,
            'recipients_count' => $b->recipients_count,
            'scheduled_at' => $b->scheduled_at?->toIso8601ZuluString(),
            'sent_at' => $b->sent_at?->toIso8601ZuluString(),
        ];
    }
}
