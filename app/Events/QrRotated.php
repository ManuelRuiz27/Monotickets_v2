<?php

namespace App\Events;

use App\Models\Qr;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

/**
 * Domain event fired when a ticket QR code is created or rotated.
 */
class QrRotated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>|null  $original
     * @param  array<string, mixed>  $updated
     * @param  array<string, array<string, mixed>>  $changes
     */
    public function __construct(
        public Ticket $ticket,
        public Qr $qr,
        public User $actor,
        public Request $request,
        public string $tenantId,
        public ?array $original,
        public array $updated,
        public array $changes
    ) {
    }
}
