<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Local development driver: writes the message to the log instead of sending it. */
final class LogSmsProvider implements SmsProvider
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $to, string $message): string
    {
        // Codes are readable locally so developers can sign in; never outside local/testing.
        $readable = app()->environment('local', 'testing');
        Log::info('sms.log_driver', ['to' => Str::mask($to, '*', 0, -4), 'message' => $readable ? $message : preg_replace('/\d{4,}/', '****', $message)]);

        return 'log_'.Str::random(12);
    }
}
