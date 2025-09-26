<?php

namespace App\Providers;

use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Policies\CheckpointPolicy;
use App\Policies\EventPolicy;
use App\Policies\UserPolicy;
use App\Policies\VenuePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Register application authentication services.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     */
    protected $policies = [
        Checkpoint::class => CheckpointPolicy::class,
        Event::class => EventPolicy::class,
        User::class => UserPolicy::class,
        Venue::class => VenuePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
