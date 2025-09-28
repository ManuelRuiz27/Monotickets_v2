<?php

namespace App\Support\Logging;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Provides helpers for consistent structured lifecycle logging.
 */
trait StructuredLogging
{
    /**
     * Log a lifecycle event for a domain entity.
     *
     * @param array<string, mixed> $context
     */
    protected function logEntityLifecycle(
        Request $request,
        User $user,
        string $entityType,
        string $entityId,
        string $action,
        string $tenantId,
        array $context = []
    ): void {
        $baseContext = [
            'entity_type' => $entityType,
            'action' => $action,
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'entity_id' => $entityId,
        ];

        $requestId = $this->extractRequestId($request);

        if ($requestId !== null) {
            $baseContext['request_id'] = $requestId;
        }

        Log::info(sprintf('%s.%s', $entityType, $action), array_merge($baseContext, $context));
    }

    /**
     * Log a counter metric associated with an entity lifecycle event.
     */
    protected function logLifecycleMetric(
        Request $request,
        User $user,
        string $metricName,
        string $entityType,
        string $entityId,
        string $tenantId,
        array $context = []
    ): void {
        $baseContext = [
            'metric' => $metricName,
            'entity_type' => $entityType,
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'entity_id' => $entityId,
        ];

        $requestId = $this->extractRequestId($request);

        if ($requestId !== null) {
            $baseContext['request_id'] = $requestId;
        }

        Log::info('metrics.counter', array_merge($baseContext, $context));
    }

    /**
     * Emit a structured log entry for ad-hoc events.
     *
     * @param array<string, mixed> $context
     */
    protected function logStructuredEvent(Request $request, string $event, array $context = []): void
    {
        $requestId = $this->extractRequestId($request);

        if ($requestId !== null) {
            $context = array_merge(['request_id' => $requestId], $context);
        }

        Log::info($event, $context);
    }

    private function extractRequestId(Request $request): ?string
    {
        $requestId = Arr::first(array_filter([
            $request->attributes->get('request_id'),
            $request->attributes->get('requestId'),
            $request->headers->get('X-Request-Id'),
            $request->headers->get('X-Request-ID'),
        ]));

        return $requestId !== null ? (string) $requestId : null;
    }
}
