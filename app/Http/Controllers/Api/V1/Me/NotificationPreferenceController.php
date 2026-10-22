<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Me\NotificationPreferencesRequest;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Email/push preference matrix (VB11). Transactional email cannot be switched off. */
final class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->matrix($request->user()));
    }

    public function update(NotificationPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($request, $user) {
            foreach ($request->validated('preferences') as $row) {
                $category = NotificationCategory::from($row['category']);
                $values = array_filter([
                    'email' => $category->emailAlwaysOn() ? true : ($row['email'] ?? null),
                    'push' => $row['push'] ?? null,
                    'sms' => $row['sms'] ?? null,
                    'whatsapp' => $row['whatsapp'] ?? null,
                ], fn ($v) => $v !== null);

                NotificationPreference::query()->updateOrCreate(['user_id' => $user->id, 'category' => $category->value], $values);
            }
        });

        return ApiResponse::ok($this->matrix($user), 'Preferences saved.');
    }

    private function matrix(User $user): array
    {
        return array_map(function (NotificationCategory $category) use ($user) {
            $pref = $this->notifications->preference($user, $category);

            return [
                'category' => $category->value,
                'label' => $category->label(),
                'email' => $category->emailAlwaysOn() ? true : $pref['email'],
                'push' => $pref['push'],
                'sms' => $pref['sms'],
                'whatsapp' => $pref['whatsapp'],
                'email_locked' => $category->emailAlwaysOn(),
            ];
        }, NotificationCategory::configurable());
    }
}
