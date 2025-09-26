<?php

namespace App\Http\Requests\Checkpoint;

use App\Http\Requests\ApiFormRequest;

/**
 * Validate payload for creating checkpoints.
 */
class CheckpointStoreRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
