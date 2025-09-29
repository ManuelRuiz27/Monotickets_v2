<?php

namespace App\Http\Requests\Admin\Tenant;

use App\Http\Requests\ApiFormRequest;

/**
 * Validate payload for creating administrator-managed tenants.
 */
class TenantStoreRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:tenants,slug'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'plan_id' => ['required', 'string', 'exists:plans,id'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'limit_overrides' => ['nullable', 'array'],
            'limit_overrides.*' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
