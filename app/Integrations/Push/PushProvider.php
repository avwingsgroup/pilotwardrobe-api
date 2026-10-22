<?php

declare(strict_types=1);

namespace App\Integrations\Push;

use App\Integrations\ProviderException;

interface PushProvider
{
    /**
     * @param  array<string, string>  $data  string-only payload (FCM requirement)
     * @return string provider message id
     *
     * @throws InvalidDeviceToken when the token is no longer registered (caller should invalidate it)
     * @throws ProviderException
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): string;
}
