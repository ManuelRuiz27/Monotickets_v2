<?php

namespace App\Http\Requests\Guest;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate query parameters for guest listings.
 */
class GuestIndexRequest extends ApiFormRequest
{
    /**
     * Normalise filter inputs prior to validation.
     */
    protected function prepareForValidation(): void
    {
        $statuses = $this->input('rsvp_status');

        if (is_string($statuses)) {
            $this->merge(['rsvp_status' => array_filter([$statuses])]);
        }

        $list = $this->input('list');

        if ($list === '') {
            $this->merge(['list' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'rsvp_status' => ['sometimes', 'array'],
            'rsvp_status.*' => ['string', Rule::in(['none', 'invited', 'confirmed', 'declined'])],
            'list' => ['sometimes', 'nullable', 'string', 'uuid'],
            'search' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
