<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
    }
}
