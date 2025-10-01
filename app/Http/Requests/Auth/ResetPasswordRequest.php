<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * Validate password reset submissions.
 */
class ResetPasswordRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'min:6', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
