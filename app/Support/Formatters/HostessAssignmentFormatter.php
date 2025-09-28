<?php

namespace App\Support\Formatters;

use App\Models\HostessAssignment;

/**
 * Helper to serialize hostess assignments for API responses.
 */
class HostessAssignmentFormatter
{
    /**
     * @return array<string, mixed>
     */
    public static function format(HostessAssignment $assignment): array
    {
        return [
            'id' => (string) $assignment->id,
            'tenant_id' => (string) $assignment->tenant_id,
            'hostess_user_id' => (string) $assignment->hostess_user_id,
            'event_id' => (string) $assignment->event_id,
            'venue_id' => $assignment->venue_id !== null ? (string) $assignment->venue_id : null,
            'checkpoint_id' => $assignment->checkpoint_id !== null ? (string) $assignment->checkpoint_id : null,
            'starts_at' => $assignment->starts_at?->toAtomString(),
            'ends_at' => $assignment->ends_at?->toAtomString(),
            'is_active' => (bool) $assignment->is_active,
            'hostess' => $assignment->relationLoaded('hostess') && $assignment->hostess !== null ? [
                'id' => (string) $assignment->hostess->id,
                'name' => $assignment->hostess->name,
            ] : null,
            'event' => $assignment->relationLoaded('event') && $assignment->event !== null ? [
                'id' => (string) $assignment->event->id,
                'name' => $assignment->event->name,
                'start_at' => $assignment->event->start_at?->toAtomString(),
                'end_at' => $assignment->event->end_at?->toAtomString(),
            ] : null,
            'venue' => $assignment->relationLoaded('venue') && $assignment->venue !== null ? [
                'id' => (string) $assignment->venue->id,
                'name' => $assignment->venue->name,
            ] : null,
            'checkpoint' => $assignment->relationLoaded('checkpoint') && $assignment->checkpoint !== null ? [
                'id' => (string) $assignment->checkpoint->id,
                'name' => $assignment->checkpoint->name,
            ] : null,
            'created_at' => $assignment->created_at?->toAtomString(),
            'updated_at' => $assignment->updated_at?->toAtomString(),
        ];
    }
}
