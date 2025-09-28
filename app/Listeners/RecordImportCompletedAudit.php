<?php

namespace App\Listeners;

use App\Events\ImportProcessingCompleted;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;

/**
 * Persist audit logs when imports finish processing.
 */
class RecordImportCompletedAudit
{
    public function handle(ImportProcessingCompleted $event): void
    {
        $import = $event->import;

        AuditLog::create([
            'tenant_id' => $import->tenant_id,
            'user_id' => null,
            'entity' => 'import',
            'entity_id' => (string) $import->id,
            'action' => 'import_completed',
            'diff_json' => [
                'status' => $import->status,
                'rows_total' => $import->rows_total,
                'rows_ok' => $import->rows_ok,
                'rows_failed' => $import->rows_failed,
            ],
            'ip' => null,
            'ua' => null,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
