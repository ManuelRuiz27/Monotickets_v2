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
    public static function error(string $code, string $message, ?array $details, int $status, ?string $traceId = null): JsonResponse
    {
        $payload = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $payload['details'] = $details;
        }

        if ($traceId !== null) {
            $payload['traceId'] = $traceId;
        }

        return response()->json($payload, $status);
    }
}
