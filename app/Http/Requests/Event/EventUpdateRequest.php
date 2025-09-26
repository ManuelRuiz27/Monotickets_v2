<?php

namespace App\Http\Requests\Event;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate payload for updating events.
 */
class EventUpdateRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organizer_user_id' => ['sometimes', 'string', Rule::exists('users', 'id')],
            'code' => ['sometimes', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'start_at' => ['sometimes', 'date'],
            'end_at' => ['sometimes', 'date'],
            'timezone' => ['sometimes', 'timezone'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'checkin_policy' => ['sometimes', Rule::in(['single', 'multiple'])],
            'settings_json' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
