<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * One review per side per completed order. Tags come from a fixed list; both the display
 * labels ("As described") and slugs ("as_described") are accepted (the contract sample
 * sends slugs while the narrative says labels) and stored as display labels.
 */
final class ReviewService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @param array{rating: int, comment?: ?string, tags?: list<string>} $data */
    public function create(Order $order, User $author, array $data): Review
    {
        if (! $order->isParty($author)) {
            throw ApiException::notFound('Order not found.');
        }
        if (! in_array($order->status, [OrderStatus::Completed, OrderStatus::PartiallyRefunded], true)) {
            throw ApiException::invalidTransition('You can review an order once it is complete.');
        }

        $tags = $this->normaliseTags($data['tags'] ?? []);
        $role = $order->isBuyer($author) ? 'buyer' : 'seller';
        $subjectId = $role === 'buyer' ? $order->seller_id : $order->buyer_id;

        try {
            $review = DB::transaction(function () use ($order, $author, $data, $tags, $role, $subjectId) {
                $review = Review::query()->create([
                    'order_id' => $order->id,
                    'author_id' => $author->id,
                    'subject_id' => $subjectId,
                    'listing_id' => $role === 'buyer' ? $order->items()->value('listing_id') : null,
                    'role' => $role,
                    'rating' => (int) $data['rating'],
                    'comment' => isset($data['comment']) ? mb_substr(strip_tags(trim($data['comment'])), 0, 1000) : null,
                    'tags' => $tags,
                ]);
                $this->recompute($subjectId, $review->listing_id);

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('You have already reviewed this order.');
        }

        $this->notifications->notify(User::query()->findOrFail($subjectId), 'review.received', [
            'rating' => (string) $review->rating,
            'author' => $author->shortName(),
            'comment' => mb_substr((string) $review->comment, 0, 80),
        ], "review.received:{$review->id}");

        return $review;
    }

    /** Recomputes the subject's rating, count and breakdown (and the listing rating) from source rows. */
    public function recompute(string $subjectId, ?string $listingId): void
    {
        $rows = Review::query()->where('subject_id', $subjectId)->where('is_hidden', false)
            ->selectRaw('rating, count(*) as c')->groupBy('rating')->pluck('c', 'rating');
        $count = (int) $rows->sum();
        $sum = (int) $rows->map(fn ($c, $r) => $c * $r)->sum();
        $breakdown = [];
        foreach (range(1, 5) as $star) {
            $breakdown[(string) $star] = (int) ($rows[$star] ?? 0);
        }

        User::query()->whereKey($subjectId)->update([
            'reviews_count' => $count,
            'rating_avg' => $count > 0 ? round($sum / $count, 2) : 0,
            'rating_breakdown' => json_encode($breakdown),
        ]);

        if ($listingId) {
            $avg = Review::query()->where('listing_id', $listingId)->where('is_hidden', false)->avg('rating');
            Listing::query()->whereKey($listingId)->update(['rating_avg' => $avg !== null ? round((float) $avg, 2) : null]);
        }
    }

    /** @param list<string> $tags */
    private function normaliseTags(array $tags): array
    {
        $catalogue = config('platform.reviews.tags');
        $byLabel = array_change_key_case(array_flip($catalogue));
        $out = [];
        foreach ($tags as $i => $tag) {
            $key = isset($catalogue[$tag]) ? $tag : ($byLabel[mb_strtolower((string) $tag)] ?? null);
            if ($key === null) {
                throw ApiException::validation(["tags.{$i}" => 'Choose tags from the list.']);
            }
            $out[] = $catalogue[$key];
        }

        return array_values(array_unique($out));
    }
}
