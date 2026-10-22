<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class LogWhatsAppProvider implements WhatsAppProvider
{
    public function sendTemplate(string $to, string $template, string $language, array $bodyParameters = []): string
    {
        Log::info('whatsapp.log_driver', ['to' => Str::mask($to, '*', 0, -4), 'template' => $template, 'params' => $bodyParameters]);

        return 'wamid.log_'.Str::random(12);
    }
}
