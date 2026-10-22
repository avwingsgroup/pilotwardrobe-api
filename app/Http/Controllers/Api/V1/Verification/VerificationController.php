<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Verification;

use App\Enums\AviatorStatus;
use App\Enums\MediaType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AviatorCredential;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaService;
use App\Services\Notifications\NotificationService;
use App\Services\Verification\KycService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Identity verification (VB16) and aviator credentials. */
final class VerificationController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->kyc->present($this->kyc->forUser($request->user())));
    }

    public function personal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:-16 years', 'after:1900-01-01'],
            'nationality' => ['required', 'string', 'size:2', 'alpha'],
        ], ['date_of_birth.before' => 'You must be at least 16 to verify.']);

        return ApiResponse::ok($this->kyc->present($this->kyc->savePersonal($request->user(), $data)));
    }

    public function document(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in(array_keys(config('kyc.document_types')))],
            'document_country' => ['nullable', 'string', 'size:2', 'alpha'],
            'document_number' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-\/ ]+$/'],
            'document_expires_on' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'document_front_media_id' => ['required', 'string', 'max:40'],
            'document_back_media_id' => ['nullable', 'string', 'max:40'],
        ], ['document_expires_on.after' => 'This document has expired.', 'document_front_media_id.required' => 'Upload the front of your document.']);

        return ApiResponse::ok($this->kyc->present($this->kyc->saveDocument($request->user(), $data)));
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate(['selfie_media_id' => ['nullable', 'string', 'max:40']]);

        return ApiResponse::ok($this->kyc->present($this->kyc->submit($request->user(), $data['selfie_media_id'] ?? null)), "Submitted. We'll email you when the review is done.");
    }

    /** `GET /me/aviator` */
    public function aviator(Request $request): JsonResponse
    {
        $credential = AviatorCredential::query()->where('user_id', $request->user()->id)->first();

        return ApiResponse::ok($credential ? $this->presentAviator($credential) : ['status' => AviatorStatus::NotSubmitted->value]);
    }

    /** `POST /me/aviator` — requires approved KYC. */
    public function applyAviator(Request $request, MediaService $media, NotificationService $notifications, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        if (! $user->isKycApproved()) {
            throw ApiException::kycRequired('Verify your identity before applying for the aviator badge.');
        }

        $data = $request->validate([
            'licence_type' => ['required', Rule::in(['spl', 'ppl', 'cpl', 'atpl', 'mpl', 'ame', 'atc', 'cabin_crew', 'other'])],
            'licence_number' => ['required', 'string', 'max:40'],
            'issuing_authority' => ['required', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:80'],
            'organisation' => ['nullable', 'string', 'max:120'],
            'type_ratings' => ['nullable', 'array', 'max:20'],
            'type_ratings.*' => ['string', 'max:20'],
            'licence_media_id' => ['required', 'string', 'max:40'],
        ]);

        $existing = AviatorCredential::query()->where('user_id', $user->id)->first();
        if ($existing && in_array($existing->status, [AviatorStatus::Pending, AviatorStatus::Approved], true)) {
            throw ApiException::conflict($existing->status === AviatorStatus::Approved ? 'You are already a verified aviator.' : 'Your application is being reviewed.');
        }

        $credential = DB::transaction(function () use ($user, $data, $existing, $media, $audit) {
            $credential = $existing ?? new AviatorCredential(['user_id' => $user->id]);
            $file = $media->claimOne($user, $data['licence_media_id'], MediaType::Kyc, 'licence_media_id', $credential->exists ? $credential : null);
            $credential->fill([
                'licence_type' => $data['licence_type'],
                'licence_number' => $data['licence_number'],
                'licence_last4' => substr(preg_replace('/\W/', '', $data['licence_number']), -2),
                'issuing_authority' => $data['issuing_authority'],
                'job_title' => $data['job_title'] ?? null,
                'organisation' => $data['organisation'] ?? null,
                'type_ratings' => array_values(array_map('strtoupper', $data['type_ratings'] ?? [])),
                'licence_media_id' => $file?->id,
                'status' => AviatorStatus::Pending,
                'rejection_reason' => null,
                'submitted_at' => now(),
            ])->save();
            $file?->attachTo($credential, 'licence');
            $audit->log('AVIATOR.SUBMITTED', $credential, $data['licence_type'], actor: $user);

            return $credential;
        });

        $notifications->notify($user, 'aviator.submitted', [], "aviator.submitted:{$credential->id}:".now()->timestamp);

        return ApiResponse::created($this->presentAviator($credential), 'Application submitted.');
    }

    public static function presentAviator(AviatorCredential $c): array
    {
        $number = (string) $c->licence_number;
        $masked = strlen($number) > 4 ? substr($number, 0, max(0, strlen($number) - 6)).'••••'.substr($number, -2) : '••••';

        return [
            'licence_type' => $c->licence_type,
            'licence_number_masked' => $masked,
            'issuing_authority' => $c->issuing_authority,
            'job_title' => $c->job_title,
            'organisation' => $c->organisation,
            'type_ratings' => $c->type_ratings ?? [],
            'status' => $c->status->value,
            'rejection_reason' => $c->rejection_reason,
            'submitted_at' => $c->submitted_at?->toIso8601ZuluString(),
            'verified_at' => $c->verified_at?->toIso8601ZuluString(),
        ];
    }
}
