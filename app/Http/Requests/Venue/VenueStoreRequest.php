<?php

namespace App\Http\Requests\Venue;

use App\Http\Requests\ApiFormRequest;

/**
 * Validate payload for creating venues.
 */
class VenueStoreRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
