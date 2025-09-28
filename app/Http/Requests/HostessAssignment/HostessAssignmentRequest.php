<?php

namespace App\Http\Requests\HostessAssignment;

use App\Http\Requests\ApiFormRequest;

/**
 * Shared validation logic for hostess assignment payloads.
 */
abstract class HostessAssignmentRequest extends ApiFormRequest
{
    /**
     * Build validation rules for hostess assignments.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function assignmentRules(bool $partial, bool $allowTenantReference): array
    {
        $required = $partial ? ['sometimes'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        $rules = [
            'hostess_user_id' => array_merge($required, ['string', 'ulid', 'exists:users,id']),
            'event_id' => array_merge($required, ['string', 'uuid', 'exists:events,id']),
            'venue_id' => array_merge($optional, ['string', 'uuid', 'exists:venues,id']),
            'checkpoint_id' => array_merge($optional, ['string', 'uuid', 'exists:checkpoints,id']),
            'starts_at' => array_merge($required, ['date']),
            'ends_at' => array_merge($optional, ['date']),
            'is_active' => array_merge($optional, ['boolean']),
        ];

        if ($allowTenantReference) {
            $rules['tenant_id'] = ['sometimes', 'string', 'ulid', 'exists:tenants,id'];
        }

        return $rules;
    }
}
