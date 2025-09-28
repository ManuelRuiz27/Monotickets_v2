<?php

namespace App\Http\Requests\Import;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate query params for listing import rows.
 */
class ImportRowIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(['ok', 'failed'])],
        ];
    }
}
