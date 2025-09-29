<?php

namespace App\Http\Requests\Tenant;

use App\Http\Requests\ApiFormRequest;

class UpdateBrandingRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'logo_url' => ['sometimes', 'nullable', 'url'],
            'email_from' => ['sometimes', 'nullable', 'email'],
            'email_reply_to' => ['sometimes', 'nullable', 'email'],
            'colors' => ['sometimes', 'array'],
            'colors.primary' => ['sometimes', 'nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'colors.accent' => ['sometimes', 'nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'colors.bg' => ['sometimes', 'nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'colors.text' => ['sometimes', 'nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
        ];
    }
}
