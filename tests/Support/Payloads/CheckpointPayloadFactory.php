<?php

namespace Tests\Support\Payloads;

/**
 * Build payloads for checkpoint API endpoints.
 */
class CheckpointPayloadFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function make(array $overrides = []): array
    {
        $defaults = [
            'name' => 'Entrance Gate',
            'description' => 'Primary access point for attendees.',
        ];

        return array_merge($defaults, $overrides);
    }
}
