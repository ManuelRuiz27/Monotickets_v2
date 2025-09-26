<?php

namespace Tests\Support\Payloads;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Build consistent payloads for event related API requests.
 */
class EventPayloadFactory
{
    /**
     * Generate a payload for creating or updating an event.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function make(Tenant $tenant, User $organizer, array $overrides = []): array
    {
        $startAt = CarbonImmutable::now()->addDays(5);
        $endAt = $startAt->addHours(4);

        $defaults = [
            'tenant_id' => $tenant->id,
            'organizer_user_id' => $organizer->id,
            'code' => 'EVT-' . Str::upper(Str::random(6)),
            'name' => 'Sample Event',
            'description' => 'Sample event description',
            'start_at' => $startAt->toISOString(),
            'end_at' => $endAt->toISOString(),
            'timezone' => 'UTC',
            'status' => 'draft',
            'capacity' => 200,
            'checkin_policy' => 'single',
            'settings_json' => ['language' => 'en'],
        ];

        return array_merge($defaults, $overrides);
    }
}
