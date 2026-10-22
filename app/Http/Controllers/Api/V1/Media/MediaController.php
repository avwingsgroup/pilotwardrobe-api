<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Media;

use App\Enums\MediaType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaAssetPresenter;
use App\Models\Listing;
use App\Models\Media;
use App\Services\Media\MediaService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaController extends Controller
{
    /** `POST /media` — multipart `file` + `type`. */
    public function store(Request $request, MediaService $media): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('platform.media.max_kilobytes')],
            'type' => ['required', Rule::in(MediaType::values())],
            'alt' => ['nullable', 'string', 'max:255'],
        ], [
            'file.max' => 'Files can be up to 15 MB.',
            'file.required' => 'Choose a file to upload.',
        ]);

        $type = MediaType::from($data['type']);
        if ($type === MediaType::Content && ! $request->user()->hasPermission('content.manage')) {
            throw ApiException::forbidden();
        }

        $asset = $media->upload($request->user(), $request->file('file'), $type, $data['alt'] ?? null);

        return ApiResponse::created(MediaAssetPresenter::make($asset), 'Uploaded.');
    }

    /** `DELETE /media/{id}` — unattached uploads owned by the caller only. */
    public function destroy(Request $request, string $id, MediaService $media): Response
    {
        $asset = Media::query()->where('owner_id', $request->user()->id)->whereKey($id)->first()
            ?? throw ApiException::notFound('File not found.');

        if ($asset->isAttached() && ! in_array($asset->attachable_type, ['listing'], true)) {
            throw ApiException::conflict('This file is in use and cannot be deleted.');
        }
        if ($asset->attachable_type === 'listing') {
            $listing = Listing::query()->find($asset->attachable_id);
            if ($listing && $listing->seller_id !== $request->user()->id) {
                throw ApiException::notFound('File not found.');
            }
            if ($listing && $listing->images()->count() <= 1 && in_array($listing->status->value, ['active', 'reserved', 'pending_review'], true)) {
                throw ApiException::conflict('A live listing needs at least one photo.');
            }
        }

        $media->delete($asset);

        return ApiResponse::noContent();
    }

    /** Local-driver private file delivery (development only), behind a temporary signed URL. */
    public function localPrivate(Request $request, string $mediaId): StreamedResponse
    {
        abort_unless($request->hasValidSignature() && config('services.media.driver') === 'local', 403);
        $asset = Media::query()->findOrFail($mediaId);

        return Storage::disk('local')->response('media/'.$asset->public_id.'.'.$asset->format);
    }

    /** Signed statement download (URL issued by `POST /me/wallet/statements`). */
    public function statement(Request $request): StreamedResponse
    {
        $path = (string) base64_decode((string) $request->query('path'), true);
        abort_unless(str_starts_with($path, 'statements/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'pilotwardrobe-statement.pdf', ['Content-Type' => 'application/pdf']);
    }
}
