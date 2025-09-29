<?php

namespace App\Services\Tenants;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantAnonymizer
{
    public function anonymize(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant): void {
            $now = CarbonImmutable::now();

            $this->anonymizeUsers($tenant);
            $this->anonymizeGuests($tenant);
            $this->anonymizeTickets($tenant);
            $this->anonymizeAttendances($tenant);
            $this->anonymizeEvents($tenant);

            $settings = $tenant->settings_json;

            if (! is_array($settings)) {
                $settings = [];
            }

            Arr::set($settings, 'compliance.anonymized_at', $now->toIso8601String());

            $tenant->name = sprintf('Anonymized Tenant %s', Str::upper(Str::substr($tenant->id, 0, 6)));
            $tenant->slug = sprintf('anon-%s', Str::lower($tenant->id));
            $tenant->plan = null;
            $tenant->status = 'anonymized';
            $tenant->settings_json = $settings;
            $tenant->save();
        });
    }

    private function anonymizeUsers(Tenant $tenant): void
    {
        $tenant->users()->withTrashed()->get()->each(function (User $user, int $index): void {
            $user->name = sprintf('Anonymized User %d', $index + 1);
            $user->email = sprintf('anonymized+%s@anon.invalid', Str::lower($user->id));
            $user->phone = null;
            $user->password_hash = Hash::make(Str::random(32));
            $user->save();
        });
    }

    private function anonymizeGuests(Tenant $tenant): void
    {
        Guest::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->each(function (Guest $guest): void {
                $guest->full_name = 'Invitado Anónimo';
                $guest->email = null;
                $guest->phone = null;
                $guest->organization = null;
                $guest->custom_fields_json = null;
                $guest->save();
            });
    }

    private function anonymizeTickets(Tenant $tenant): void
    {
        Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->each(function (Ticket $ticket): void {
                $ticket->seat_section = null;
                $ticket->seat_row = null;
                $ticket->seat_code = null;
                $ticket->save();
            });
    }

    private function anonymizeAttendances(Tenant $tenant): void
    {
        Attendance::query()
            ->where('tenant_id', $tenant->id)
            ->update(['metadata_json' => null]);
    }

    private function anonymizeEvents(Tenant $tenant): void
    {
        Event::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->each(function (Event $event): void {
                $event->name = sprintf('Archived Event %s', Str::upper(Str::substr($event->id, 0, 6)));
                $event->description = null;
                $event->code = Str::upper(Str::random(10));
                $event->status = 'archived';
                $event->save();
            });
    }
}
