<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public static function record(
        string $event,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $reason = null,
        ?int $businessUnitId = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'business_unit_id' => $businessUnitId,
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'reason' => $reason,
            'ip_address' => Request::ip(),
            'user_agent' => mb_substr((string) Request::userAgent(), 0, 255),
        ]);
    }
}
