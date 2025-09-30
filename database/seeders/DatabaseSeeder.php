<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestList;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Qr\QrCodeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'DemoPassword123!';

    private int $ticketSequence = 1;

    public function run(): void
    {
        $qrProvider = app(QrCodeProvider::class);
        $now = CarbonImmutable::now();

        $tenant = Tenant::create([
            'name' => 'Monotickets Demo',
            'slug' => 'monotickets-demo',
            'status' => 'active',
            'plan' => 'pro',
            'settings_json' => [
                'timezone' => 'Europe/Madrid',
                'branding' => [
                    'logo_url' => 'https://demo.monotickets.test/assets/logo.png',
                    'colors' => [
                        'primary' => '#0F172A',
                        'accent' => '#38BDF8',
                        'bg' => '#FFFFFF',
                        'text' => '#020617',
                    ],
                    'email_from' => 'no-reply@demo.monotickets.test',
                    'email_reply_to' => 'support@demo.monotickets.test',
                ],
            ],
        ]);

        $superadminRole = Role::create([
            'tenant_id' => null,
            'code' => 'superadmin',
            'name' => 'Super Admin',
            'description' => 'Global platform administrator.',
        ]);

        $organizerRole = Role::create([
            'tenant_id' => $tenant->id,
            'code' => 'organizer',
            'name' => 'Organizer',
            'description' => 'Manages events and configuration.',
        ]);

        $hostessRole = Role::create([
            'tenant_id' => $tenant->id,
            'code' => 'hostess',
            'name' => 'Hostess',
            'description' => 'Handles on-site attendee operations.',
        ]);

        $tenantOwnerRole = Role::create([
            'tenant_id' => $tenant->id,
            'code' => 'tenant_owner',
            'name' => 'Tenant Owner',
            'description' => 'Responsible for billing and tenant settings.',
        ]);

        $superadmin = User::create([
            'tenant_id' => null,
            'name' => 'Super Admin',
            'email' => 'superadmin@demo.test',
            'phone' => '+34 600 000 000',
            'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
            'is_active' => true,
        ]);
        $superadmin->roles()->attach($superadminRole->id, ['tenant_id' => null]);

        $organizers = collect([
            ['name' => 'Laura Martín', 'email' => 'organizer1@demo.test'],
            ['name' => 'Javier Ortega', 'email' => 'organizer2@demo.test'],
        ])->map(function (array $data) use ($tenant, $organizerRole) {
            $organizer = User::factory()->for($tenant)->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
            ]);

            $organizer->roles()->attach($organizerRole->id, ['tenant_id' => $tenant->id]);

            return $organizer;
        });

        $hostess = User::factory()->for($tenant)->create([
            'name' => 'María Recepción',
            'email' => 'hostess@demo.test',
            'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
        ]);
        $hostess->roles()->attach($hostessRole->id, ['tenant_id' => $tenant->id]);

        $tenantOwner = User::factory()->for($tenant)->create([
            'name' => 'Ignacio Gestor',
            'email' => 'owner@demo.test',
            'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
        ]);
        $tenantOwner->roles()->attach($tenantOwnerRole->id, ['tenant_id' => $tenant->id]);

        $upcomingEvent = Event::create([
            'tenant_id' => $tenant->id,
            'organizer_user_id' => $organizers->first()->id,
            'code' => 'LIVE2024',
            'name' => 'Monotickets Live 2024',
            'description' => 'Jornadas presenciales con experiencias interactivas y talleres prácticos.',
            'start_at' => $now->addDays(10)->setTime(9, 0),
            'end_at' => $now->addDays(10)->setTime(18, 0),
            'timezone' => 'Europe/Madrid',
            'status' => 'published',
            'capacity' => 800,
            'checkin_policy' => 'single',
            'settings_json' => [
                'language' => 'es',
                'allow_guest_checkins' => false,
                'mode' => 'live',
            ],
        ]);

        $pastEvent = Event::create([
            'tenant_id' => $tenant->id,
            'organizer_user_id' => $organizers->last()->id,
            'code' => 'SUMMIT2023',
            'name' => 'Summit Monotickets 2023',
            'description' => 'Encuentro profesional con charlas inspiradoras y networking.',
            'start_at' => $now->subDays(45)->setTime(8, 30),
            'end_at' => $now->subDays(45)->setTime(17, 0),
            'timezone' => 'Europe/Madrid',
            'status' => 'archived',
            'capacity' => 650,
            'checkin_policy' => 'multiple',
            'settings_json' => [
                'language' => 'es',
                'allow_guest_checkins' => true,
                'mode' => 'hybrid',
            ],
        ]);

        $this->createEventInfrastructure($upcomingEvent, [
            [
                'name' => 'Auditorio Principal',
                'address' => 'Av. de la Innovación 12, Madrid',
                'lat' => 40.4169,
                'lng' => -3.7035,
                'notes' => 'Acceso por puerta A.',
                'checkpoints' => [
                    ['name' => 'Entrada General', 'description' => 'Control de acceso para asistentes generales.'],
                    ['name' => 'Fast Track VIP', 'description' => 'Acceso prioritario para invitados VIP.'],
                ],
            ],
            [
                'name' => 'Pabellón Talleres',
                'address' => 'Av. de la Innovación 14, Madrid',
                'lat' => 40.4174,
                'lng' => -3.7042,
                'notes' => 'Zona destinada a workshops y demostraciones.',
                'checkpoints' => [
                    ['name' => 'Registro Taller', 'description' => 'Entrega de materiales y control de asistencia.'],
                    ['name' => 'Salida Taller', 'description' => 'Control de salida para recopilar feedback.'],
                ],
            ],
        ]);

        $this->createEventInfrastructure($pastEvent, [
            [
                'name' => 'Centro de Convenciones',
                'address' => 'Plaza Central 8, Barcelona',
                'lat' => 41.3874,
                'lng' => 2.1686,
                'notes' => 'Entrada principal y área de acreditaciones.',
                'checkpoints' => [
                    ['name' => 'Acceso Principal', 'description' => 'Control principal del evento.'],
                    ['name' => 'Control Seguridad', 'description' => 'Verificación adicional para staff y proveedores.'],
                ],
            ],
            [
                'name' => 'Terraza Networking',
                'address' => 'Plaza Central 8, Planta 3, Barcelona',
                'lat' => 41.3878,
                'lng' => 2.169,
                'notes' => 'Espacio reservado para encuentros privados.',
                'checkpoints' => [
                    ['name' => 'Acceso Terraza', 'description' => 'Control de acceso para sesiones de networking.'],
                ],
            ],
        ]);

        $upcomingLists = [
            'general' => $this->createGuestList($upcomingEvent, 'General Live 2024', 'Acceso estándar para asistentes registrados.', [
                'type' => 'general',
                'badge' => 'General',
            ]),
            'vip' => $this->createGuestList($upcomingEvent, 'VIP Experience', 'Programa exclusivo con asientos preferenciales y lounge privado.', [
                'type' => 'vip',
                'benefits' => ['lounge', 'fast_track'],
            ]),
            'staff' => $this->createGuestList($upcomingEvent, 'Staff Operativo', 'Equipo interno y proveedores acreditados.', [
                'type' => 'staff',
            ]),
        ];

        $pastLists = [
            'general' => $this->createGuestList($pastEvent, 'General Summit 2023', 'Participantes confirmados y acompañantes.', [
                'type' => 'general',
                'badge' => 'General',
            ]),
            'vip' => $this->createGuestList($pastEvent, 'VIP Summit', 'Invitados especiales con agenda personalizada.', [
                'type' => 'vip',
                'benefits' => ['meet_and_greet'],
            ]),
            'staff' => $this->createGuestList($pastEvent, 'Equipo Summit', 'Staff interno y soporte logístico.', [
                'type' => 'staff',
            ]),
        ];

        $this->seedUpcomingEvent($upcomingEvent, $upcomingLists, $qrProvider);
        $this->seedPastEvent($pastEvent, $pastLists, $qrProvider);
    }

    /**
     * @param array<int, array<string, mixed>> $venues
     */
    private function createEventInfrastructure(Event $event, array $venues): void
    {
        foreach ($venues as $venueData) {
            $venue = $event->venues()->create([
                'name' => $venueData['name'],
                'address' => $venueData['address'],
                'lat' => $venueData['lat'],
                'lng' => $venueData['lng'],
                'notes' => $venueData['notes'],
            ]);

            $venue->checkpoints()->createMany(array_map(function (array $checkpoint) use ($event): array {
                return [
                    'event_id' => $event->id,
                    'name' => $checkpoint['name'],
                    'description' => $checkpoint['description'],
                ];
            }, $venueData['checkpoints']));
        }
    }

    private function createGuestList(Event $event, string $name, string $description, array $criteria): GuestList
    {
        return $event->guestLists()->create([
            'name' => $name,
            'description' => $description,
            'criteria_json' => $criteria,
        ]);
    }

    private function seedUpcomingEvent(Event $event, array $lists, QrCodeProvider $qrProvider): void
    {
        $baseIssuedAt = $event->start_at->subDays(14);

        for ($i = 1; $i <= 260; $i++) {
            $guest = Guest::create([
                'event_id' => $event->id,
                'guest_list_id' => $lists['general']->id,
                'full_name' => sprintf('Asistente General %03d', $i),
                'email' => sprintf('live-general-%03d@demo.test', $i),
                'phone' => sprintf('+34 610 %06d', $i),
                'rsvp_status' => $i % 9 === 0 ? 'invited' : 'confirmed',
                'rsvp_at' => $i % 9 === 0 ? null : $baseIssuedAt->addDays(intdiv($i, 10)),
                'allow_plus_ones' => $i % 35 === 0,
                'plus_ones_limit' => $i % 35 === 0 ? 1 : 0,
                'custom_fields_json' => [
                    'industry' => $i % 2 === 0 ? 'technology' : 'media',
                ],
            ]);

            $status = $i % 50 === 0 ? 'revoked' : 'issued';
            $price = $i % 15 === 0 ? 9500 : 7500;

            $this->createTicket(
                $event,
                $guest,
                'general',
                $status,
                $baseIssuedAt->addMinutes($i * 3),
                $event->end_at,
                $price,
                $qrProvider
            );
        }

        for ($i = 1; $i <= 40; $i++) {
            $guest = Guest::create([
                'event_id' => $event->id,
                'guest_list_id' => $lists['vip']->id,
                'full_name' => sprintf('Invitado VIP %02d', $i),
                'email' => sprintf('live-vip-%02d@demo.test', $i),
                'organization' => $i % 3 === 0 ? 'Partner Global' : 'Cliente Clave',
                'rsvp_status' => 'confirmed',
                'rsvp_at' => $baseIssuedAt->subDays(1),
                'allow_plus_ones' => true,
                'plus_ones_limit' => 1,
                'custom_fields_json' => [
                    'concierge' => true,
                ],
            ]);

            $this->createTicket(
                $event,
                $guest,
                'vip',
                'issued',
                $baseIssuedAt->addMinutes($i * 5),
                $event->end_at,
                18000,
                $qrProvider
            );

            if ($i % 2 === 0) {
                $this->createTicket(
                    $event,
                    $guest,
                    'general',
                    'issued',
                    $baseIssuedAt->addMinutes($i * 5 + 2),
                    $event->end_at,
                    0,
                    $qrProvider
                );
            }
        }

        for ($i = 1; $i <= 30; $i++) {
            $guest = Guest::create([
                'event_id' => $event->id,
                'guest_list_id' => $lists['staff']->id,
                'full_name' => sprintf('Staff Operativo %02d', $i),
                'email' => sprintf('live-staff-%02d@demo.test', $i),
                'phone' => sprintf('+34 620 %06d', $i),
                'organization' => 'Monotickets',
                'rsvp_status' => 'confirmed',
                'allow_plus_ones' => false,
                'plus_ones_limit' => 0,
                'custom_fields_json' => [
                    'role' => $i % 2 === 0 ? 'accreditation' : 'logistics',
                ],
            ]);

            $status = $i % 6 === 0 ? 'revoked' : 'issued';

            $this->createTicket(
                $event,
                $guest,
                'staff',
                $status,
                $baseIssuedAt->addMinutes($i * 4),
                $event->end_at,
                0,
                $qrProvider
            );
        }
    }

    private function seedPastEvent(Event $event, array $lists, QrCodeProvider $qrProvider): void
    {
        $baseIssuedAt = $event->start_at->subDays(25);

        for ($i = 1; $i <= 180; $i++) {
            $guest = Guest::create([
                'event_id' => $event->id,
                'guest_list_id' => $lists['general']->id,
                'full_name' => sprintf('Summit Asistente %03d', $i),
                'email' => sprintf('summit-general-%03d@demo.test', $i),
                'organization' => $i % 4 === 0 ? 'Startup Local' : 'Empresa Consolidada',
                'rsvp_status' => $i % 12 === 0 ? 'invited' : 'confirmed',
                'rsvp_at' => $baseIssuedAt->subDays(intdiv($i, 12)),
                'allow_plus_ones' => $i % 40 === 0,
                'plus_ones_limit' => $i % 40 === 0 ? 1 : 0,
                'custom_fields_json' => [
                    'interest' => $i % 3 === 0 ? 'networking' : 'content',
                ],
            ]);

            $status = match (true) {
                $i <= 120 => 'used',
                $i <= 150 => 'issued',
                $i <= 170 => 'expired',
                default => 'revoked',
            };

            $this->createTicket(
                $event,
                $guest,
                'general',
                $status,
                $baseIssuedAt->addMinutes($i * 2),
                $event->end_at,
                6800,
                $qrProvider
            );
        }

        for ($i = 1; $i <= 30; $i++) {
            $guest = Guest::create([
                'event_id' => $event->id,
                'guest_list_id' => $lists['vip']->id,
                'full_name' => sprintf('Summit VIP %02d', $i),
                'email' => sprintf('summit-vip-%02d@demo.test', $i),
                'organization' => 'Sponsor Premium',
                'rsvp_status' => 'confirmed',
                'allow_plus_ones' => true,
                'plus_ones_limit' => 1,
                'custom_fields_json' => [
                    'after_party' => $i % 5 !== 0,
                ],
            ]);

            $status = $i <= 22 ? 'used' : 'expired';

            $this->createTicket(
                $event,
                $guest,
                'vip',
                $status,
                $baseIssuedAt->addMinutes($i * 6),
                $event->end_at,
                14500,
                $qrProvider
            );

            if ($i <= 15) {
                $this->createTicket(
                    $event,
                    $guest,
                    'general',
                    'used',
                    $baseIssuedAt->addMinutes($i * 6 + 3),
                    $event->end_at,
                    0,
                    $qrProvider
                );
            }
        }

        for ($i = 1; $i <= 20; $i++) {
            $guest = Guest::create([
                'event_id' => $event->id,
                'guest_list_id' => $lists['staff']->id,
                'full_name' => sprintf('Equipo Summit %02d', $i),
                'email' => sprintf('summit-staff-%02d@demo.test', $i),
                'organization' => 'Monotickets',
                'rsvp_status' => 'confirmed',
                'allow_plus_ones' => false,
                'plus_ones_limit' => 0,
                'custom_fields_json' => [
                    'role' => $i % 2 === 0 ? 'seguridad' : 'coordinación',
                ],
            ]);

            $status = $i <= 15 ? 'used' : 'expired';

            $this->createTicket(
                $event,
                $guest,
                'staff',
                $status,
                $baseIssuedAt->addMinutes($i * 5),
                $event->end_at,
                0,
                $qrProvider
            );
        }
    }

    private function createTicket(
        Event $event,
        Guest $guest,
        string $type,
        string $status,
        CarbonImmutable $issuedAt,
        ?CarbonImmutable $expiresAt,
        int $priceCents,
        QrCodeProvider $qrProvider
    ): Ticket {
        $ticket = Ticket::create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'type' => $type,
            'price_cents' => $priceCents,
            'status' => $status,
            'seat_section' => match ($type) {
                'vip' => 'PLATEA',
                'staff' => 'STAFF',
                default => 'GENERAL',
            },
            'seat_row' => match ($type) {
                'vip' => 'A',
                'staff' => 'S',
                default => 'B',
            },
            'seat_code' => str_pad((string) $this->ticketSequence++, 4, '0', STR_PAD_LEFT),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ]);

        $generated = $qrProvider->generate($ticket);

        $ticket->qr()->create([
            'display_code' => $generated->displayCode,
            'payload' => $generated->payload,
            'version' => 1,
            'is_active' => $status === 'issued',
        ]);

        return $ticket;
    }
}
