<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Zoho ZeptoMail transactional email (POST https://api.zeptomail.com/v1.1/email).
 * Registered as the `zeptomail` mail transport; mail is always sent from queued jobs.
 */
final class ZeptoMailTransport extends AbstractTransport
{
    private readonly string $token;

    public function __construct(
        string $token,
        private readonly string $endpoint = 'https://api.zeptomail.com/v1.1/email',
        private readonly ?string $bounceAddress = null,
    ) {
        parent::__construct();
        // Accept the key with or without the "Zoho-enczapikey " prefix shown in the ZeptoMail console.
        $this->token = trim((string) preg_replace('/^\s*Zoho-enczapikey\s+/i', '', $token));
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->token === '') {
            throw new TransportException('ZEPTOMAIL_TOKEN is not configured.');
        }

        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            throw new TransportException('ZeptoMail can only send MIME messages.');
        }
        $email = MessageConverter::toEmail($original);
        $from = $email->getFrom()[0] ?? null;
        if ($from === null) {
            throw new TransportException('A from address is required.');
        }

        $payload = array_filter([
            'from' => $this->address($from),
            'to' => array_map(fn (Address $a) => ['email_address' => $this->address($a)], $email->getTo()),
            'cc' => array_map(fn (Address $a) => ['email_address' => $this->address($a)], $email->getCc()) ?: null,
            'bcc' => array_map(fn (Address $a) => ['email_address' => $this->address($a)], $email->getBcc()) ?: null,
            'reply_to' => array_map(fn (Address $a) => $this->address($a), $email->getReplyTo()) ?: null,
            'subject' => (string) $email->getSubject(),
            'htmlbody' => $email->getHtmlBody() ? (string) $email->getHtmlBody() : null,
            'textbody' => $email->getTextBody() ? (string) $email->getTextBody() : null,
            'bounce_address' => $this->bounceAddress,
            'track_clicks' => false,
            'track_opens' => false,
            'client_reference' => $email->getHeaders()->get('X-PW-Delivery')?->getBodyAsString(),
        ], fn ($v) => $v !== null);

        $response = Http::timeout(15)->connectTimeout(5)->acceptJson()
            ->withHeaders(['Authorization' => 'Zoho-enczapikey '.$this->token])
            ->post($this->endpoint, $payload);

        if ($response->failed()) {
            throw new TransportException(sprintf('ZeptoMail rejected the message (HTTP %d): %s', $response->status(), mb_substr($response->body(), 0, 300)));
        }

        $requestId = $response->json('request_id');
        if ($requestId) {
            $message->setMessageId((string) $requestId);
        }
    }

    /** @return array{address: string, name?: string} */
    private function address(Address $address): array
    {
        return array_filter(['address' => $address->getAddress(), 'name' => $address->getName() ?: null]);
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }
}
