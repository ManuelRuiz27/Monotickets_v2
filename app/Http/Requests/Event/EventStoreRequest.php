<?php

namespace App\Http\Requests\Event;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate payload for creating events.
 */
class EventStoreRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'string', Rule::exists('tenants', 'id')],
            'organizer_user_id' => ['required', 'string', Rule::exists('users', 'id')],
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'timezone' => ['required', 'timezone'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'checkin_policy' => ['required', Rule::in(['single', 'multiple'])],
            'settings_json' => ['nullable', 'array'],
        ];
    }
}
