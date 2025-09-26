<?php

namespace App\Http\Requests\Checkpoint;

use App\Http\Requests\ApiFormRequest;

/**
 * Validate query params for listing checkpoints.
 */
class CheckpointIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
