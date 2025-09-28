<?php

namespace App\Http\Requests\Ticket;

use App\Http\Requests\ApiFormRequest;
use App\Models\Ticket;
use Illuminate\Validation\Rule;

/**
 * Shared validation logic for ticket requests.
 */
abstract class TicketRequest extends ApiFormRequest
{
    protected ?string $resolvedEventId = null;

    protected ?string $routeTicketId = null;

    /**
     * Prepare the data for validation by resolving route context.
     */
    protected function prepareForValidation(): void
    {
        $routeTicketId = $this->route('ticket_id') ?? $this->route('ticketId');

        if (is_string($routeTicketId) && $routeTicketId !== '') {
            $this->routeTicketId = $routeTicketId;

            $ticket = Ticket::withTrashed()->find($routeTicketId);

            if ($ticket !== null) {
                $this->resolvedEventId = (string) $ticket->event_id;
            }
        }

        $routeEventId = $this->route('event_id');

        if (is_string($routeEventId) && $routeEventId !== '') {
            $this->resolvedEventId = $routeEventId;
        }
    }

    /**
     * Build the validation rules for a ticket payload.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function ticketRules(bool $partial): array
    {
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'type' => array_merge($optional, [Rule::in(['general', 'vip', 'staff'])]),
            'price_cents' => array_merge($optional, ['integer', 'min:0']),
            'seat_section' => array_merge($optional, ['string', 'max:255', 'required_with:seat_row', 'required_with:seat_code']),
            'seat_row' => array_merge($optional, ['string', 'max:255', 'required_with:seat_section', 'required_with:seat_code']),
            'seat_code' => array_merge($optional, ['string', 'max:255', 'required_with:seat_section', 'required_with:seat_row']),
            'expires_at' => array_merge($optional, ['date']),
            'status' => array_merge($partial ? ['sometimes'] : ['nullable'], [Rule::in(['issued', 'revoked', 'used', 'expired'])]),
        ];
    }

    /**
     * Normalize integer fields after validation.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if (array_key_exists('price_cents', $validated) && $validated['price_cents'] !== null) {
            $validated['price_cents'] = (int) $validated['price_cents'];
        }

        return $validated;
    }
}
