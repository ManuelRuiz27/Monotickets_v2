<?php

namespace App\Listeners;

use App\Events\ImportProcessingStarted;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;

/**
 * Persist audit logs when imports start processing.
 */
class RecordImportStartedAudit
{
    public function handle(ImportProcessingStarted $event): void
    {
        $import = $event->import;

        AuditLog::create([
            'tenant_id' => $import->tenant_id,
            'user_id' => null,
            'entity' => 'import',
            'entity_id' => (string) $import->id,
            'action' => 'import_started',
            'diff_json' => [
                'status' => $import->status,
            ],
            'ip' => null,
            'ua' => null,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
