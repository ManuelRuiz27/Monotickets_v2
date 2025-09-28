<?php

namespace App\Http\Requests\Guest;

use App\Http\Requests\ApiFormRequest;
use App\Models\Guest;
use Illuminate\Validation\Rule;

/**
 * Shared validation logic for guest requests.
 */
abstract class GuestRequest extends ApiFormRequest
{
    protected ?string $resolvedEventId = null;

    protected ?string $routeGuestId = null;

    /**
     * Prepare the data for validation by resolving route context.
     */
    protected function prepareForValidation(): void
    {
        $routeEventId = $this->route('event_id');

        if (is_string($routeEventId) && $routeEventId !== '') {
            $this->resolvedEventId = $routeEventId;
        }

        $routeGuestId = $this->route('guest_id') ?? $this->route('guestId');

        if (is_string($routeGuestId) && $routeGuestId !== '') {
            $this->routeGuestId = $routeGuestId;

            if ($this->resolvedEventId === null) {
                $guest = Guest::withTrashed()->find($routeGuestId);

                if ($guest !== null) {
                    $this->resolvedEventId = (string) $guest->event_id;
                }
            }
        }
    }

    /**
     * Build the validation rules for a guest payload.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function guestRules(bool $partial): array
    {
        $required = $partial ? ['sometimes'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];
        $eventId = $this->resolvedEventId;

        return [
            'full_name' => array_merge($required, ['string', 'max:255']),
            'email' => array_merge(['nullable', 'email', 'max:255'], $this->uniqueEmailRule($eventId)),
            'phone' => array_merge($optional, ['string', 'max:50']),
            'organization' => array_merge($optional, ['string', 'max:255']),
            'rsvp_status' => array_merge($optional, [Rule::in(['none', 'invited', 'confirmed', 'declined'])]),
            'rsvp_at' => array_merge($optional, ['date']),
            'allow_plus_ones' => array_merge($optional, ['boolean']),
            'plus_ones_limit' => array_merge($optional, ['integer', 'min:0']),
            'custom_fields_json' => array_merge($optional, ['array']),
            'guest_list_id' => array_merge($optional, [
                'string',
                'uuid',
                Rule::exists('guest_lists', 'id')->where(function ($query) use ($eventId) {
                    if ($eventId !== null) {
                        $query->where('event_id', $eventId);
                    }

                    return $query;
                }),
            ]),
        ];
    }

    /**
     * Ensure boolean and integer fields are normalised.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if (array_key_exists('allow_plus_ones', $validated)) {
            $validated['allow_plus_ones'] = (bool) $validated['allow_plus_ones'];
        }

        if (array_key_exists('plus_ones_limit', $validated)) {
            $validated['plus_ones_limit'] = (int) $validated['plus_ones_limit'];
        }

        return $validated;
    }

    /**
     * Build the unique rule for the guest email scoped by event.
     *
     * @return array<int, Rule>
     */
    private function uniqueEmailRule(?string $eventId): array
    {
        if ($eventId === null) {
            return [];
        }

        return [
            Rule::unique('guests', 'email')
                ->where(function ($query) use ($eventId) {
                    $query->where('event_id', $eventId);
                    $query->whereNull('deleted_at');

                    return $query;
                })
                ->ignore($this->routeGuestId, 'id'),
        ];
    }
}
