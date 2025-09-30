<?php

namespace App\Logging;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class RequestContextProcessor
{
    public function __invoke(array $record): array
    {
        if (! app()->bound('request')) {
            return $record;
        }

        $request = app('request');

        if (! $request instanceof Request) {
            return $record;
        }

        if (! $this->shouldEnrich($request)) {
            return $record;
        }

        $tenantId = $this->resolveTenantId();
        $userId = $request->user()?->getAuthIdentifier();
        $deviceId = $this->resolveDeviceId($request);

        $context = array_filter([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'device_id' => $deviceId,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($context === []) {
            return $record;
        }

        $record['context'] = array_merge($record['context'] ?? [], $context);

        return $record;
    }

    private function shouldEnrich(Request $request): bool
    {
        return $request->is('api/auth', 'api/auth/*', 'api/scan', 'api/scan/*');
    }

    private function resolveTenantId(): ?string
    {
        if (! app()->bound(TenantContext::class)) {
            return null;
        }

        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);

        return $tenantContext->tenantId();
    }

    private function resolveDeviceId(Request $request): ?string
    {
        $candidates = [
            $request->input('device_id'),
            $request->header('X-Device-ID'),
            $request->header('X-Device-Id'),
        ];

        return Arr::first(array_filter($candidates, static fn ($value) => $value !== null && $value !== ''));
    }
}
