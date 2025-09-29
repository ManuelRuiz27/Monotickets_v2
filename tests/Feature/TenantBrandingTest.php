<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class TenantBrandingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    public function test_organizer_can_view_branding_settings(): void
    {
        $tenant = Tenant::factory()->create([
            'settings_json' => [
                'timezone' => 'UTC',
                'branding' => [
                    'logo_url' => 'https://assets.example.com/logo.png',
                    'colors' => [
                        'primary' => '#123456',
                        'accent' => '#654321',
                        'bg' => '#FFFFFF',
                        'text' => '#000000',
                    ],
                    'email_from' => 'brand@example.com',
                    'email_reply_to' => 'support@example.com',
                ],
            ],
        ]);

        $organizer = $this->createOrganizer($tenant);

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->getJson('/tenants/me/branding');

        $response->assertOk();
        $response->assertJsonPath('data.logo_url', 'https://assets.example.com/logo.png');
        $response->assertJsonPath('data.colors.primary', '#123456');
        $response->assertJsonPath('data.email_from', 'brand@example.com');
        $response->assertJsonPath('data.email_reply_to', 'support@example.com');
    }

    public function test_organizer_can_update_branding_settings(): void
    {
        $tenant = Tenant::factory()->create([
            'settings_json' => [
                'timezone' => 'UTC',
                'branding' => [
                    'logo_url' => 'https://assets.example.com/old-logo.png',
                    'colors' => [
                        'primary' => '#111111',
                        'accent' => '#222222',
                        'bg' => '#333333',
                        'text' => '#444444',
                    ],
                    'email_from' => 'old@example.com',
                    'email_reply_to' => 'reply@example.com',
                ],
            ],
        ]);

        $organizer = $this->createOrganizer($tenant);

        $payload = [
            'logo_url' => 'https://cdn.example.com/new-logo.png',
            'colors' => [
                'accent' => '#ABCDEF',
                'text' => null,
            ],
            'email_reply_to' => 'helpdesk@example.com',
        ];

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->patchJson('/tenants/me/branding', $payload);

        $response->assertOk();
        $response->assertJsonPath('data.logo_url', 'https://cdn.example.com/new-logo.png');
        $response->assertJsonPath('data.colors.primary', '#111111');
        $response->assertJsonPath('data.colors.accent', '#ABCDEF');
        $response->assertJsonPath('data.colors.text', null);
        $response->assertJsonPath('data.email_reply_to', 'helpdesk@example.com');

        $tenant->refresh();
        $this->assertSame('https://cdn.example.com/new-logo.png', $tenant->branding()['logo_url']);
        $this->assertSame('#111111', $tenant->branding()['colors']['primary']);
        $this->assertSame('#ABCDEF', $tenant->branding()['colors']['accent']);
        $this->assertNull($tenant->branding()['colors']['text']);
        $this->assertSame('helpdesk@example.com', $tenant->branding()['email_reply_to']);
    }

    public function test_invalid_color_format_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->patchJson('/tenants/me/branding', [
                'colors' => [
                    'primary' => 'blue',
                ],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['colors.primary']);
    }
}
