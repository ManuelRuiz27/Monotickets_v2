<?php

namespace App\Console\Commands;

use App\Jobs\AnonymizeTenantJob;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class AnonymizeCanceledTenantsCommand extends Command
{
    protected $signature = 'tenants:anonymize-canceled';

    protected $description = 'Queue anonymization jobs for tenants canceled beyond the retention window.';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now()->subDays((int) config('tenancy.anonymize_canceled_after_days', 30));

        $processed = 0;

        Tenant::query()
            ->where('status', 'canceled')
            ->where('updated_at', '<=', $cutoff)
            ->where(function ($query): void {
                $query->whereNull('settings_json');
                $query->orWhereNull('settings_json->compliance->anonymized_at');
            })
            ->chunk(100, function ($tenants) use (&$processed): void {
                foreach ($tenants as $tenant) {
                    Bus::dispatch(new AnonymizeTenantJob((string) $tenant->id));
                    $processed++;
                }
            });

        Log::info('tenants.anonymize_scheduled', [
            'count' => $processed,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        $this->info(sprintf('Queued anonymization for %d tenants.', $processed));

        return self::SUCCESS;
    }
}
