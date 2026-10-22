<?php

declare(strict_types=1);

namespace App\Jobs\Admin;

use App\Models\Broadcast;
use App\Models\User;
use App\Services\Admin\BroadcastService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fans a broadcast out to its audience in chunks. Each recipient gets a dedupe key, so a
 * retried job never notifies anyone twice. Channel selection still respects preferences
 * (marketing email opt-outs) inside NotificationService.
 */
final class SendBroadcast implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    public function __construct(public readonly string $broadcastId)
    {
        $this->onConnection(config('queue.long_connection'))->onQueue('long');
    }

    public function uniqueId(): string
    {
        return $this->broadcastId;
    }

    public function handle(BroadcastService $broadcasts, NotificationService $notifications): void
    {
        $broadcast = Broadcast::query()->find($this->broadcastId);
        if ($broadcast === null || in_array($broadcast->status, ['sent', 'cancelled'], true)) {
            return;
        }
        if ($broadcast->scheduled_at && $broadcast->scheduled_at->isFuture()) {
            $this->release((int) max(60, now()->diffInSeconds($broadcast->scheduled_at)));

            return;
        }

        $broadcast->forceFill(['status' => 'sending'])->save();
        $sent = 0;

        $broadcasts->audienceQuery($broadcast->audience ?? [], $broadcast->channels ?? [])
            ->chunkById(500, function ($users) use ($broadcast, $notifications, &$sent) {
                /** @var User $user */
                foreach ($users as $user) {
                    $notifications->notify($user, 'marketing.broadcast', [
                        'title' => $broadcast->title,
                        'body' => $broadcast->body,
                        'link' => $broadcast->link ?: '/',
                    ], "broadcast:{$broadcast->id}:{$user->id}");
                    $sent++;
                }
            });

        $broadcast->forceFill(['status' => 'sent', 'sent_at' => now(), 'recipients_count' => $sent])->save();
    }
}
