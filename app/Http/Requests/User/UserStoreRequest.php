<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;
use App\Models\Role;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

/**
 * Validate incoming data for creating users.
 */
class UserStoreRequest extends ApiFormRequest
{
    private const ROLE_OPTIONS = ['superadmin', 'organizer', 'hostess'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', $this->uniqueEmailRule()],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(self::ROLE_OPTIONS)],
            'tenant_id' => ['sometimes', 'nullable', 'ulid', 'exists:tenants,id'],
        ];
    }

    /**
     * Retrieve the validated input data and normalise boolean values.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if (array_key_exists('is_active', $validated)) {
            $validated['is_active'] = (bool) $validated['is_active'];
        }

        return $validated;
    }

    /**
     * Build the unique rule for the email address scoped by tenant.
     */
    private function uniqueEmailRule(): Rule
    {
        $tenantId = $this->tenantIdForValidation();

        return Rule::unique('users', 'email')->where(function ($query) use ($tenantId) {
            if ($tenantId === null) {
                $query->whereNull('tenant_id');
            } else {
                $query->where('tenant_id', $tenantId);
            }

            return $query;
        });
    }

    /**
     * Determine the tenant context to scope validation rules.
     */
    private function tenantIdForValidation(): ?string
    {
        $user = $this->user();

        if ($user !== null) {
            $user->loadMissing('roles');

            $isSuperAdmin = $user->roles->contains(fn (Role $role): bool => $role->code === 'superadmin');

            if ($isSuperAdmin) {
                if ($this->filled('tenant_id')) {
                    return $this->string('tenant_id')->toString();
                }

                $headerTenant = $this->header('X-Tenant-ID');

                if ($headerTenant !== null && $headerTenant !== '') {
                    return (string) $headerTenant;
                }
            }
        }

        $tenantId = Config::get('tenant.id');

        if ($tenantId === null) {
            $headerTenant = $this->header('X-Tenant-ID');

            if ($headerTenant !== null && $headerTenant !== '') {
                return (string) $headerTenant;
            }
        }

        if ($tenantId !== null) {
            return (string) $tenantId;
        }

        return $user?->tenant_id !== null ? (string) $user->tenant_id : null;
    }
}

