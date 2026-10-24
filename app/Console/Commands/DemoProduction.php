<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\Catalogue\HomeController;
use App\Integrations\Media\MediaStorage;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Temporary demo data on a real server, for testing the storefront end to end.
 *
 *   1. back up the database (docs/deployment-vps.md §17)
 *   2. php artisan demo:production seed       demo members, listings + photos, an order, an offer, a conversation
 *   3. test
 *   4. php artisan demo:production cleanup    deletes the demo photos from media storage (Cloudinary)
 *   5. restore the backup                     removes every demo row, including immutable ledger/audit rows
 *
 * While seeding, notifications are written to the log instead of being sent: the demo accounts
 * use example.com addresses and placeholder phone numbers that may belong to real people.
 */
final class DemoProduction extends Command
{
    protected $signature = 'demo:production {action : seed | cleanup} {--force : Skip the confirmation prompt}';

    protected $description = 'Seed temporary demo data on a server (after a backup), or remove its uploaded photos.';

    public function handle(MediaStorage $storage): int
    {
        return match ((string) $this->argument('action')) {
            'seed' => $this->seed(),
            'cleanup' => $this->cleanup($storage),
            default => $this->invalidAction(),
        };
    }

    private function seed(): int
    {
        $this->warn('This adds demo accounts with the public password "Password1", 20 listings with photos and sample orders.');
        $this->warn('Remove them afterwards by restoring the database backup taken BEFORE this command (docs/deployment-vps.md §17).');
        if (! $this->option('force') && ! $this->confirm('Have you taken a database backup you will restore after testing?')) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        // Nothing leaves the server: run jobs in this process with log-only channels.
        config([
            'queue.default' => 'sync',
            'queue.long_connection' => 'sync',
            'mail.default' => 'log',
            'services.sms.providers' => ['log'],
            'services.whatsapp.driver' => 'log',
            'services.fcm.driver' => 'log',
        ]);

        DemoSeeder::$allowInProduction = true;
        DemoSeeder::$includeStaff = ! app()->isProduction();
        try {
            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        } finally {
            DemoSeeder::$allowInProduction = false;
            DemoSeeder::$includeStaff = true;
        }

        Cache::forget(HomeController::CACHE_KEY);
        Cache::forget('catalogue:tree');

        $members = User::query()->whereIn('email', DemoSeeder::EMAILS)->whereIn('role', ['user', 'aviator'])->pluck('email');
        $this->info('Demo data seeded. Sign in as any of these with password "Password1":');
        foreach ($members as $email) {
            $this->line("  {$email}");
        }

        return self::SUCCESS;
    }

    private function cleanup(MediaStorage $storage): int
    {
        $owners = User::query()->whereIn('email', DemoSeeder::EMAILS)->pluck('id');
        $media = Media::query()->whereIn('owner_id', $owners)->get();
        if (! $this->option('force') && ! $this->confirm("Delete {$media->count()} demo upload(s) from media storage?", true)) {
            return self::FAILURE;
        }

        $failed = 0;
        foreach ($media as $item) {
            try {
                $storage->delete($item);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$item->public_id}: {$e->getMessage()}");
            }
        }

        $this->info('Deleted '.($media->count() - $failed).' demo upload(s) from media storage.');
        $this->line('Now restore the database backup to remove the demo rows (docs/deployment-vps.md §17).');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Action must be "seed" or "cleanup".');

        return self::INVALID;
    }
}
