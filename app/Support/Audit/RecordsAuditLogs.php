<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Shared helpers for recording audit logs and calculating diffs.
 */
trait RecordsAuditLogs
{
    /**
     * Persist an audit log entry for an entity lifecycle action.
     *
     * @param array<string, mixed> $diff
     */
    private function recordAuditLog(
        User $actor,
        Request $request,
        string $entity,
        string $entityId,
        string $action,
        array $diff,
        ?string $tenantId
    ): void {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $actor->id,
            'entity' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'diff_json' => $diff,
            'ip' => (string) $request->ip(),
            'ua' => (string) $request->userAgent(),
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Calculate the differences between two state snapshots.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $updated
     * @return array<string, array<string, mixed>>
     */
    private function calculateDifferences(array $original, array $updated): array
    {
        $changes = [];

        foreach ($updated as $key => $value) {
            if (! array_key_exists($key, $original)) {
                continue;
            }

            if ($original[$key] === $value) {
                continue;
            }

            $changes[$key] = [
                'before' => $original[$key],
                'after' => $value,
            ];
        }

        return $changes;
    }
}
