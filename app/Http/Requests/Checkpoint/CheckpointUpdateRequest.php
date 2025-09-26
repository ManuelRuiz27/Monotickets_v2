<?php

namespace App\Http\Requests\Checkpoint;

/**
 * Validate payload for updating checkpoints.
 */
class CheckpointUpdateRequest extends CheckpointRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->checkpointRules(true);
    }
}
