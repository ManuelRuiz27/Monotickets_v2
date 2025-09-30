<?php

namespace App\Http\Requests\HostessAssignment;

use App\Http\Requests\ApiFormRequest;

/**
 * Validate filters for hostess self-assignment queries.
 */
class MyHostessAssignmentIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['sometimes', 'string', 'uuid', 'exists:events,id'],
        ];
    }
}
