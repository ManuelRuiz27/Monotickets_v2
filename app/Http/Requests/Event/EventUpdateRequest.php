<?php

namespace App\Http\Requests\Event;

/**
 * Validate payload for updating events.
 */
class EventUpdateRequest extends EventRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $eventId = (string) $this->route('event_id');

        return $this->eventRules(true, $eventId !== '' ? $eventId : null);
    }
}
