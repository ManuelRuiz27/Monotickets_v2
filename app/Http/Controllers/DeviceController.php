<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Requests\Device\DeviceRegisterRequest;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Handle device registration and tracking operations.
 */
class DeviceController extends Controller
{
    use InteractsWithTenants;

    /**
     * Register a device for the current tenant context.
     */
    public function register(DeviceRegisterRequest $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $authUser->loadMissing('roles');

        $tenantId = $this->resolveTenantContext($request, $authUser);

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Unable to determine tenant context for device registration.'],
            ]);
        }

        $payload = $request->validated();
        $fingerprintHash = hash('sha256', $payload['fingerprint']);

        $device = Device::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'fingerprint' => $fingerprintHash,
            ],
            [
                'tenant_id' => $tenantId,
                'name' => $payload['name'],
                'platform' => $payload['platform'],
                'last_seen_at' => now(),
                'is_active' => true,
            ]
        );

        $wasRecentlyCreated = $device->wasRecentlyCreated;
        $device->refresh();

        return response()->json([
            'data' => $this->formatDevice($device),
        ], $wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Format a device payload for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatDevice(Device $device): array
    {
        return [
            'id' => (string) $device->id,
            'tenant_id' => (string) $device->tenant_id,
            'name' => $device->name,
            'platform' => $device->platform,
            'fingerprint' => $device->fingerprint,
            'last_seen_at' => $device->last_seen_at?->toAtomString(),
            'is_active' => (bool) $device->is_active,
            'created_at' => $device->created_at?->toAtomString(),
            'updated_at' => $device->updated_at?->toAtomString(),
        ];
    }
}
