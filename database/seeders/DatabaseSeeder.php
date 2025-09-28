<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestList;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo-tenant',
            'status' => 'active',
            'plan' => 'pro',
            'settings_json' => [
                'timezone' => 'UTC',
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
            'description' => 'Manages tenant events and configuration.',
        ]);

        $hostessRole = Role::create([
            'tenant_id' => $tenant->id,
            'code' => 'hostess',
            'name' => 'Hostess',
            'description' => 'Supports on-site attendee operations.',
        ]);

        $superadmin = User::create([
            'tenant_id' => null,
            'name' => 'Super Admin',
            'email' => 'superadmin@demo.test',
            'phone' => '+1 555-0000',
            'password_hash' => Hash::make('password'),
            'is_active' => true,
        ]);

        $superadmin->roles()->attach($superadminRole->id, ['tenant_id' => null]);

        $organizers = User::factory()
            ->count(2)
            ->for($tenant)
            ->state(new Sequence(
                ['name' => 'Organizer One', 'email' => 'organizer1@demo.test'],
                ['name' => 'Organizer Two', 'email' => 'organizer2@demo.test'],
            ))
            ->create();

        $organizers->each(function (User $organizer) use ($organizerRole, $tenant) {
            $organizer->roles()->attach($organizerRole->id, ['tenant_id' => $tenant->id]);
        });

        $hostess = User::factory()
            ->for($tenant)
            ->state([
                'name' => 'Hostess Demo',
                'email' => 'hostess@demo.test',
            ])
            ->create();

        $hostess->roles()->attach($hostessRole->id, ['tenant_id' => $tenant->id]);

        $demoEvent = Event::create([
            'tenant_id' => $tenant->id,
            'organizer_user_id' => $organizers->first()->id,
            'code' => 'DEMO2024',
            'name' => 'Demo Experience',
            'description' => 'Evento de demostración para pruebas internas.',
            'start_at' => now()->addDays(7)->setTime(9, 0),
            'end_at' => now()->addDays(7)->setTime(13, 0),
            'timezone' => 'UTC',
            'status' => 'published',
            'capacity' => 250,
            'checkin_policy' => 'single',
            'settings_json' => [
                'language' => 'es',
                'allow_guest_checkins' => false,
            ],
        ]);

        $mainHall = $demoEvent->venues()->create([
            'name' => 'Sala Principal',
            'address' => 'Calle Falsa 123, Ciudad Demo',
            'lat' => 40.416775,
            'lng' => -3.70379,
            'notes' => 'Entrada principal para asistentes generales.',
        ]);

        $vipLounge = $demoEvent->venues()->create([
            'name' => 'Zona VIP',
            'address' => 'Calle Falsa 123, Planta 2, Ciudad Demo',
            'lat' => 40.417,
            'lng' => -3.704,
            'notes' => 'Área exclusiva para invitados especiales.',
        ]);

        $mainHall->checkpoints()->createMany([
            [
                'event_id' => $demoEvent->id,
                'name' => 'Acceso Principal',
                'description' => 'Punto de control para el acceso general.',
            ],
            [
                'event_id' => $demoEvent->id,
                'name' => 'Registro Acreditaciones',
                'description' => 'Entrega de acreditaciones y bienvenida.',
            ],
        ]);

        $vipLounge->checkpoints()->create([
            'event_id' => $demoEvent->id,
            'name' => 'Control VIP',
            'description' => 'Verificación de acceso para invitados VIP.',
        ]);

        $generalGuestList = $demoEvent->guestLists()->create([
            'name' => 'Invitados Generales',
            'description' => 'Lista de invitados confirmados y acompañantes.',
            'criteria_json' => [
                'type' => 'general',
                'notes' => 'Incluye invitados VIP y staff confirmados.',
            ],
        ]);

        $guests = collect([
            [
                'full_name' => 'Ana García',
                'email' => 'ana.garcia@demo.test',
                'phone' => '+34 600 000 001',
                'organization' => 'InnovateX',
                'rsvp_status' => 'confirmed',
                'rsvp_at' => now()->subDays(2),
                'allow_plus_ones' => true,
                'plus_ones_limit' => 2,
                'custom_fields_json' => [
                    'tags' => ['vip'],
                    'language' => 'es',
                ],
            ],
            [
                'full_name' => 'Carlos Pérez',
                'email' => 'carlos.perez@demo.test',
                'phone' => '+34 600 000 002',
                'organization' => 'TechBridge',
                'rsvp_status' => 'invited',
                'rsvp_at' => null,
                'allow_plus_ones' => false,
                'plus_ones_limit' => 0,
                'custom_fields_json' => [
                    'diet' => 'Vegetariano',
                ],
            ],
            [
                'full_name' => 'Lucía Fernández',
                'email' => 'lucia.fernandez@demo.test',
                'phone' => '+34 600 000 003',
                'organization' => 'Creative Minds',
                'rsvp_status' => 'confirmed',
                'rsvp_at' => now()->subDay(),
                'allow_plus_ones' => false,
                'plus_ones_limit' => 0,
                'custom_fields_json' => [
                    'note' => 'Ponente principal',
                ],
            ],
            [
                'full_name' => 'Miguel Torres',
                'email' => 'miguel.torres@demo.test',
                'phone' => '+34 600 000 004',
                'organization' => 'EventCo',
                'rsvp_status' => 'declined',
                'rsvp_at' => now()->subDays(3),
                'allow_plus_ones' => false,
                'plus_ones_limit' => 0,
                'custom_fields_json' => [
                    'reason' => 'Viaje de negocios',
                ],
            ],
            [
                'full_name' => 'Sofía Rojas',
                'email' => 'sofia.rojas@demo.test',
                'phone' => '+34 600 000 005',
                'organization' => 'Future Labs',
                'rsvp_status' => 'invited',
                'rsvp_at' => null,
                'allow_plus_ones' => true,
                'plus_ones_limit' => 1,
                'custom_fields_json' => [
                    'interests' => ['networking'],
                ],
            ],
        ])->map(function (array $guestData) use ($demoEvent, $generalGuestList) {
            return Guest::create(array_merge($guestData, [
                'event_id' => $demoEvent->id,
                'guest_list_id' => $generalGuestList->id,
            ]));
        })->values();

        $baseIssuedAt = now()->subDays(1)->setTime(10, 0);
        $ticketCounter = 1;

        $guests->each(function (Guest $guest, int $index) use ($demoEvent, $baseIssuedAt, &$ticketCounter) {
            $ticketsToCreate = 1;

            if ($index === 0) {
                $ticketsToCreate += $guest->plus_ones_limit;
            }

            for ($i = 0; $i < $ticketsToCreate; $i++) {
                $ticket = Ticket::create([
                    'event_id' => $demoEvent->id,
                    'guest_id' => $guest->id,
                    'type' => $index === 0 && $i === 0 ? 'vip' : 'general',
                    'price_cents' => $index === 0 ? 15000 : 8000,
                    'status' => 'issued',
                    'seat_section' => $index === 0 ? 'PLATEA' : 'BALCÓN',
                    'seat_row' => $index === 0 ? 'A' : 'B',
                    'seat_code' => str_pad((string) $ticketCounter, 2, '0', STR_PAD_LEFT),
                    'issued_at' => $baseIssuedAt->copy()->addMinutes($ticketCounter),
                    'expires_at' => $demoEvent->end_at,
                ]);

                $ticket->qr()->create([
                    'code' => strtoupper(Str::random(16)),
                    'version' => 1,
                    'is_active' => true,
                ]);

                $ticketCounter++;
            }
        });
    }
}
