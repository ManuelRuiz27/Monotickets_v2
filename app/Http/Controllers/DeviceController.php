<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Requests\Device\DeviceRegisterRequest;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

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

        try {
            $fingerprintPlain = $this->decryptFingerprint($payload['fingerprint']);
        } catch (Throwable $exception) {
            report($exception);

            $this->throwValidationException([
                'fingerprint' => ['No se pudo procesar el identificador del dispositivo.'],
            ]);
        }

        $fingerprintHash = hash('sha256', $fingerprintPlain);
        sodium_memzero($fingerprintPlain);

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

    /**
     * Decrypt a fingerprint payload using the configured symmetric key.
     */
    private function decryptFingerprint(string $payload): string
    {
        $keyEncoded = config('fingerprint.encryption_key');
        if (!is_string($keyEncoded) || $keyEncoded === '') {
            throw new RuntimeException('Fingerprint encryption key is not configured.');
        }

        $key = base64_decode($keyEncoded, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Fingerprint encryption key has an invalid length.');
        }

        $decoded = base64_decode($payload, true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Fingerprint payload is invalid.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $fingerprint = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        sodium_memzero($ciphertext);
        sodium_memzero($key);

        if ($fingerprint === false) {
            throw new RuntimeException('Unable to decrypt fingerprint payload.');
        }

        return $fingerprint;
    }
}
