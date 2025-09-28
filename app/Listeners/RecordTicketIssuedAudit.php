<?php

namespace App\Listeners;

use App\Events\TicketIssued;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;

/**
 * Persist audit logs when tickets are issued.
 */
class RecordTicketIssuedAudit
{
    public function handle(TicketIssued $event): void
    {
        AuditLog::create([
            'tenant_id' => $event->tenantId,
            'user_id' => $event->actor->id,
            'entity' => 'ticket',
            'entity_id' => $event->ticket->id,
            'action' => 'issued',
            'diff_json' => [
                'after' => $event->snapshot,
            ],
            'ip' => (string) $event->request->ip(),
            'ua' => (string) $event->request->userAgent(),
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
