<?php

namespace App\Http\Requests\Venue;

use App\Http\Requests\ApiFormRequest;

/**
 * Shared validation logic for venue requests.
 */
abstract class VenueRequest extends ApiFormRequest
{
    /**
     * Build the validation rules for a venue payload.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function venueRules(bool $partial): array
    {
        $required = $partial ? ['sometimes'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'name' => array_merge($required, ['string', 'max:255']),
            'address' => array_merge($optional, ['string', 'max:255']),
            'lat' => array_merge($optional, ['numeric', 'between:-90,90']),
            'lng' => array_merge($optional, ['numeric', 'between:-180,180']),
            'notes' => array_merge($optional, ['string']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('validation.venue.name.required'),
            'lat.numeric' => __('validation.venue.lat.numeric'),
            'lat.between' => __('validation.venue.lat.between'),
            'lng.numeric' => __('validation.venue.lng.numeric'),
            'lng.between' => __('validation.venue.lng.between'),
        ];
    }
}
