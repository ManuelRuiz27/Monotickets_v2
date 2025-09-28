<?php

namespace App\Http\Requests\Device;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate payload for registering devices.
 */
class DeviceRegisterRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', Rule::in(['web', 'android', 'ios'])],
            'fingerprint' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9+\/=]+$/'],
        ];
    }
}
