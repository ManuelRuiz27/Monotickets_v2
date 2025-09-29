<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\Exceptions\BillingPeriodAlreadyClosedException;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CloseBillingPeriodJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 5;

    public function __construct(private readonly string $subscriptionId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(BillingService $billingService): void
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->with('plan')->find($this->subscriptionId);

        if ($subscription === null) {
            return;
        }

        $periodEnd = $subscription->current_period_end instanceof CarbonImmutable
            ? $subscription->current_period_end
            : CarbonImmutable::parse((string) $subscription->current_period_end);

        if ($periodEnd->isFuture()) {
            return;
        }

        $startedAt = microtime(true);

        try {
            $invoice = $billingService->closePeriod($subscription);
        } catch (BillingPeriodAlreadyClosedException $exception) {
            $invoice = $exception->invoice();
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::info('metrics.distribution', [
                'metric' => 'invoice_generation_time_ms',
                'tenant_id' => (string) $subscription->tenant_id,
                'invoice_id' => (string) $invoice->id,
                'value' => $durationMs,
                'trigger' => 'cron',
                'status' => 'already_closed',
            ]);

            return;
        }

        $invoice->loadMissing('payments');

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('metrics.distribution', [
            'metric' => 'invoice_generation_time_ms',
            'tenant_id' => (string) $invoice->tenant_id,
            'invoice_id' => (string) $invoice->id,
            'value' => $durationMs,
            'trigger' => 'cron',
            'status' => $invoice->wasRecentlyCreated ? 'created' : 'existing',
        ]);

        AuditLog::create([
            'tenant_id' => $invoice->tenant_id,
            'user_id' => null,
            'entity' => 'invoice',
            'entity_id' => (string) $invoice->id,
            'action' => 'closed',
            'diff_json' => [
                'status' => $invoice->status,
                'trigger' => 'cron',
                'created' => $invoice->wasRecentlyCreated,
            ],
            'ip' => null,
            'ua' => null,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
