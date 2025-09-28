<?php

namespace App\Providers;

use App\Events\TicketIssued;
use App\Events\TicketRevoked;
use App\Listeners\RecordTicketIssuedAudit;
use App\Listeners\RecordTicketRevokedAudit;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Register the application's event listeners.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        TicketIssued::class => [
            RecordTicketIssuedAudit::class,
        ],
        TicketRevoked::class => [
            RecordTicketRevokedAudit::class,
        ],
    ];

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
