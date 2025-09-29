<?php

namespace App\Http\Requests\Admin\Tenant;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate payload for updating tenants via administrator endpoints.
 */
class TenantUpdateRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = $this->route('tenant');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('tenants', 'slug')->ignore($tenantId),
            ],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            'plan_id' => ['sometimes', 'string', 'exists:plans,id'],
            'subscription_status' => ['sometimes', 'string', 'in:trialing,active,paused,canceled'],
            'cancel_at_period_end' => ['sometimes', 'boolean'],
            'trial_end' => ['sometimes', 'nullable', 'date'],
            'limit_overrides' => ['sometimes', 'nullable', 'array'],
            'limit_overrides.*' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
