<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Helper for building consistent API responses.
 */
class ApiResponse
{
    /**
     * Create a paginated JSON response.
     *
     * @param  array<int, mixed>  $data
     * @param  array{page:int,per_page:int,total:int,total_pages:int}  $meta
     */
    public static function paginate(array $data, array $meta): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $meta['page'],
                'per_page' => $meta['per_page'],
                'total' => $meta['total'],
                'total_pages' => $meta['total_pages'],
            ],
        ]);
    }

    /**
     * Create a JSON error response.
     *
     * @param  array<string, mixed>|null  $details
     */
    public static function error(string $code, string $message, ?array $details, int $status): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ], static fn ($value) => $value !== null),
        ], $status);
    }
}
