<?php

namespace App\Services\Qr;

use App\Models\Qr;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use JsonException;
use RuntimeException;

class SecureQrCodeProvider implements QrCodeProvider
{
    private const DISPLAY_PREFIX = 'MT';
    private const DISPLAY_BLOCK_SIZE = 4;
    private const DISPLAY_MAX_ATTEMPTS = 25;
    private const NONCE_BYTES = 10;

    /**
     * Characters used for generating human friendly display codes.
     */
    private const DISPLAY_CHARSET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(private readonly string $secret)
    {
        if ($this->secret === '') {
            throw new RuntimeException('QR secret is not configured.');
        }
    }

    public function generate(Ticket $ticket): GeneratedQrCode
    {
        $displayCode = $this->generateUniqueDisplayCode();
        $payload = $this->buildPayload($ticket, $displayCode);

        return new GeneratedQrCode($payload, $displayCode);
    }

    public function verify(string $payload): bool
    {
        [$encodedData, $encodedSignature] = $this->splitPayload($payload);

        $data = $this->decodeBase64Url($encodedData);
        $signature = $this->decodeBase64Url($encodedSignature);

        if ($data === null || $signature === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $data, $this->secret, true);

        return hash_equals($expected, $signature);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decode(string $payload): ?array
    {
        [$encodedData, $encodedSignature] = $this->splitPayload($payload);
        $data = $this->decodeBase64Url($encodedData);
        $signature = $this->decodeBase64Url($encodedSignature);

        if ($data === null || $signature === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $data, $this->secret, true);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return $decoded;
    }

    private function buildPayload(Ticket $ticket, string $displayCode): string
    {
        $issuedAt = $ticket->issued_at;

        if ($issuedAt !== null && ! $issuedAt instanceof Carbon) {
            $issuedAt = Carbon::parse($issuedAt);
        }

        $expiresAt = $ticket->expires_at;

        if ($expiresAt !== null && ! $expiresAt instanceof Carbon) {
            $expiresAt = Carbon::parse($expiresAt);
        }

        $payload = [
            'ver' => 1,
            'tid' => (string) $ticket->id,
            'eid' => (string) $ticket->event_id,
            'did' => $displayCode,
            'tenant' => $ticket->tenant_id !== null ? (string) $ticket->tenant_id : null,
            'iat' => $issuedAt?->toIso8601String(),
            'exp' => $expiresAt?->toIso8601String(),
            'nonce' => $this->generateNonce(),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, $this->secret, true);

        return sprintf('%s.%s', $this->encodeBase64Url($json), $this->encodeBase64Url($signature));
    }

    private function generateUniqueDisplayCode(): string
    {
        for ($attempt = 0; $attempt < self::DISPLAY_MAX_ATTEMPTS; $attempt++) {
            $candidate = sprintf('%s-%s-%s', self::DISPLAY_PREFIX, $this->randomBlock(), $this->randomBlock());

            $exists = Qr::withTrashed()->where('display_code', $candidate)->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique QR display code.');
    }

    private function randomBlock(): string
    {
        $characters = self::DISPLAY_CHARSET;
        $length = strlen($characters);
        $result = '';

        for ($i = 0; $i < self::DISPLAY_BLOCK_SIZE; $i++) {
            $index = random_int(0, $length - 1);
            $result .= $characters[$index];
        }

        return $result;
    }

    private function generateNonce(): string
    {
        return $this->encodeBase64Url(random_bytes(self::NONCE_BYTES));
    }

    private function encodeBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decodeBase64Url(string $value): ?string
    {
        $padding = 4 - (strlen($value) % 4);

        if ($padding < 4) {
            $value .= str_repeat('=', $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPayload(string $payload): array
    {
        $parts = explode('.', $payload, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return ['', ''];
        }

        return [$parts[0], $parts[1]];
    }
}
