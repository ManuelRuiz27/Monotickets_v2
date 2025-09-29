<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Tenants\TenantAnonymizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnonymizeTenantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $tenantId)
    {
    }

    public function handle(TenantAnonymizer $anonymizer): void
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        if ($tenant->status !== 'canceled' && $tenant->status !== 'anonymized') {
            return;
        }

        $alreadyAnonymized = is_array($tenant->settings_json)
            && data_get($tenant->settings_json, 'compliance.anonymized_at') !== null;

        if ($alreadyAnonymized) {
            return;
        }

        $anonymizer->anonymize($tenant);
    }
}
