<?php

namespace App\Http\Params;

use Illuminate\Database\Eloquent\Builder;

/**
 * Value object to handle search queries on list endpoints.
 */
class SearchParam
{
    public function __construct(private readonly string $term)
    {
    }

    public static function fromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $term = trim($value);

        if ($term === '') {
            return null;
        }

        return new self($term);
    }

    /**
     * Apply the search filter to the query for the given columns.
     *
     * @param  array<int, string>  $columns
     */
    public function apply(Builder $query, array $columns): void
    {
        $term = $this->term;

        $query->where(function (Builder $builder) use ($columns, $term): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($column, 'like', "%{$term}%");
            }
        });
    }

    public function term(): string
    {
        return $this->term;
    }
}
