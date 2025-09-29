<?php

namespace App\Http\Requests\Checkpoint;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation logic for checkpoint requests.
 */
abstract class CheckpointRequest extends ApiFormRequest
{
    private ?string $providedEventId = null;

    private ?string $providedVenueId = null;

    private ?string $resolvedEventId = null;

    private ?string $resolvedVenueId = null;

    /**
     * Prepare the data for validation by normalising route parameters.
     */
    protected function prepareForValidation(): void
    {
        $this->providedEventId = $this->has('event_id') ? (string) $this->input('event_id') : null;
        $this->providedVenueId = $this->has('venue_id') ? (string) $this->input('venue_id') : null;

        $routeEventId = $this->route('event_id');
        $routeVenueId = $this->route('venue_id');

        if ($routeEventId !== null && $routeEventId !== '') {
            $this->resolvedEventId = (string) $routeEventId;

            if (! $this->has('event_id')) {
                $this->merge(['event_id' => $this->resolvedEventId]);
            }
        } else {
            $this->resolvedEventId = $this->providedEventId;
        }

        if ($routeVenueId !== null && $routeVenueId !== '') {
            $this->resolvedVenueId = (string) $routeVenueId;

            if (! $this->has('venue_id')) {
                $this->merge(['venue_id' => $this->resolvedVenueId]);
            }
        } else {
            $this->resolvedVenueId = $this->providedVenueId;
        }
    }

    /**
     * Build the validation rules for a checkpoint payload.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function checkpointRules(bool $partial): array
    {
        $required = $partial ? ['sometimes'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];
        $eventId = $this->resolvedEventId;

        return [
            'name' => array_merge($required, ['string', 'max:255']),
            'description' => array_merge($optional, ['string']),
            'event_id' => array_merge($required, ['string', 'uuid', Rule::exists('events', 'id')]),
            'venue_id' => array_merge($required, [
                'string',
                'uuid',
                Rule::exists('venues', 'id')->where(function ($query) use ($eventId) {
                    if ($eventId !== null) {
                        $query->where('event_id', $eventId);
                    }

                    return $query;
                }),
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('validation.checkpoint.name.required'),
            'event_id.required' => __('validation.checkpoint.event_id.required'),
            'event_id.uuid' => __('validation.checkpoint.event_id.uuid'),
            'event_id.exists' => __('validation.checkpoint.event_id.exists'),
            'venue_id.required' => __('validation.checkpoint.venue_id.required'),
            'venue_id.uuid' => __('validation.checkpoint.venue_id.uuid'),
            'venue_id.exists' => __('validation.checkpoint.venue_id.exists'),
        ];
    }

    /**
     * Ensure route parameters match the provided identifiers.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->providedEventId !== null
                && $this->resolvedEventId !== null
                && $this->providedEventId !== $this->resolvedEventId
            ) {
                $validator->errors()->add('event_id', __('validation.checkpoint.event_id.mismatch'));
            }

            if ($this->providedVenueId !== null
                && $this->resolvedVenueId !== null
                && $this->providedVenueId !== $this->resolvedVenueId
            ) {
                $validator->errors()->add('venue_id', __('validation.checkpoint.venue_id.mismatch'));
            }
        });
    }
}
