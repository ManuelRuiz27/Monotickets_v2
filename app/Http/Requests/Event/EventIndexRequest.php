<?php

namespace App\Http\Requests\Event;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate query parameters for listing events.
 */
class EventIndexRequest extends ApiFormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $status = $this->input('status');

        if (is_string($status)) {
            $parts = array_filter(array_map('trim', explode(',', $status)));
            $this->merge(['status' => $parts]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::in(['draft', 'published', 'archived'])],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
