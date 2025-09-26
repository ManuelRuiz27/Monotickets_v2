<?php

namespace App\Http\Requests\Checkpoint;

use App\Http\Requests\ApiFormRequest;

/**
 * Validate payload for updating checkpoints.
 */
class CheckpointUpdateRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
