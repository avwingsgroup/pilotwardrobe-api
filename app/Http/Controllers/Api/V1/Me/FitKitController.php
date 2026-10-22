<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Me\FitKitProfileRequest;
use App\Http\Resources\FitKitProfileResource;
use App\Models\FitKitProfile;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Fit Kit size profiles (V6). The default profile drives `sizes.fit_match` on listings. */
final class FitKitController extends Controller
{
    private const MAX_PROFILES = 10;

    public function index(Request $request): JsonResponse
    {
        $profiles = FitKitProfile::query()->where('user_id', $request->user()->id)
            ->orderByDesc('is_default')->orderBy('created_at')->get();

        return ApiResponse::ok(FitKitProfileResource::collection($profiles)->resolve($request));
    }

    public function store(FitKitProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        if (FitKitProfile::query()->where('user_id', $user->id)->count() >= self::MAX_PROFILES) {
            throw ApiException::conflict('You can save up to 10 Fit Kit profiles.');
        }

        $profile = DB::transaction(function () use ($request, $user) {
            $data = $request->validated();
            $makeDefault = ($data['is_default'] ?? false) || ! FitKitProfile::query()->where('user_id', $user->id)->exists();
            if ($makeDefault) {
                FitKitProfile::query()->where('user_id', $user->id)->update(['is_default' => false]);
            }
            $data['preferences'] = array_merge(FitKitProfileResource::DEFAULT_PREFERENCES, $data['preferences'] ?? []);

            return FitKitProfile::query()->create([...$data, 'user_id' => $user->id, 'is_default' => $makeDefault]);
        });

        return ApiResponse::created(new FitKitProfileResource($profile), 'Profile saved.');
    }

    public function update(FitKitProfileRequest $request, string $id): JsonResponse
    {
        $profile = $this->find($request, $id);

        DB::transaction(function () use ($request, $profile) {
            $data = $request->validated();
            if (($data['is_default'] ?? false) === true) {
                FitKitProfile::query()->where('user_id', $profile->user_id)->where('id', '!=', $profile->id)->update(['is_default' => false]);
            } elseif (($data['is_default'] ?? null) === false && $profile->is_default) {
                unset($data['is_default']);
            }
            if (isset($data['preferences'])) {
                $data['preferences'] = array_merge(FitKitProfileResource::DEFAULT_PREFERENCES, $profile->preferences ?? [], $data['preferences']);
            }
            $profile->fill($data)->save();
        });

        return ApiResponse::ok(new FitKitProfileResource($profile->fresh()), 'Profile saved.');
    }

    public function destroy(Request $request, string $id): Response
    {
        $profile = $this->find($request, $id);

        DB::transaction(function () use ($profile) {
            $wasDefault = $profile->is_default;
            $profile->delete();
            if ($wasDefault) {
                FitKitProfile::query()->where('user_id', $profile->user_id)->oldest()->first()?->forceFill(['is_default' => true])->save();
            }
        });

        return ApiResponse::noContent();
    }

    private function find(Request $request, string $id): FitKitProfile
    {
        return FitKitProfile::query()->where('user_id', $request->user()->id)->whereKey($id)->first()
            ?? throw ApiException::notFound('Profile not found.');
    }
}
