<?php

namespace App\Http\Params;

use Illuminate\Database\Eloquent\Builder;

/**
 * Value object representing a sortable query parameter.
 */
class SortParam
{
    private function __construct(
        private readonly string $column,
        private readonly string $direction
    ) {
    }

    /**
     * Create a new sort parameter from the request input.
     *
     * @param  array<int, string>  $allowedColumns
     */
    public static function fromString(?string $value, array $allowedColumns, ?self $default = null): self
    {
        if ($default === null) {
            $default = new self($allowedColumns[0] ?? 'id', 'asc');
        }

        if ($value === null) {
            return $default;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $default;
        }

        $direction = 'asc';

        if (str_starts_with($trimmed, '-')) {
            $direction = 'desc';
            $trimmed = substr($trimmed, 1);
        }

        if (! in_array($trimmed, $allowedColumns, true)) {
            return $default;
        }

        return new self($trimmed, $direction);
    }

    public static function asc(string $column): self
    {
        return new self($column, 'asc');
    }

    /**
     * Apply the sort to the query builder.
     */
    public function apply(Builder $query): void
    {
        $query->orderBy($this->column, $this->direction);
    }
}
