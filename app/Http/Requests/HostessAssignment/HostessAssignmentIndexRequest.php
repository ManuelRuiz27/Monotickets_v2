<?php

namespace App\Http\Requests\HostessAssignment;

use App\Http\Requests\ApiFormRequest;

/**
 * Validate filters for hostess assignment listings.
 */
class HostessAssignmentIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['sometimes', 'string', 'ulid', 'exists:tenants,id'],
            'event_id' => ['sometimes', 'string', 'uuid', 'exists:events,id'],
            'hostess_user_id' => ['sometimes', 'string', 'ulid', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
