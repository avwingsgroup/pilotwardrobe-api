<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

use App\Integrations\ProviderException;

interface WhatsAppProvider
{
    /**
     * Sends a pre-approved template message (business-initiated messages must use templates).
     *
     * @param  list<string>  $bodyParameters  values for {{1}}, {{2}}, … in the template body
     * @return string provider message id (wamid)
     *
     * @throws ProviderException
     */
    public function sendTemplate(string $to, string $template, string $language, array $bodyParameters = []): string;
}
