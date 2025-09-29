<?php

namespace App\Console\Commands;

use App\Jobs\CloseBillingPeriodJob;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CloseBillingPeriodsCommand extends Command
{
    protected $signature = 'billing:close-periods {--force : Dispatch closures even if the billing period has not ended yet.}';

    protected $description = 'Queue jobs to close billing periods for active subscriptions.';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $force = $this->option('force') === true;
        $dispatched = 0;

        Subscription::query()
            ->active()
            ->when(! $force, fn ($query) => $query->where('current_period_end', '<=', $now))
            ->chunk(100, function ($subscriptions) use (&$dispatched): void {
                foreach ($subscriptions as $subscription) {
                    CloseBillingPeriodJob::dispatch((string) $subscription->id);
                    $dispatched++;
                }
            });

        Log::info('billing.period_closure_scheduled', [
            'count' => $dispatched,
            'forced' => $force,
        ]);

        $this->info(sprintf('Queued %d billing closure jobs.', $dispatched));

        return self::SUCCESS;
    }
}
