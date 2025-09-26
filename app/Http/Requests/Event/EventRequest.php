<?php

namespace App\Http\Requests\Event;

use App\Http\Requests\ApiFormRequest;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Shared validation logic for event requests.
 */
abstract class EventRequest extends ApiFormRequest
{
    /**
     * @var array<int, string>
     */
    protected const STATUS_OPTIONS = ['draft', 'published', 'archived'];

    /**
     * @var array<int, string>
     */
    protected const CHECKIN_POLICY_OPTIONS = ['single', 'multiple'];

    /**
     * Build the validation rules for an event payload.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function eventRules(bool $partial, ?string $eventId = null): array
    {
        $required = $partial ? ['sometimes'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'organizer_user_id' => array_merge($required, ['string', 'ulid', Rule::exists('users', 'id')]),
            'code' => array_merge($required, ['string', 'max:100', $this->uniqueCodeRule($eventId)]),
            'name' => array_merge($required, ['string', 'max:255']),
            'description' => array_merge($optional, ['string']),
            'start_at' => array_merge($required, ['date']),
            'end_at' => array_merge($required, ['date']),
            'timezone' => array_merge($required, ['timezone:all']),
            'status' => array_merge($required, [Rule::in(self::STATUS_OPTIONS)]),
            'capacity' => array_merge($optional, ['integer', 'min:0']),
            'checkin_policy' => array_merge($required, [Rule::in(self::CHECKIN_POLICY_OPTIONS)]),
            'settings_json' => array_merge($optional, ['array']),
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organizer_user_id.required' => __('validation.event.organizer_user_id.required'),
            'organizer_user_id.ulid' => __('validation.event.organizer_user_id.ulid'),
            'organizer_user_id.exists' => __('validation.event.organizer_user_id.exists'),
            'code.required' => __('validation.event.code.required'),
            'code.max' => __('validation.event.code.max'),
            'code.unique' => __('validation.event.code.unique'),
            'name.required' => __('validation.event.name.required'),
            'timezone.timezone' => __('validation.event.timezone'),
            'start_at.required' => __('validation.event.start_at.required'),
            'start_at.date' => __('validation.event.start_at.date'),
            'end_at.required' => __('validation.event.end_at.required'),
            'end_at.date' => __('validation.event.end_at.date'),
            'status.required' => __('validation.event.status.required'),
            'status.in' => __('validation.event.status.in'),
            'capacity.min' => __('validation.event.capacity.min'),
            'capacity.integer' => __('validation.event.capacity.integer'),
            'checkin_policy.required' => __('validation.event.checkin_policy.required'),
            'checkin_policy.in' => __('validation.event.checkin_policy.in'),
        ];
    }

    /**
     * Ensure the end date occurs after the start date when both are present.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $startAt = $this->input('start_at');
            $endAt = $this->input('end_at');

            if ($startAt === null || $endAt === null) {
                return;
            }

            try {
                $start = CarbonImmutable::parse($startAt);
                $end = CarbonImmutable::parse($endAt);
            } catch (Throwable $exception) {
                return;
            }

            if ($start->gte($end)) {
                $validator->errors()->add('end_at', __('validation.event.end_at.after'));
            }
        });
    }

    /**
     * Build the unique rule for the event code scoped by tenant.
     */
    protected function uniqueCodeRule(?string $ignoreId = null): Rule
    {
        $tenantId = $this->tenantIdForValidation();

        $rule = Rule::unique('events', 'code')
            ->where(function ($query) use ($tenantId) {
                if ($tenantId === null) {
                    $query->whereNull('tenant_id');
                } else {
                    $query->where('tenant_id', $tenantId);
                }

                return $query;
            });

        if ($ignoreId !== null) {
            $rule->ignore($ignoreId);
        }

        return $rule;
    }

    /**
     * Determine the tenant context for scoping validation rules.
     */
    private function tenantIdForValidation(): ?string
    {
        $user = $this->user();

        if ($user !== null) {
            $user->loadMissing('roles');
            $isSuperAdmin = $user->roles->contains(fn (Role $role): bool => $role->code === 'superadmin');

            if ($isSuperAdmin) {
                if ($this->filled('tenant_id')) {
                    return (string) $this->input('tenant_id');
                }

                $headerTenant = $this->header('X-Tenant-ID');

                if ($headerTenant !== null && $headerTenant !== '') {
                    return (string) $headerTenant;
                }
            }
        }

        $configuredTenant = Config::get('tenant.id');

        if ($configuredTenant === null) {
            $headerTenant = $this->header('X-Tenant-ID');

            if ($headerTenant !== null && $headerTenant !== '') {
                return (string) $headerTenant;
            }
        }

        if ($configuredTenant !== null) {
            return (string) $configuredTenant;
        }

        return $user?->tenant_id !== null ? (string) $user->tenant_id : null;
    }
}
