<?php

namespace App\Listeners;

use App\Events\QrRotated;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;

/**
 * Persist audit logs when QR codes are rotated.
 */
class RecordQrRotatedAudit
{
    public function handle(QrRotated $event): void
    {
        AuditLog::create([
            'tenant_id' => $event->tenantId,
            'user_id' => $event->actor->id,
            'entity' => 'qr',
            'entity_id' => $event->qr->id,
            'action' => 'rotated',
            'diff_json' => array_filter([
                'before' => $event->original,
                'after' => $event->updated,
                'changes' => $event->changes,
            ], static fn ($value) => $value !== null && $value !== []),
            'ip' => (string) $event->request->ip(),
            'ua' => (string) $event->request->userAgent(),
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
