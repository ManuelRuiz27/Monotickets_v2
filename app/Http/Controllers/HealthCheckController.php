<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
        ];

        $allHealthy = collect($checks)->every(static fn (array $check): bool => $check['status'] === 'ok');
        $status = $allHealthy ? 200 : 503;

        return response()->json([
            'status' => $allHealthy ? 'ok' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $status);
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->select('SELECT 1');

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $this->sanitizeMessage($exception),
            ];
        }
    }

    private function checkRedis(): array
    {
        try {
            Redis::connection()->ping();

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $this->sanitizeMessage($exception),
            ];
        }
    }

    private function checkQueue(): array
    {
        try {
            $connection = Queue::connection();

            if (method_exists($connection, 'getConnectionName')) {
                $connection->getConnectionName();
            }

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $this->sanitizeMessage($exception),
            ];
        }
    }

    private function sanitizeMessage(Throwable $exception): string
    {
        return config('app.debug')
            ? $exception->getMessage()
            : 'unavailable';
    }
}
