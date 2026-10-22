<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\DisputeStatus;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Badge counters for `GET /auth/me` (handoff §2.3). One round-trip per counter, all indexed. */
final class CountersService
{
    /** @return array<string, int> */
    public function for(User $user): array
    {
        $id = $user->id;
        $open = fn (array $cases) => array_map(fn ($c) => $c->value, $cases);

        return [
            'unread_messages' => $this->unreadMessages($id),
            'unread_notifications' => (int) DB::table('notifications')->where('user_id', $id)->whereNull('read_at')->count(),
            'cart_items' => (int) DB::table('cart_items')->where('user_id', $id)->count(),
            'open_offers' => (int) DB::table('offers')->where('recipient_id', $id)->where('status', 'pending')->count()
                + (int) DB::table('swap_requests')->where('counterparty_id', $id)->where('status', 'pending')->count(),
            'active_orders' => (int) DB::table('orders')
                ->where(fn ($q) => $q->where('buyer_id', $id)->orWhere('seller_id', $id))
                ->whereIn('status', $open(OrderStatus::open()))
                ->count(),
            'active_listings' => (int) DB::table('listings')->where('seller_id', $id)->whereNull('deleted_at')
                ->whereIn('status', [ListingStatus::Active->value, ListingStatus::Reserved->value])->count(),
            'open_disputes' => (int) DB::table('disputes')
                ->where(fn ($q) => $q->where('buyer_id', $id)->orWhere('seller_id', $id))
                ->whereIn('status', $open(DisputeStatus::active()))
                ->count(),
        ];
    }

    public function unreadMessages(string $userId): int
    {
        return (int) DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where(function ($q) use ($userId) {
                $q->where(fn ($b) => $b->where('c.buyer_id', $userId)->whereRaw("m.created_at > coalesce(c.buyer_last_read_at, 'epoch'::timestamp)"))
                    ->orWhere(fn ($s) => $s->where('c.seller_id', $userId)->whereRaw("m.created_at > coalesce(c.seller_last_read_at, 'epoch'::timestamp)"));
            })
            ->where(fn ($q) => $q->whereNull('m.sender_id')->orWhere('m.sender_id', '!=', $userId))
            ->count();
    }
}
