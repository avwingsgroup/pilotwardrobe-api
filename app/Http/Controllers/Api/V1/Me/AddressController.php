<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Me\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class AddressController extends Controller
{
    private const MAX_ADDRESSES = 20;

    public function index(Request $request): JsonResponse
    {
        $addresses = Address::query()->where('user_id', $request->user()->id)
            ->orderByDesc('is_default')->orderBy('created_at')->get();

        return ApiResponse::ok(AddressResource::collection($addresses)->resolve($request));
    }

    public function store(AddressRequest $request): JsonResponse
    {
        $user = $request->user();
        if (Address::query()->where('user_id', $user->id)->count() >= self::MAX_ADDRESSES) {
            throw ApiException::conflict('You can save up to 20 addresses.');
        }

        $address = DB::transaction(function () use ($request, $user) {
            $data = $request->validated();
            $isFirst = ! Address::query()->where('user_id', $user->id)->exists();
            $makeDefault = $isFirst || ($data['is_default'] ?? false);
            if ($makeDefault) {
                Address::query()->where('user_id', $user->id)->update(['is_default' => false]);
            }

            return Address::query()->create([...$data, 'user_id' => $user->id, 'country_code' => strtoupper($data['country_code']), 'is_default' => $makeDefault]);
        });

        return ApiResponse::created(new AddressResource($address));
    }

    public function update(AddressRequest $request, string $id): JsonResponse
    {
        $address = $this->find($request, $id);

        DB::transaction(function () use ($request, $address) {
            $data = $request->validated();
            if (($data['is_default'] ?? false) === true) {
                Address::query()->where('user_id', $address->user_id)->where('id', '!=', $address->id)->update(['is_default' => false]);
            } elseif (array_key_exists('is_default', $data) && $data['is_default'] === false && $address->is_default) {
                unset($data['is_default']); // there must always be a default; set another one instead
            }
            if (isset($data['country_code'])) {
                $data['country_code'] = strtoupper($data['country_code']);
            }
            $address->fill($data)->save();
        });

        return ApiResponse::ok(new AddressResource($address->fresh()));
    }

    public function destroy(Request $request, string $id): Response
    {
        $address = $this->find($request, $id);

        DB::transaction(function () use ($address) {
            $wasDefault = $address->is_default;
            $address->forceFill(['is_default' => false])->save();
            $address->delete();
            if ($wasDefault) {
                Address::query()->where('user_id', $address->user_id)->oldest()->first()?->forceFill(['is_default' => true])->save();
            }
        });

        return ApiResponse::noContent();
    }

    private function find(Request $request, string $id): Address
    {
        return Address::query()->where('user_id', $request->user()->id)->whereKey($id)->first()
            ?? throw ApiException::notFound('Address not found.');
    }
}
