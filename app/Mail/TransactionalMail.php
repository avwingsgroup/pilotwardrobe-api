<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Every Pilotwardrobe email renders through one responsive layout with an HTML and a
 * plain-text part (§3). Content comes pre-rendered from the notification template.
 * Sent synchronously from inside the queued DeliverNotification job.
 */
final class TransactionalMail extends Mailable
{
    /** @param array{subject: string, heading: string, lines: list<string>, cta: ?array{label: string, url: string}} $content */
    public function __construct(
        public readonly array $content,
        public readonly bool $unsubscribable = false,
        public readonly string $deliveryId = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->content['subject']);
    }

    public function headers(): Headers
    {
        return new Headers(text: array_filter(['X-PW-Delivery' => $this->deliveryId]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.transactional',
            text: 'mail.transactional-text',
            with: [
                'heading' => $this->content['heading'],
                'lines' => $this->content['lines'],
                'cta' => $this->content['cta'],
                'unsubscribeUrl' => $this->unsubscribable ? config('platform.frontend_url').'/hangar/settings/notifications' : null,
                'frontendUrl' => config('platform.frontend_url'),
            ],
        );
    }
}
