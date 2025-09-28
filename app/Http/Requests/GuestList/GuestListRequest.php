<?php

namespace App\Http\Requests\GuestList;

use App\Http\Requests\ApiFormRequest;

/**
 * Shared validation logic for guest list requests.
 */
abstract class GuestListRequest extends ApiFormRequest
{
    /**
     * Build the validation rules for a guest list payload.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function guestListRules(bool $partial): array
    {
        $required = $partial ? ['sometimes'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'name' => array_merge($required, ['string', 'max:255']),
            'description' => array_merge($optional, ['string']),
            'criteria_json' => array_merge($optional, ['array']),
        ];
    }
}
