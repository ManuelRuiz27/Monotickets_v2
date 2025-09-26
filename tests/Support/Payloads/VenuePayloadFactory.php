<?php

namespace Tests\Support\Payloads;

/**
 * Build payloads for venue API endpoints.
 */
class VenuePayloadFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function make(array $overrides = []): array
    {
        $defaults = [
            'name' => 'Main Hall',
            'address' => '123 Event Street',
            'lat' => 40.7128,
            'lng' => -74.0060,
            'notes' => 'Access through the main entrance.',
        ];

        return array_merge($defaults, $overrides);
    }
}
