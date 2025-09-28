<?php

namespace App\Jobs;

use App\Models\ActivityMetric;
use App\Models\Attendance;
use App\Models\Guest;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Consolidate raw attendance and RSVP data into hourly buckets.
 */
class AggregateActivityHourly implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /**
         * @var array<string, array<string, array{date_hour: CarbonImmutable, invites_sent: int, rsvp_confirmed: int, scans_valid: int, scans_duplicate: int, unique_ticket_ids: array<string, bool>}>> $metrics
         */
        $metrics = [];

        $this->aggregateAttendances($metrics);
        $this->aggregateGuests($metrics);
        $this->storeBuckets($metrics);
    }

    /**
     * @param  array<string, array<string, array{date_hour: CarbonImmutable, invites_sent: int, rsvp_confirmed: int, scans_valid: int, scans_duplicate: int, unique_ticket_ids: array<string, bool>}>>  $metrics
     */
    private function aggregateAttendances(array &$metrics): void
    {
        Attendance::query()
            ->select(['event_id', 'ticket_id', 'result', 'scanned_at'])
            ->orderBy('event_id')
            ->orderBy('scanned_at')
            ->cursor()
            ->each(function (Attendance $attendance) use (&$metrics): void {
                $scannedAt = CarbonImmutable::make($attendance->scanned_at);

                if ($scannedAt === null) {
                    return;
                }

                $hour = $scannedAt->utc()->startOfHour();
                $bucket =& $this->bucketForHour($metrics, (string) $attendance->event_id, $hour);

                if ($attendance->result === 'valid') {
                    $bucket['scans_valid']++;
                } elseif ($attendance->result === 'duplicate') {
                    $bucket['scans_duplicate']++;
                }

                $bucket['unique_ticket_ids'][(string) $attendance->ticket_id] = true;
            });
    }

    /**
     * @param  array<string, array<string, array{date_hour: CarbonImmutable, invites_sent: int, rsvp_confirmed: int, scans_valid: int, scans_duplicate: int, unique_ticket_ids: array<string, bool>}>>  $metrics
     */
    private function aggregateGuests(array &$metrics): void
    {
        Guest::query()
            ->select(['event_id', 'created_at', 'rsvp_status', 'rsvp_at'])
            ->orderBy('event_id')
            ->orderBy('created_at')
            ->cursor()
            ->each(function (Guest $guest) use (&$metrics): void {
                $createdAt = CarbonImmutable::make($guest->created_at);

                if ($createdAt !== null) {
                    $hour = $createdAt->utc()->startOfHour();
                    $bucket =& $this->bucketForHour($metrics, (string) $guest->event_id, $hour);
                    $bucket['invites_sent']++;
                }

                if ($guest->rsvp_status !== 'confirmed') {
                    return;
                }

                $rsvpAt = CarbonImmutable::make($guest->rsvp_at);

                if ($rsvpAt === null) {
                    return;
                }

                $hour = $rsvpAt->utc()->startOfHour();
                $bucket =& $this->bucketForHour($metrics, (string) $guest->event_id, $hour);
                $bucket['rsvp_confirmed']++;
            });
    }

    /**
     * @param  array<string, array<string, array{date_hour: CarbonImmutable, invites_sent: int, rsvp_confirmed: int, scans_valid: int, scans_duplicate: int, unique_ticket_ids: array<string, bool>}>>  $metrics
     */
    private function storeBuckets(array $metrics): void
    {
        foreach ($metrics as $eventId => $hours) {
            foreach ($hours as $bucket) {
                $uniqueGuests = count($bucket['unique_ticket_ids']);

                ActivityMetric::query()->updateOrCreate(
                    [
                        'event_id' => $eventId,
                        'date_hour' => $bucket['date_hour'],
                    ],
                    [
                        'invites_sent' => $bucket['invites_sent'],
                        'rsvp_confirmed' => $bucket['rsvp_confirmed'],
                        'scans_valid' => $bucket['scans_valid'],
                        'scans_duplicate' => $bucket['scans_duplicate'],
                        'unique_guests_in' => $uniqueGuests,
                    ],
                );
            }
        }
    }

    /**
     * @param  array<string, array<string, array{date_hour: CarbonImmutable, invites_sent: int, rsvp_confirmed: int, scans_valid: int, scans_duplicate: int, unique_ticket_ids: array<string, bool>}>>  $metrics
     * @return array{date_hour: CarbonImmutable, invites_sent: int, rsvp_confirmed: int, scans_valid: int, scans_duplicate: int, unique_ticket_ids: array<string, bool>}
     */
    private function &bucketForHour(array &$metrics, string $eventId, CarbonImmutable $hour): array
    {
        $hourKey = $hour->toIso8601String();

        if (! array_key_exists($eventId, $metrics)) {
            $metrics[$eventId] = [];
        }

        if (! array_key_exists($hourKey, $metrics[$eventId])) {
            $metrics[$eventId][$hourKey] = [
                'date_hour' => $hour,
                'invites_sent' => 0,
                'rsvp_confirmed' => 0,
                'scans_valid' => 0,
                'scans_duplicate' => 0,
                'unique_ticket_ids' => [],
            ];
        }

        return $metrics[$eventId][$hourKey];
    }
}
