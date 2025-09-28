<?php

namespace App\Http\Requests\HostessAssignment;

/**
 * Validate payload for updating hostess assignments.
 */
class HostessAssignmentUpdateRequest extends HostessAssignmentRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->assignmentRules(true, false);
    }
}
