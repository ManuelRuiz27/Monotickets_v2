<?php

namespace App\Http\Requests\GuestList;

use App\Http\Requests\ApiFormRequest;

/**
 * Validate query parameters for guest list listings.
 */
class GuestListIndexRequest extends ApiFormRequest
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
