<?php

namespace App\Http\Requests\Import;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate payload for queuing imports.
 */
class ImportStoreRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in(['csv', 'xlsx', 'api'])],
            'file_url' => ['required', 'string', 'max:2048'],
            'mapping' => ['required', 'array', 'min:1'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
            'options' => ['sometimes', 'array'],
            'options.dedupe_by_email' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalise optional values.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);
        $validated['options'] = $validated['options'] ?? [];
        $validated['options']['dedupe_by_email'] = (bool) ($validated['options']['dedupe_by_email'] ?? false);

        return $validated;
    }
}
