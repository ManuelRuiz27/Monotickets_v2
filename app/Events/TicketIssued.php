<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

/**
 * Domain event fired when a ticket is issued.
 */
class TicketIssued
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public Ticket $ticket,
        public User $actor,
        public Request $request,
        public string $tenantId,
        public array $snapshot
    ) {
    }
}
