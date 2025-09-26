<?php

namespace App\Http\Requests\Event;

use Illuminate\Validation\Rule;

/**
 * Validate payload for creating events.
 */
class EventStoreRequest extends EventRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'tenant_id' => ['nullable', 'ulid', Rule::exists('tenants', 'id')],
        ], $this->eventRules(false));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'tenant_id.ulid' => __('validation.event.tenant_id.ulid'),
            'tenant_id.exists' => __('validation.event.tenant_id.exists'),
        ]);
    }
}
