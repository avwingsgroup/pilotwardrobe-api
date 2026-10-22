<?php

declare(strict_types=1);

namespace App\Integrations\Push;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class LogPushProvider implements PushProvider
{
    public function send(string $deviceToken, string $title, string $body, array $data = []): string
    {
        Log::info('push.log_driver', ['token' => Str::limit($deviceToken, 12), 'title' => $title]);

        return 'projects/log/messages/'.Str::random(12);
    }
}
