<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Qr;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Qr\QrCodeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecureQrCodeProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_produces_unique_display_codes_for_large_dataset(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = User::factory()->for($tenant)->create();
        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        $provider = app(QrCodeProvider::class);
        $codes = [];

        for ($i = 0; $i < 520; $i++) {
            $guest = Guest::query()->create([
                'event_id' => $event->id,
                'full_name' => sprintf('Guest %03d', $i + 1),
                'email' => sprintf('guest%03d@example.test', $i + 1),
            ]);

            $ticket = Ticket::query()->create([
                'event_id' => $event->id,
                'guest_id' => $guest->id,
                'type' => 'general',
                'status' => 'issued',
                'price_cents' => 0,
                'seat_section' => 'GEN',
                'seat_row' => 'A',
                'seat_code' => str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'issued_at' => now()->subMinutes($i + 1),
            ]);

            $generated = $provider->generate($ticket);

            Qr::query()->create([
                'ticket_id' => $ticket->id,
                'display_code' => $generated->displayCode,
                'payload' => $generated->payload,
                'version' => 1,
                'is_active' => true,
            ]);

            $codes[] = $generated->displayCode;
        }

        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function test_payload_signature_is_valid_and_detects_tampering(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = User::factory()->for($tenant)->create();
        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Signature Guest',
            'email' => 'signature@example.test',
        ]);

        $ticket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'type' => 'vip',
            'status' => 'issued',
            'price_cents' => 15000,
            'issued_at' => now()->subHour(),
            'expires_at' => $event->end_at,
        ]);

        $provider = app(QrCodeProvider::class);
        $generated = $provider->generate($ticket);

        $this->assertMatchesRegularExpression('/^MT-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $generated->displayCode);
        $this->assertTrue($provider->verify($generated->payload));

        $decoded = $provider->decode($generated->payload);
        $this->assertIsArray($decoded);
        $this->assertSame($ticket->id, $decoded['tid'] ?? null);
        $this->assertSame($generated->displayCode, $decoded['did'] ?? null);

        $parts = explode('.', $generated->payload, 2);
        $tampered = sprintf('%s.%s', Str::reverse($parts[0]), $parts[1]);

        $this->assertFalse($provider->verify($tampered));
        $this->assertNull($provider->decode($tampered));
    }
}
