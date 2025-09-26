<?php

namespace App\Http\Requests\Checkpoint;

/**
 * Validate payload for creating checkpoints.
 */
class CheckpointStoreRequest extends CheckpointRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->checkpointRules(false);
    }
}
