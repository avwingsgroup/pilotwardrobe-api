<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Writes immutable audit rows (§4.5). Call it from inside the same database
 * transaction as the change it records, so the log and the change commit together.
 *
 * Actions are SCREAMING_SNAKE `TARGET.VERB` (e.g. `USER.SUSPENDED`).
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        Model|string|null $target = null,
        ?string $detail = null,
        string $severity = 'info',
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?User $actor = null,
        ?string $targetLabel = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        [$targetType, $targetId] = match (true) {
            $target instanceof Model => [self::typeOf($target), (string) $target->getKey()],
            is_string($target) => [null, null],
            default => [null, null],
        };

        return AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->displayName(),
            'actor_is_system' => $actor === null,
            'action' => $action,
            'severity' => $severity,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_label' => $targetLabel ?? (is_string($target) ? $target : $this->labelFor($target)),
            'detail' => $detail,
            'before' => $before ? $this->scrub($before) : null,
            'after' => $after ? $this->scrub($after) : null,
            'metadata' => $metadata ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'request_id' => Context::get('request_id'),
            'created_at' => now(),
        ]);
    }

    /** Short type name: the morph alias when one exists, else the snake-cased class name. */
    public static function typeOf(Model $model): string
    {
        $alias = array_search($model::class, Relation::morphMap(), true);

        return is_string($alias) ? $alias : Str::snake(class_basename($model));
    }

    private function labelFor(?Model $target): ?string
    {
        if ($target === null) {
            return null;
        }

        return match (true) {
            $target instanceof User => $target->displayName(),
            isset($target->number) => (string) $target->number,
            isset($target->code) => (string) $target->code,
            isset($target->title) => mb_substr((string) $target->title, 0, 200),
            isset($target->name) => (string) $target->name,
            default => (string) $target->getKey(),
        };
    }

    /** Never persist secrets or raw identity data in audit snapshots. */
    private function scrub(array $values): array
    {
        $sensitive = ['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'document_number',
            'account_number', 'provider_recipient_code', 'licence_number', 'date_of_birth', 'token'];

        foreach ($values as $key => $value) {
            if (in_array($key, $sensitive, true)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->scrub($value);
            }
        }

        return $values;
    }
}
