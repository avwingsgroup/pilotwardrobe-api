<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\Media\MediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Data-retention housekeeping (daily): abandoned uploads (Cloudinary assets included),
 * expired idempotency keys, OTP codes, trusted devices, generated statements/exports.
 */
final class PruneTemporaryData extends Command
{
    protected $signature = 'pilotwardrobe:prune';

    protected $description = 'Remove abandoned uploads and expired temporary records.';

    public function handle(MediaService $media): int
    {
        $hours = (int) config('platform.media.unattached_ttl_hours');
        $removed = 0;
        Media::query()->whereNull('attachable_id')->where('created_at', '<', now()->subHours($hours))
            // Avatars/covers are linked from users rather than attached.
            ->whereNotIn('id', DB::table('users')->whereNotNull('avatar_media_id')->select('avatar_media_id'))
            ->whereNotIn('id', DB::table('users')->whereNotNull('cover_media_id')->select('cover_media_id'))
            ->orderBy('id')->chunkById(200, function ($rows) use ($media, &$removed) {
                foreach ($rows as $row) {
                    $media->delete($row);
                    $removed++;
                }
            });

        $keys = DB::table('idempotency_keys')->where('expires_at', '<', now())->delete();
        $codes = DB::table('one_time_codes')->where('expires_at', '<', now()->subDay())->delete();
        $devices = DB::table('trusted_devices')->where('expires_at', '<', now())->delete();
        $sessions = DB::table('auth_sessions')->whereNotNull('revoked_at')->where('revoked_at', '<', now()->subDays(90))->delete();

        foreach (['statements', 'exports'] as $dir) {
            foreach (Storage::disk('local')->allFiles($dir) as $file) {
                if (Storage::disk('local')->lastModified($file) < now()->subDays(2)->timestamp) {
                    Storage::disk('local')->delete($file);
                }
            }
        }

        $this->info("media: {$removed}, idempotency keys: {$keys}, codes: {$codes}, trusted devices: {$devices}, old sessions: {$sessions}");

        return self::SUCCESS;
    }
}
