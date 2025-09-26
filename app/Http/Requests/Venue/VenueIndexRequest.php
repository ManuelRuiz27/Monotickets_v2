<?php

namespace App\Http\Requests\Venue;

use App\Http\Requests\ApiFormRequest;

/**
 * Validate query params for venue listings.
 */
class VenueIndexRequest extends ApiFormRequest
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
