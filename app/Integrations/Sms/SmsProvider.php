<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Integrations\ProviderException;

interface SmsProvider
{
    public function name(): string;

    /**
     * @param  string  $to  E.164 phone number
     * @return string provider message id
     *
     * @throws ProviderException
     */
    public function send(string $to, string $message): string;
}
