<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\MediaType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Catalogue\HomeController;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\HeroSlideResource;
use App\Jobs\Admin\SendBroadcast;
use App\Models\Broadcast;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HeroSlide;
use App\Models\Media;
use App\Models\Page;
use App\Models\PromoCode;
use App\Services\Admin\BroadcastService;
use App\Services\Audit\AuditLogger;
use App\Services\Content\HtmlSanitizer;
use App\Services\Notifications\NotificationService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use App\Support\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Promotions (AD13), broadcasts (AD14), content (AD15) and catalogue (AD16). */
final class AdminGrowthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    // ---------------------------------------------------------------- promotions

    public function campaigns(Request $request): JsonResponse
    {
        $query = Campaign::query()->withCount('promoCodes')->latest();

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (Campaign $c) => [
            'id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'status' => $this->campaignStatus($c), 'audience' => $c->audience,
            'starts_at' => $c->starts_at?->toIso8601ZuluString(), 'ends_at' => $c->ends_at?->toIso8601ZuluString(),
            'promo_codes' => $c->promo_codes_count, 'rules' => $c->rules,
            'redemptions' => (int) PromoCode::query()->where('campaign_id', $c->id)->sum('times_used'),
        ]);
    }

    public function duplicateCampaign(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::query()->find($id) ?? throw ApiException::notFound('Campaign not found.');
        $copy = $campaign->replicate(['status']);
        $copy->name = Str::limit($campaign->name, 110, '').' (copy)';
        $copy->status = 'draft';
        $copy->created_by = $request->user()->id;
        $copy->save();
        $this->audit->log('CAMPAIGN.DUPLICATED', $copy, $campaign->name);

        return ApiResponse::created(['id' => $copy->id, 'name' => $copy->name, 'status' => $copy->status]);
    }

    public function promoCodes(Request $request): JsonResponse
    {
        $query = PromoCode::query()->latest()
            ->when(QueryParams::search($request), fn ($q, $s) => $q->where('code', 'ilike', '%'.addcslashes($s, '%_\\').'%'));

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (PromoCode $p) => $this->presentPromo($p));
    }

    public function createPromoCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9\-_]+$/', 'unique_ci:promo_codes,code'],
            'campaign_id' => ['nullable', 'string', 'max:40', 'exists:campaigns,id'],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required_if:discount_type,fixed', 'nullable', Rule::in(Currency::codes())],
            'min_order_amount' => ['nullable', 'integer', 'min:0'],
            'max_discount_amount' => ['nullable', 'integer', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ], ['code.unique_ci' => 'That code already exists.']);

        if ($data['discount_type'] === 'percent' && $data['discount_value'] > 90) {
            throw ApiException::validation(['discount_value' => 'Percentage discounts can be at most 90%.']);
        }

        $promo = PromoCode::query()->create([
            ...array_intersect_key($data, array_flip(['campaign_id', 'discount_type', 'currency', 'min_order_amount', 'max_discount_amount', 'usage_limit', 'starts_at', 'ends_at'])),
            'code' => strtoupper($data['code']),
            // Percent is stored in basis points; fixed amounts are minor units.
            'discount_value' => $data['discount_type'] === 'percent' ? (int) round($data['discount_value'] * 100) : (int) $data['discount_value'],
            'per_user_limit' => $data['per_user_limit'] ?? 1,
            'created_by' => $request->user()->id,
        ]);
        $this->audit->log('PROMO_CODE.CREATED', $promo, $promo->code.' · '.$data['discount_value'].($data['discount_type'] === 'percent' ? '%' : ' '.$promo->currency), 'warning');

        return ApiResponse::created($this->presentPromo($promo));
    }

    // ---------------------------------------------------------------- broadcasts

    public function reach(Request $request, BroadcastService $broadcasts): JsonResponse
    {
        $data = $this->broadcastInput($request, onlyAudience: true);

        return ApiResponse::ok($broadcasts->reach($data['audience'] ?? [], $data['channels']));
    }

    public function sendBroadcast(Request $request, BroadcastService $broadcasts): JsonResponse
    {
        $data = $this->broadcastInput($request);
        $broadcast = Broadcast::query()->create([
            'title' => strip_tags($data['title']),
            'body' => strip_tags($data['body']),
            'channels' => $data['channels'],
            'audience' => $data['audience'] ?? [],
            'link' => $data['link'] ?? null,
            'status' => 'scheduled',
            'scheduled_at' => $data['scheduled_at'] ?? now(),
            'recipients_count' => $broadcasts->reach($data['audience'] ?? [], $data['channels'])['total'],
            'created_by' => $request->user()->id,
        ]);
        SendBroadcast::dispatch($broadcast->id)->delay($broadcast->scheduled_at);
        $this->audit->log('BROADCAST.SCHEDULED', $broadcast, $broadcast->title.' → '.$broadcast->recipients_count.' recipients', 'warning');

        return ApiResponse::created($broadcasts->present($broadcast), 'Broadcast scheduled.');
    }

    public function testBroadcast(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $this->broadcastInput($request);
        $notifications->notify($request->user(), 'marketing.broadcast', [
            'title' => '[TEST] '.$data['title'], 'body' => $data['body'], 'link' => $data['link'] ?? '/',
        ], 'broadcast.test:'.$request->user()->id.':'.now()->timestamp);

        return ApiResponse::ok(['sent_to' => $request->user()->email], 'Test sent to you.');
    }

    // ---------------------------------------------------------------- content

    public function heroSlides(Request $request): JsonResponse
    {
        return ApiResponse::ok(HeroSlide::query()->orderBy('position')->get()->map(fn (HeroSlide $s) => (new HeroSlideResource($s))->withDraft()->resolve($request))->values());
    }

    /** `PATCH /admin/content/hero-slides/{id}` — edits are saved as a draft until published. */
    public function updateHeroSlide(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'headline' => ['sometimes', 'string', 'max:120'],
            'subtext' => ['sometimes', 'nullable', 'string', 'max:400'],
            'button_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'link' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^\/(?!\/)/'],
            'audience' => ['sometimes', 'nullable', 'string', 'max:120'],
            'locales' => ['sometimes', 'nullable', 'string', 'max:60'],
            'schedule' => ['sometimes', 'nullable', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(['live', 'scheduled', 'draft'])],
            'image_media_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'active' => ['sometimes', 'boolean'],
        ], ['link.regex' => 'Links must be site paths like /marketplace.']);
        $slide = HeroSlide::query()->find($id) ?? throw ApiException::notFound('Slide not found.');

        if (! empty($data['image_media_id']) && ! Media::query()->whereKey($data['image_media_id'])->whereIn('type', [MediaType::Content->value, MediaType::Listing->value])->exists()) {
            throw ApiException::validation(['image_media_id' => 'Upload the image first.']);
        }

        $active = $data['active'] ?? null;
        unset($data['active']);
        $slide->forceFill(['draft' => array_merge($slide->draft ?? [], $data)] + ($active !== null ? ['active' => $active] : []))->save();
        $this->audit->log('CONTENT.SLIDE_EDITED', $slide, implode(', ', array_keys($data)));

        return ApiResponse::ok((new HeroSlideResource($slide))->withDraft()->resolve($request), 'Slide saved as draft.');
    }

    public function reorderHeroSlides(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['string', 'max:40']]);
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $i => $id) {
                HeroSlide::query()->whereKey($id)->update(['position' => $i + 1]);
            }
        });
        Cache::forget(HomeController::CACHE_KEY);
        $this->audit->log('CONTENT.SLIDES_REORDERED', 'hero_slides', implode(', ', $data['ids']));

        return $this->heroSlides($request);
    }

    /** `POST /admin/content/publish` — promotes drafts to live and purges the home cache. */
    public function publish(Request $request): JsonResponse
    {
        $published = DB::transaction(function () {
            $count = 0;
            foreach (HeroSlide::query()->whereNotNull('draft')->get() as $slide) {
                $slide->forceFill(['published' => array_merge($slide->published ?? [], $slide->draft ?? []), 'draft' => null])->save();
                $count++;
            }

            return $count;
        });
        Cache::forget(HomeController::CACHE_KEY);
        $this->audit->log('CONTENT.PUBLISHED', 'content', "{$published} slide(s)", 'success');

        return ApiResponse::ok(['published' => $published], 'Content published.');
    }

    /** `PUT /admin/content/pages/{slug}` (addition) — CMS pages; HTML is sanitised on write. */
    public function savePage(Request $request, string $slug, HtmlSanitizer $sanitizer): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:500'],
            'body_html' => ['required', 'string', 'max:200000'],
            'kind' => ['nullable', Rule::in(['page', 'help'])],
            'status' => ['nullable', Rule::in(['draft', 'published'])],
        ]);
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw ApiException::validation(['slug' => 'Use lowercase letters, numbers and hyphens.']);
        }

        $page = Page::query()->updateOrCreate(['slug' => $slug], [
            'title' => strip_tags($data['title']),
            'summary' => isset($data['summary']) ? strip_tags($data['summary']) : null,
            'body_html' => $sanitizer->clean($data['body_html']),
            'kind' => $data['kind'] ?? 'page',
            'status' => $data['status'] ?? 'published',
            'published_at' => ($data['status'] ?? 'published') === 'published' ? now() : null,
        ]);
        $this->audit->log('CONTENT.PAGE_SAVED', $page, $slug);

        return ApiResponse::ok(['slug' => $page->slug, 'title' => $page->title, 'status' => $page->status, 'body_html' => $page->body_html]);
    }

    // ---------------------------------------------------------------- catalogue (read-only per design)

    public function catalogue(Request $request): JsonResponse
    {
        return ApiResponse::ok(CategoryResource::collection(
            Category::query()->whereNull('parent_id')->with(['image', 'children'])->orderBy('position')->get()
        )->resolve($request));
    }

    public function category(Request $request, string $id): JsonResponse
    {
        $category = Category::query()->with(['image', 'children', 'parent'])->where(fn ($q) => $q->whereKey($id)->orWhere('slug', $id))->first()
            ?? throw ApiException::notFound('Category not found.');

        return ApiResponse::ok((new CategoryResource($category))->detailed()->resolve($request) + [
            'fit_kit' => $category->size_chart['fit_kit'] ?? null,
            'is_active' => $category->is_active,
        ]);
    }

    // ----------------------------------------------------------------

    private function broadcastInput(Request $request, bool $onlyAudience = false): array
    {
        return $request->validate([
            'title' => [$onlyAudience ? 'nullable' : 'required', 'string', 'max:120'],
            'body' => [$onlyAudience ? 'nullable' : 'required', 'string', 'max:1000'],
            'link' => ['nullable', 'string', 'max:255', 'regex:/^\/(?!\/)/'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['email', 'push', 'in_app'])],
            'audience' => ['nullable', 'array'],
            'audience.roles' => ['nullable', 'array'],
            'audience.roles.*' => [Rule::in(['user', 'aviator'])],
            'audience.countries' => ['nullable', 'array'],
            'audience.kyc' => ['nullable', Rule::in(['any', 'verified', 'unverified'])],
            'audience.sellers_only' => ['nullable', 'boolean'],
            'audience.buyers_only' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
        ]);
    }

    private function campaignStatus(Campaign $c): string
    {
        return match (true) {
            $c->status === 'draft' => 'draft',
            $c->ends_at !== null && $c->ends_at->isPast() => 'ended',
            $c->starts_at !== null && $c->starts_at->isFuture() => 'scheduled',
            default => 'live',
        };
    }

    private function presentPromo(PromoCode $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'campaign_id' => $p->campaign_id,
            'discount_type' => $p->discount_type,
            'discount_value' => $p->discount_type === 'percent' ? $p->discount_value / 100 : $p->discount_value,
            'currency' => $p->currency,
            'usage_limit' => $p->usage_limit,
            'per_user_limit' => $p->per_user_limit,
            'times_used' => $p->times_used,
            'starts_at' => $p->starts_at?->toIso8601ZuluString(),
            'ends_at' => $p->ends_at?->toIso8601ZuluString(),
            'active' => (bool) $p->is_active,
        ];
    }
}
