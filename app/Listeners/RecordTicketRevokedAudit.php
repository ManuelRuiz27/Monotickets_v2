<?php

namespace App\Listeners;

use App\Events\TicketRevoked;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;

/**
 * Persist audit logs when tickets are revoked.
 */
class RecordTicketRevokedAudit
{
    public function handle(TicketRevoked $event): void
    {
        AuditLog::create([
            'tenant_id' => $event->tenantId,
            'user_id' => $event->actor->id,
            'entity' => 'ticket',
            'entity_id' => $event->ticket->id,
            'action' => 'revoked',
            'diff_json' => [
                'before' => $event->original,
                'after' => $event->updated,
                'changes' => $event->changes,
            ],
            'ip' => (string) $event->request->ip(),
            'ua' => (string) $event->request->userAgent(),
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
