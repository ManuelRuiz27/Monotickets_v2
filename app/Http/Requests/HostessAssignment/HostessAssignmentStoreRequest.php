<?php

namespace App\Http\Requests\HostessAssignment;

/**
 * Validate payload for creating hostess assignments.
 */
class HostessAssignmentStoreRequest extends HostessAssignmentRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->assignmentRules(false, true);
    }
}
