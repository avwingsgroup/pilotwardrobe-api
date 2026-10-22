<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Enums\KycStatus;
use App\Enums\MediaType;
use App\Exceptions\ApiException;
use App\Jobs\Verification\RunKycChecks;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaService;
use App\Services\Notifications\NotificationService;
use App\Support\Ids\ReferenceGenerator;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Identity verification (VB16 user flow, AD5 staff review; handoff §8.8).
 *
 * personal → document → (liveness hand-off) → submit → automated checks (Prembly) → staff decision.
 * Personal data is encrypted at rest; document images are private Cloudinary assets.
 */
final class KycService
{
    public function __construct(
        private readonly MediaService $media,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    public function forUser(User $user): KycVerification
    {
        $kyc = KycVerification::query()->where('user_id', $user->id)->first();
        if ($kyc !== null) {
            return $kyc;
        }

        $kyc = new KycVerification(['user_id' => $user->id]);
        $kyc->code = ReferenceGenerator::unique(KycVerification::class, 'code', fn () => ReferenceGenerator::prefixed('KY', 6));
        $kyc->save();

        return $kyc;
    }

    /** Step 1. */
    public function savePersonal(User $user, array $data): KycVerification
    {
        $kyc = $this->editable($user);
        $kyc->fill([
            'legal_first_name' => trim($data['first_name']),
            'legal_last_name' => trim($data['last_name']),
            'date_of_birth' => $data['date_of_birth'],
            'nationality' => strtoupper($data['nationality']),
            'step' => 'document',
        ])->save();

        return $kyc;
    }

    /** Step 2: document type, number and private media ids. */
    public function saveDocument(User $user, array $data): KycVerification
    {
        $kyc = $this->editable($user);
        if ($kyc->legal_first_name === null) {
            throw ApiException::validation(['first_name' => 'Complete your personal details first.']);
        }

        $twoSided = in_array($data['document_type'], config('kyc.two_sided'), true);
        if ($twoSided && empty($data['document_back_media_id'])) {
            throw ApiException::validation(['document_back_media_id' => 'Upload the back of the document too.']);
        }

        DB::transaction(function () use ($user, $kyc, $data) {
            $front = $this->media->claimOne($user, $data['document_front_media_id'], MediaType::Kyc, 'document_front_media_id', $kyc);
            $back = $this->media->claimOne($user, $data['document_back_media_id'] ?? null, MediaType::Kyc, 'document_back_media_id', $kyc);
            $front?->attachTo($kyc, 'document_front');
            $back?->attachTo($kyc, 'document_back');

            $kyc->fill([
                'document_type' => $data['document_type'],
                'document_country' => strtoupper($data['document_country'] ?? $kyc->nationality ?? 'NG'),
                'document_number' => $data['document_number'] ?? null,
                'document_expires_on' => $data['document_expires_on'] ?? null,
                'document_front_media_id' => $front?->id,
                'document_back_media_id' => $back?->id,
                'step' => 'liveness',
            ]);
            [$token, $hash] = $this->handoffToken();
            $kyc->handoff_token_hash = $hash;
            $kyc->handoff_expires_at = now()->addMinutes((int) config('kyc.handoff_ttl_minutes'));
            $kyc->save();
            $kyc->setAttribute('handoff_plain', $token);
        });

        return $kyc;
    }

    /** Step 3: selfie (from the liveness step) + submit for review. */
    public function submit(User $user, ?string $selfieMediaId): KycVerification
    {
        $kyc = $this->editable($user);
        if ($kyc->document_front_media_id === null) {
            throw ApiException::validation(['document_front_media_id' => 'Upload your document first.']);
        }

        $kyc = DB::transaction(function () use ($user, $kyc, $selfieMediaId) {
            if ($selfieMediaId) {
                $selfie = $this->media->claimOne($user, $selfieMediaId, MediaType::Kyc, 'selfie_media_id', $kyc);
                $selfie?->attachTo($kyc, 'selfie');
                $kyc->selfie_media_id = $selfie?->id;
            }
            $kyc->status->assertCanTransitionTo(KycStatus::Pending, 'This verification has already been submitted.');
            $kyc->fill([
                'status' => KycStatus::Pending,
                'step' => 'review',
                'submitted_at' => now(),
                'rejection_reason' => null,
                'checks_status' => 'queued',
                'handoff_token_hash' => null,
            ])->save();
            $this->audit->log('KYC.SUBMITTED', $kyc, $kyc->document_type, actor: $user);
            RunKycChecks::dispatch($kyc->id)->afterCommit();

            return $kyc;
        });

        $this->notifications->notify($user, 'kyc.submitted', [], "kyc.submitted:{$kyc->id}:".$kyc->submitted_at->timestamp);

        return $kyc;
    }

    /** Staff decisions (AD5). `$decision`: approve | reject | escalate | request_info. */
    public function decide(KycVerification $kyc, User $staff, string $decision, ?string $reason): KycVerification
    {
        $kyc = DB::transaction(function () use ($kyc, $staff, $decision, $reason) {
            $kyc = KycVerification::query()->lockForUpdate()->findOrFail($kyc->id);
            if ($kyc->user_id === $staff->id) {
                throw ApiException::conflict('You cannot review your own verification.');
            }

            $target = match ($decision) {
                'approve' => KycStatus::Approved,
                'reject' => KycStatus::Rejected,
                'escalate' => KycStatus::Escalated,
                'request_info' => KycStatus::MoreInfo,
                default => throw ApiException::validation(['decision' => 'Choose a valid decision.']),
            };
            $kyc->status->assertCanTransitionTo($target, 'This verification is not awaiting review.');

            $before = ['status' => $kyc->status->value];
            $kyc->fill([
                'status' => $target,
                'reviewer_note' => $reason,
                'rejection_reason' => in_array($decision, ['reject', 'request_info'], true) ? $reason : null,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'step' => match ($decision) {
                    'approve' => 'done',
                    'request_info', 'reject' => 'document',
                    default => 'review',
                },
            ])->save();

            $kyc->user->bumpPermissionsVersion();
            $this->audit->log('KYC.'.strtoupper($decision === 'request_info' ? 'MORE_INFO_REQUESTED' : $target->value), $kyc, $reason,
                $decision === 'approve' ? 'success' : ($decision === 'reject' ? 'danger' : 'warning'), $before, ['status' => $target->value], actor: $staff);

            return $kyc;
        });

        $template = match ($decision) {
            'approve' => 'kyc.approved',
            'reject' => 'kyc.rejected',
            'request_info' => 'kyc.more_info',
            default => null,
        };
        if ($template) {
            $this->notifications->notify($kyc->user, $template, ['reason' => (string) $reason], "{$template}:{$kyc->id}:".now()->timestamp);
        }

        return $kyc;
    }

    /** `GET /me/kyc` shape. */
    public function present(KycVerification $kyc): array
    {
        $handoffToken = $kyc->getAttribute('handoff_plain');
        $handoffUrl = $handoffToken ? config('platform.frontend_url').'/k/'.$handoffToken : null;

        return [
            'id' => $kyc->id,
            'status' => $kyc->status->value,
            'step' => $kyc->step,
            'document_type' => $kyc->document_type,
            'personal' => $kyc->legal_first_name ? [
                'first_name' => $kyc->legal_first_name,
                'last_name' => $kyc->legal_last_name,
                'date_of_birth' => $kyc->date_of_birth,
                'nationality' => $kyc->nationality,
            ] : null,
            'document_front' => $kyc->document_front_media_id ? ['id' => $kyc->document_front_media_id, 'uploaded' => true] : null,
            'document_back' => $kyc->document_back_media_id ? ['id' => $kyc->document_back_media_id, 'uploaded' => true] : null,
            'selfie' => $kyc->selfie_media_id ? ['id' => $kyc->selfie_media_id, 'uploaded' => true] : null,
            'handoff_url' => $handoffUrl,
            'handoff_qr_svg' => $handoffUrl ? $this->qr($handoffUrl) : null,
            'handoff_expires_at' => $handoffUrl ? $kyc->handoff_expires_at?->toIso8601ZuluString() : null,
            'rejection_reason' => $kyc->rejection_reason,
            'submitted_at' => $kyc->submitted_at?->toIso8601ZuluString(),
            'reviewed_at' => $kyc->reviewed_at?->toIso8601ZuluString(),
            'document_types' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(config('kyc.document_types')), config('kyc.document_types')),
        ];
    }

    private function editable(User $user): KycVerification
    {
        $kyc = $this->forUser($user);
        if (! $kyc->status->isEditableByUser()) {
            throw ApiException::invalidTransition($kyc->status === KycStatus::Approved
                ? 'Your identity is already verified.'
                : 'Your verification is being reviewed. We will email you when it is done.');
        }

        return $kyc;
    }

    /** @return array{0: string, 1: string} plain, hash */
    private function handoffToken(): array
    {
        $token = Str::lower(Str::random(10));

        return [$token, hash('sha256', $token)];
    }

    private function qr(string $url): string
    {
        return (new Writer(new ImageRenderer(
            new RendererStyle(180, 1),
            new SvgImageBackEnd,
        )))->writeString($url);
    }
}
