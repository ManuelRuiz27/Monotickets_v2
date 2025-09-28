<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\SnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Precompute frequently accessed dashboard snapshots for an event.
 */
class WarmUpDashboards implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(private readonly string $eventId, private readonly ?int $ttlSeconds = 300)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(SnapshotService $snapshots): void
    {
        /** @var Event|null $event */
        $event = Event::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        $baseParams = [
            'tenant_id' => (string) $event->tenant_id,
            'event_id' => (string) $event->id,
        ];

        if ($this->ttlSeconds !== null) {
            $baseParams['ttl'] = $this->ttlSeconds;
        }

        $snapshots->compute('attendance_by_hour', $baseParams);
        $snapshots->compute('rsvp_funnel', $baseParams);
        $snapshots->compute('checkpoint_totals', $baseParams);
        $snapshots->compute('guests_by_list', $baseParams);
    }
}
