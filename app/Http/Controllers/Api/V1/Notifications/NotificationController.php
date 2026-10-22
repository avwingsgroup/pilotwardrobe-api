<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\Account\CountersService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Notification centre (VB11). */
final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()->where('user_id', $request->user()->id)
            ->when($request->query('category'), fn ($q) => $q->whereIn('category', QueryParams::list($request, 'category')))
            ->when(QueryParams::bool($request, 'unread') === true, fn ($q) => $q->whereNull('read_at'))
            ->latest()->orderByDesc('id');

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (Notification $n) => self::present($n));
    }

    /** `GET /notifications/unread-count` (polled every 60 s). */
    public function unreadCount(Request $request, CountersService $counters): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::ok([
            'count' => Notification::query()->where('user_id', $user->id)->whereNull('read_at')->count(),
            'messages' => $counters->unreadMessages($user->id),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = Notification::query()->where('user_id', $request->user()->id)->whereKey($id)->first()
            ?? throw ApiException::notFound('Notification not found.');
        $notification->read_at ??= now();
        $notification->save();

        return ApiResponse::ok(self::present($notification));
    }

    public function markAllRead(Request $request): Response
    {
        Notification::query()->where('user_id', $request->user()->id)->whereNull('read_at')
            ->when($request->input('category'), fn ($q, $c) => $q->where('category', $c))
            ->update(['read_at' => now()]);

        return ApiResponse::noContent();
    }

    public static function present(Notification $n): array
    {
        return array_filter([
            'id' => $n->id,
            'category' => $n->category,
            'icon' => $n->icon,
            'title' => $n->title,
            'body' => $n->body,
            'read_at' => $n->read_at?->toIso8601ZuluString(),
            'created_at' => $n->created_at?->toIso8601ZuluString(),
            'link' => $n->link,
            'actions' => $n->actions,
        ], fn ($v, $k) => $v !== null || in_array($k, ['read_at', 'body'], true), ARRAY_FILTER_USE_BOTH);
    }
}
