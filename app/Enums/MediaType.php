<?php

declare(strict_types=1);

namespace App\Enums;

/** `type` on `POST /media` (handoff §6). */
enum MediaType: string
{
    case Listing = 'listing';
    case Avatar = 'avatar';
    case Cover = 'cover';
    case Kyc = 'kyc';
    case DisputeEvidence = 'dispute_evidence';
    case Message = 'message';
    case Content = 'content';

    /** Private assets use Cloudinary authenticated delivery with short-lived signed URLs. */
    public function isPrivate(): bool
    {
        return in_array($this, [self::Kyc, self::DisputeEvidence, self::Message], true);
    }

    public function allowsPdf(): bool
    {
        return $this->isPrivate();
    }

    public function folder(): string
    {
        return match ($this) {
            self::Listing => 'listings',
            self::Avatar, self::Cover => 'users',
            self::Kyc => 'verification',
            self::DisputeEvidence => 'disputes',
            self::Message => 'messages',
            self::Content => 'admin',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
