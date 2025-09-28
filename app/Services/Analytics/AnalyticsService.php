<?php

namespace App\Services\Analytics;

use App\Models\ActivityMetric;
use App\Models\Attendance;
use App\Models\Guest;
use App\Models\GuestList;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Provide analytics aggregations for events.
 */
class AnalyticsService
{
    /**
     * Aggregate attendance metrics grouped by hour for an event.
     *
     * @param  DateTimeInterface|string|null  $from
     * @param  DateTimeInterface|string|null  $to
     * @return array<int, array<string, mixed>>
     */
    public function attendanceByHour(string $eventId, DateTimeInterface|string|null $from = null, DateTimeInterface|string|null $to = null): array
    {
        $fromHour = $this->normaliseDateBoundary($from)?->startOfHour();
        $toHour = $this->normaliseDateBoundary($to)?->startOfHour();

        $query = ActivityMetric::query()
            ->where('event_id', $eventId)
            ->orderBy('date_hour');

        if ($fromHour !== null) {
            $query->where('date_hour', '>=', $fromHour);
        }

        if ($toHour !== null) {
            $query->where('date_hour', '<=', $toHour);
        }

        return $query
            ->get()
            ->map(function (ActivityMetric $metric): array {
                return [
                    'date_hour' => optional($metric->date_hour)->toISOString(),
                    'invites_sent' => (int) $metric->invites_sent,
                    'rsvp_confirmed' => (int) $metric->rsvp_confirmed,
                    'scans_valid' => (int) $metric->scans_valid,
                    'scans_duplicate' => (int) $metric->scans_duplicate,
                    'unique_guests_in' => (int) $metric->unique_guests_in,
                ];
            })
            ->all();
    }

    /**
     * Calculate the RSVP funnel for an event.
     *
     * @return array<string, int>
     */
    public function rsvpFunnel(string $eventId): array
    {
        /** @var Collection<int, object{rsvp_status: string, aggregate: int}> $rows */
        $rows = Guest::query()
            ->select('rsvp_status', DB::raw('count(*) as aggregate'))
            ->where('event_id', $eventId)
            ->whereIn('rsvp_status', ['invited', 'confirmed', 'declined'])
            ->groupBy('rsvp_status')
            ->get();

        $totals = [
            'invited' => 0,
            'confirmed' => 0,
            'declined' => 0,
        ];

        foreach ($rows as $row) {
            $totals[$row->rsvp_status] = (int) $row->aggregate;
        }

        return $totals;
    }

    /**
     * Aggregate checkpoint totals for the provided event.
     *
     * @param  DateTimeInterface|string|null  $from
     * @param  DateTimeInterface|string|null  $to
     * @return array{totals: array<string, int>, checkpoints: array<int, array{checkpoint_id: ?string, valid: int, duplicate: int, invalid: int}>}
     */
    public function checkpointTotals(string $eventId, DateTimeInterface|string|null $from = null, DateTimeInterface|string|null $to = null): array
    {
        $fromBoundary = $this->normaliseDateBoundary($from);
        $toBoundary = $this->normaliseDateBoundary($to);

        $query = Attendance::query()
            ->select('checkpoint_id', 'result', DB::raw('count(*) as aggregate'))
            ->where('event_id', $eventId)
            ->groupBy('checkpoint_id', 'result');

        if ($fromBoundary !== null) {
            $query->where('scanned_at', '>=', $fromBoundary);
        }

        if ($toBoundary !== null) {
            $query->where('scanned_at', '<=', $toBoundary);
        }

        /** @var Collection<int, object{checkpoint_id: ?string, result: string, aggregate: int}> $rows */
        $rows = $query->get();

        $totals = [
            'valid' => 0,
            'duplicate' => 0,
            'invalid' => 0,
        ];

        $checkpointBuckets = [];

        foreach ($rows as $row) {
            $bucket = $this->bucketForResult($row->result);

            if ($bucket === null) {
                continue;
            }

            $checkpointKey = $row->checkpoint_id ?? '__unassigned__';

            if (! array_key_exists($checkpointKey, $checkpointBuckets)) {
                $checkpointBuckets[$checkpointKey] = [
                    'checkpoint_id' => $row->checkpoint_id !== null ? (string) $row->checkpoint_id : null,
                    'valid' => 0,
                    'duplicate' => 0,
                    'invalid' => 0,
                ];
            }

            $count = (int) $row->aggregate;
            $totals[$bucket] += $count;
            $checkpointBuckets[$checkpointKey][$bucket] += $count;
        }

        $checkpoints = array_values($checkpointBuckets);

        usort($checkpoints, function (array $left, array $right): int {
            $leftId = $left['checkpoint_id'] ?? '';
            $rightId = $right['checkpoint_id'] ?? '';

            return strcmp($leftId, $rightId);
        });

        return [
            'totals' => $totals,
            'checkpoints' => $checkpoints,
        ];
    }

    /**
     * Aggregate guest totals grouped by guest list.
     *
     * @return array{total: int, lists: array<int, array{guest_list_id: ?string, guest_list_name: ?string, guests: int}>}
     */
    public function guestsByList(string $eventId): array
    {
        /** @var Collection<int, object{guest_list_id: ?string, aggregate: int}> $rows */
        $rows = Guest::query()
            ->select('guest_list_id', DB::raw('count(*) as aggregate'))
            ->where('event_id', $eventId)
            ->groupBy('guest_list_id')
            ->get();

        $guestListNames = GuestList::query()
            ->whereIn('id', $rows->pluck('guest_list_id')->filter()->all())
            ->pluck('name', 'id');

        $total = 0;
        $lists = [];

        foreach ($rows as $row) {
            $listId = $row->guest_list_id !== null ? (string) $row->guest_list_id : null;
            $count = (int) $row->aggregate;
            $total += $count;

            $lists[] = [
                'guest_list_id' => $listId,
                'guest_list_name' => $listId !== null ? ($guestListNames[$listId] ?? null) : null,
                'guests' => $count,
            ];
        }

        usort($lists, function (array $left, array $right): int {
            $leftName = $left['guest_list_name'] ?? '';
            $rightName = $right['guest_list_name'] ?? '';

            return strcmp($leftName, $rightName);
        });

        return [
            'total' => $total,
            'lists' => $lists,
        ];
    }

    /**
     * Resolve a result into the expected bucket.
     */
    private function bucketForResult(string $result): ?string
    {
        return match ($result) {
            'valid' => 'valid',
            'duplicate' => 'duplicate',
            'invalid', 'revoked', 'expired' => 'invalid',
            default => null,
        };
    }

    /**
     * @param  DateTimeInterface|string|null  $value
     */
    private function normaliseDateBoundary(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $date = CarbonImmutable::make($value);

        return $date?->utc();
    }
}
