<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Me\DeviceTokenRequest;
use App\Models\DeviceToken;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Firebase Cloud Messaging device registrations (addition to the contract — the apps
 * need it for push). Registering an existing token moves it to the current user
 * (token refresh / account switch on the same device).
 */
final class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = DeviceToken::query()->where('user_id', $request->user()->id)->whereNull('invalidated_at')
            ->latest('updated_at')->get()
            ->map(fn (DeviceToken $d) => [
                'id' => $d->id,
                'platform' => $d->platform,
                'device_name' => $d->device_name,
                'app_version' => $d->app_version,
                'last_used_at' => $d->last_used_at?->toIso8601ZuluString(),
            ]);

        return ApiResponse::ok($devices);
    }

    public function store(DeviceTokenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $device = DeviceToken::query()->whereRaw('md5(token) = md5(?)', [$data['token']])->first() ?? new DeviceToken;
        $device->fill([
            'user_id' => $request->user()->id,
            'token' => $data['token'],
            'platform' => $data['platform'],
            'device_name' => $data['device_name'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'last_used_at' => now(),
            'invalidated_at' => null,
        ])->save();

        return ApiResponse::created(['id' => $device->id], 'Device registered.');
    }

    public function destroy(Request $request, string $id): Response
    {
        $device = DeviceToken::query()->where('user_id', $request->user()->id)->whereKey($id)->first();
        if ($device === null) {
            throw ApiException::notFound('Device not found.');
        }
        $device->delete();

        return ApiResponse::noContent();
    }
}
