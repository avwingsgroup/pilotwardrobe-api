<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Http\Resources\MediaAssetPresenter;
use App\Http\Resources\SellerSummaryResource;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Messages (VB10). Real-time transport is out of scope for v1; the SPA polls. */
final class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    /** `GET /conversations?context=buying|selling` */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $context = $request->query('context');

        $page = Conversation::query()->with(['listing.images', 'buyer.kyc', 'seller.kyc'])
            ->when($context === 'buying', fn ($q) => $q->where('buyer_id', $user->id))
            ->when($context === 'selling', fn ($q) => $q->where('seller_id', $user->id))
            ->when(! in_array($context, ['buying', 'selling'], true), fn ($q) => $q->forParticipant($user))
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')
            ->paginate(QueryParams::perPage($request, 30))->withQueryString();

        $unread = $this->unreadCounts(collect($page->items())->pluck('id')->all(), $user);

        return ApiResponse::paginated($page, fn (Conversation $c) => $this->present($c, $user, $unread[$c->id] ?? 0));
    }

    /** `GET /conversations/{id}` */
    public function show(Request $request, string $id): JsonResponse
    {
        $conversation = $this->find($request, $id);

        return ApiResponse::ok($this->present($conversation, $request->user(), $this->unreadCounts([$conversation->id], $request->user())[$conversation->id] ?? 0));
    }

    /** `GET /conversations/{id}/messages` — newest page last; `before=<message id>` pages back. */
    public function messages(Request $request, string $id): JsonResponse
    {
        $conversation = $this->find($request, $id);
        $viewer = $request->user();

        $page = Message::query()->where('conversation_id', $conversation->id)->with('attachments')
            ->orderBy('created_at')->orderBy('id')
            ->paginate(QueryParams::perPage($request, 100))->withQueryString();

        return ApiResponse::paginated($page, fn (Message $m) => self::presentMessage($m, $viewer));
    }

    /** `POST /conversations/{id}/messages` */
    public function send(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'attachment_ids' => ['nullable', 'array', 'max:5'],
            'attachment_ids.*' => ['string', 'max:40'],
        ], ['body.max' => 'Messages can be up to 4,000 characters.']);

        $message = $this->conversations->send($this->find($request, $id), $request->user(), (string) ($data['body'] ?? ''), $data['attachment_ids'] ?? []);

        return ApiResponse::created(self::presentMessage($message->load('attachments'), $request->user()));
    }

    /** `POST /conversations` — start or fetch the thread about a listing. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['listing_id' => ['required', 'string', 'max:40'], 'body' => ['nullable', 'string', 'max:4000']]);
        $listing = Listing::query()->publiclyVisible()->whereKey($data['listing_id'])->first() ?? throw ApiException::notFound('Listing not found.');

        $conversation = $this->conversations->open($request->user(), $listing);
        if (! empty($data['body'])) {
            $this->conversations->send($conversation, $request->user(), $data['body']);
        }

        return ApiResponse::created($this->present($conversation->fresh(['listing.images', 'buyer.kyc', 'seller.kyc']), $request->user(), 0));
    }

    /** `POST /conversations/{id}/read` */
    public function read(Request $request, string $id): Response
    {
        $this->conversations->markRead($this->find($request, $id), $request->user());

        return ApiResponse::noContent();
    }

    /** `POST /conversations/{id}/report` — creates an AD10 report. */
    public function report(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(['off_platform_payment', 'scam', 'harassment', 'spam', 'counterfeit', 'other'])],
            'detail' => ['nullable', 'string', 'max:1000'],
            'message_id' => ['nullable', 'string', 'max:40'],
        ]);
        $conversation = $this->find($request, $id);

        $report = Report::query()->create([
            'reporter_id' => $request->user()->id,
            'target_type' => 'conversation',
            'target_id' => $conversation->id,
            'reason' => $data['reason'],
            'detail' => $data['detail'] ?? null,
            'severity' => in_array($data['reason'], ['scam', 'off_platform_payment', 'counterfeit'], true) ? 'high' : 'medium',
            'source' => 'user_report',
        ]);

        return ApiResponse::ok(['report_id' => $report->id], 'Thanks — our Trust & Safety team will review this conversation.');
    }

    public static function presentMessage(Message $m, User $viewer): array
    {
        return array_filter([
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'sender_id' => $m->sender_id,
            'mine' => $m->sender_id === $viewer->id,
            'kind' => $m->kind,
            'body' => $m->body,
            'context' => $m->context,
            'flags' => $m->flags,
            'attachments' => $m->attachments->isNotEmpty() ? MediaAssetPresenter::many($m->attachments) : null,
            'sent_at' => $m->created_at?->toIso8601ZuluString(),
            'read_at' => $m->read_at?->toIso8601ZuluString(),
        ], fn ($v) => $v !== null);
    }

    private function present(Conversation $c, User $viewer, int $unread): array
    {
        $other = $viewer->id === $c->buyer_id ? $c->seller : $c->buyer;
        $listing = $c->listing;

        return [
            'id' => $c->id,
            'context' => $viewer->id === $c->buyer_id ? 'buying' : 'selling',
            'participant' => SellerSummaryResource::counterparty($other) + [
                'response_time' => $other->response_time,
                'kyc_verified' => $other->isKycApproved(),
            ],
            'listing' => $listing ? ListingResource::snippet($listing) + [
                'condition_label' => $listing->condition?->label(),
                'offers_count' => (int) $listing->offers_count,
                'reserved' => $listing->status->value === 'reserved',
            ] : null,
            'last_message_preview' => $c->last_message_preview,
            'last_message_at' => $c->last_message_at?->toIso8601ZuluString(),
            'unread_count' => $unread,
            'flagged' => (bool) $c->is_flagged,
        ];
    }

    /** @return array<string, int> */
    private function unreadCounts(array $ids, User $viewer): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('messages as m')->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereIn('c.id', $ids)
            ->where(fn ($q) => $q->whereNull('m.sender_id')->orWhere('m.sender_id', '!=', $viewer->id))
            ->whereRaw("m.created_at > coalesce(case when c.buyer_id = ? then c.buyer_last_read_at else c.seller_last_read_at end, 'epoch'::timestamp)", [$viewer->id])
            ->groupBy('c.id')->selectRaw('c.id, count(*) as n')->pluck('n', 'c.id')->map(fn ($n) => (int) $n)->all();
    }

    private function find(Request $request, string $id): Conversation
    {
        $conversation = Conversation::query()->with(['listing.images', 'buyer.kyc', 'seller.kyc'])->whereKey($id)->first();
        if ($conversation === null || ! $conversation->isParticipant($request->user())) {
            throw ApiException::notFound('Conversation not found.');
        }

        return $conversation;
    }
}
