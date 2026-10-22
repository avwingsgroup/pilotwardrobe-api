<?php

declare(strict_types=1);

namespace App\Services\Trading;

use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Exceptions\ApiException;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\OfferEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\ListingService;
use App\Services\Messaging\ConversationService;
use App\Services\Notifications\NotificationService;
use App\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Price offers (handoff §8.2). Seller answers buyer offers (accept/decline/counter);
 * a counter creates a child offer from seller to buyer, which the buyer accepts or declines.
 * Accepting reserves the listing for the buyer for 24 h to check out at the agreed price.
 */
final class OfferService
{
    public function __construct(
        private readonly ListingService $listings,
        private readonly ConversationService $conversations,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    public function make(User $buyer, Listing $listing, int $amount, string $currency, ?string $message, ?string $size): Offer
    {
        if (! $buyer->canTrade()) {
            throw ApiException::forbidden('Trading is restricted on your account. Contact support.');
        }
        if ($listing->seller_id === $buyer->id) {
            throw ApiException::conflict("You can't make an offer on your own listing.");
        }
        if ($listing->status !== ListingStatus::Active) {
            throw ApiException::conflict('This listing is not accepting offers right now.');
        }
        if (! $listing->negotiable) {
            throw ApiException::conflict("This seller isn't accepting offers on this item.");
        }
        if ($currency !== $listing->currency) {
            throw ApiException::validation(['currency' => "Offers on this item must be in {$listing->currency}."]);
        }
        if ($amount <= 0) {
            throw ApiException::validation(['amount' => 'The amount must be greater than zero.']);
        }
        if ($amount >= (int) $listing->price_amount) {
            throw ApiException::validation(['amount' => 'Your offer must be below the asking price — or just buy it now.']);
        }
        if ($listing->minimum_offer_amount !== null && $amount < $listing->minimum_offer_amount) {
            throw ApiException::validation(['amount' => "The seller doesn't accept offers below ".$listing->minimumOffer()->format().'.']);
        }

        try {
            $offer = DB::transaction(function () use ($buyer, $listing, $amount, $currency, $message, $size) {
                $offer = Offer::query()->create([
                    'listing_id' => $listing->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $listing->seller_id,
                    'proposer_id' => $buyer->id,
                    'recipient_id' => $listing->seller_id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'size' => $size,
                    'message' => $message ? mb_substr(trim($message), 0, 500) : null,
                    'status' => OfferStatus::Pending,
                    'expires_at' => now()->addHours((int) config('platform.offers.expiry_hours')),
                ]);
                $listing->increment('offers_count');
                $this->event($offer, 'offer', 'Offer sent · '.Money::of($amount, $currency)->format(), $buyer->shortName(), $buyer);
                $this->conversations->systemMessage($listing, $buyer, 'offer', 'Offer: '.Money::of($amount, $currency)->format().' for '.$listing->title, ['offer_id' => $offer->id]);

                return $offer;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('You already have a pending offer on this item. Cancel it to make a new one.');
        }

        $this->notifications->notify($listing->seller, 'offer.received', $this->data($offer, $buyer), "offer.received:{$offer->id}");

        return $offer;
    }

    public function accept(Offer $offer, User $actor): Offer
    {
        $offer = DB::transaction(function () use ($offer, $actor) {
            $offer = $this->lockForRecipient($offer, $actor);
            $listing = Listing::query()->lockForUpdate()->findOrFail($offer->listing_id);
            if ($listing->status !== ListingStatus::Active || $listing->availableQuantity() < 1) {
                throw ApiException::invalidTransition('This listing is no longer available.');
            }

            $offer->forceFill([
                'status' => OfferStatus::Accepted,
                'responded_at' => now(),
                // The buyer now has 24 h to check out at the agreed price.
                'expires_at' => now()->addHours((int) config('platform.offers.expiry_hours')),
            ])->save();

            $this->listings->reserveFor($listing, $listing->seller, $offer->buyer, (int) config('platform.offers.expiry_hours'), 'offer');
            $this->event($offer, 'accepted', 'Offer accepted · '.$offer->money()->format(), 'Reserved for 24h', $actor);
            $this->audit->log('OFFER.ACCEPTED', $offer, $offer->money()->format(), actor: $actor);

            return $offer;
        });

        $this->notifications->notify($offer->buyer, 'offer.accepted', $this->data($offer, $offer->seller), "offer.accepted:{$offer->id}");

        return $offer;
    }

    public function decline(Offer $offer, User $actor, ?string $reason = null): Offer
    {
        $offer = DB::transaction(function () use ($offer, $actor, $reason) {
            $offer = $this->lockForRecipient($offer, $actor);
            $offer->forceFill(['status' => OfferStatus::Declined, 'responded_at' => now()])->save();
            $this->event($offer, 'declined', 'Offer declined', $reason, $actor);

            return $offer;
        });

        $notify = $offer->proposer_id === $offer->buyer_id ? $offer->buyer : $offer->seller;
        $this->notifications->notify($notify, 'offer.declined', $this->data($offer, $actor), "offer.declined:{$offer->id}");

        return $offer;
    }

    /** Seller counter: strictly between the buyer's offer and the asking price. */
    public function counter(Offer $offer, User $seller, int $amount, ?string $message): Offer
    {
        [$original, $child] = DB::transaction(function () use ($offer, $seller, $amount, $message) {
            $offer = $this->lockForRecipient($offer, $seller);
            if ($offer->seller_id !== $seller->id) {
                throw ApiException::forbidden('Only the seller can counter an offer.');
            }
            $listing = $offer->listing;
            if ($amount <= $offer->amount || $amount >= (int) $listing->price_amount) {
                throw ApiException::validation(['amount' => 'A counter must be between '.$offer->money()->format().' and '.$listing->price()->format().'.']);
            }

            $offer->forceFill(['status' => OfferStatus::Countered, 'counter_amount' => $amount, 'responded_at' => now()])->save();
            $child = Offer::query()->create([
                'listing_id' => $offer->listing_id,
                'buyer_id' => $offer->buyer_id,
                'seller_id' => $offer->seller_id,
                'proposer_id' => $seller->id,
                'recipient_id' => $offer->buyer_id,
                'parent_offer_id' => $offer->id,
                'amount' => $amount,
                'currency' => $offer->currency,
                'size' => $offer->size,
                'message' => $message ? mb_substr(trim($message), 0, 500) : null,
                'status' => OfferStatus::Pending,
                'expires_at' => now()->addHours((int) config('platform.offers.expiry_hours')),
            ]);
            $label = 'Counter sent · '.Money::of($amount, $offer->currency)->format();
            $this->event($offer, 'counter', $label, 'Buyer has 24h to respond', $seller);
            $this->event($child, 'counter', $label, 'Buyer has 24h to respond', $seller);

            return [$offer, $child];
        });

        $this->notifications->notify($child->buyer, 'offer.countered', $this->data($child, $seller), "offer.countered:{$child->id}");

        return $original->fresh();
    }

    /** Proposer withdraws a pending offer. */
    public function cancel(Offer $offer, User $actor): Offer
    {
        return DB::transaction(function () use ($offer, $actor) {
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            if ($offer->proposer_id !== $actor->id) {
                throw ApiException::forbidden('Only the person who made the offer can withdraw it.');
            }
            $offer->status->assertCanTransitionTo(OfferStatus::Cancelled, 'This offer can no longer be withdrawn.');
            if ($offer->status !== OfferStatus::Pending) {
                throw ApiException::invalidTransition('This offer can no longer be withdrawn.');
            }
            $offer->forceFill(['status' => OfferStatus::Cancelled, 'responded_at' => now()])->save();
            $this->event($offer, 'cancelled', 'Offer withdrawn', null, $actor);

            return $offer;
        });
    }

    /** Scheduler: pending offers past 24 h, and accepted offers never checked out. */
    public function expire(Offer $offer): void
    {
        $expired = DB::transaction(function () use ($offer) {
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            if (! in_array($offer->status, [OfferStatus::Pending, OfferStatus::Accepted], true) || $offer->expires_at->isFuture()) {
                return null;
            }
            $wasAccepted = $offer->status === OfferStatus::Accepted;
            $offer->forceFill(['status' => OfferStatus::Expired])->save();
            $this->event($offer, 'expired', 'Offer expired');

            if ($wasAccepted) {
                $listing = Listing::query()->lockForUpdate()->find($offer->listing_id);
                if ($listing && $listing->reserved_reason === 'offer' && $listing->reserved_for_user_id === $offer->buyer_id && $listing->reserved_quantity === 0) {
                    $this->listings->releaseReservation($listing);
                }
            }

            return $offer;
        });

        if ($expired) {
            foreach ([$expired->buyer, $expired->seller] as $party) {
                $this->notifications->notify($party, 'offer.expired', $this->data($expired, $party), "offer.expired:{$expired->id}:{$party->id}");
            }
        }
    }

    /** Buttons for the viewer (rendered verbatim by the SPA). */
    public static function allowedActions(Offer $offer, User $viewer): array
    {
        $actions = [];
        if ($offer->status === OfferStatus::Pending) {
            if ($viewer->id === $offer->recipient_id) {
                $actions = $viewer->id === $offer->seller_id ? ['accept', 'decline', 'counter'] : ['accept', 'decline'];
            } elseif ($viewer->id === $offer->proposer_id) {
                $actions = ['cancel'];
            }
        }
        if ($offer->status === OfferStatus::Accepted && $viewer->id === $offer->buyer_id) {
            $actions[] = 'checkout';
        }
        $actions[] = 'message';

        return $actions;
    }

    private function lockForRecipient(Offer $offer, User $actor): Offer
    {
        $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
        if (! $offer->isParty($actor)) {
            throw ApiException::notFound('Offer not found.');
        }
        if ($offer->recipient_id !== $actor->id) {
            throw ApiException::forbidden('Only the person receiving the offer can respond to it.');
        }
        if ($offer->status !== OfferStatus::Pending) {
            throw ApiException::invalidTransition('This offer is no longer pending.');
        }
        if ($offer->expires_at->isPast()) {
            throw ApiException::invalidTransition('This offer has expired.');
        }

        return $offer;
    }

    private function event(Offer $offer, string $kind, string $label, ?string $detail = null, ?User $actor = null): void
    {
        OfferEvent::query()->create([
            'offer_id' => $offer->id,
            'kind' => $kind,
            'label' => $label,
            'detail' => $detail,
            'actor_id' => $actor?->id,
            'occurred_at' => now(),
        ]);
    }

    private function data(Offer $offer, User $counterparty): array
    {
        return [
            'offer_id' => $offer->id,
            'listing_id' => $offer->listing_id,
            'title' => $offer->listing->title,
            'slug' => $offer->listing->slug,
            'amount' => $offer->money()->format(),
            'counterparty' => $counterparty->shortName(),
        ];
    }
}
