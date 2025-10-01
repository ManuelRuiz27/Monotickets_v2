<?php

namespace App\Services\Analytics;

use App\Models\ActivityMetric;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestList;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
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
        $timezone = $this->resolveTimezone(
            Event::query()
                ->whereKey($eventId)
                ->value('timezone')
        );

        $fromHour = $this->normaliseUtcBoundary($timezone, $from, true)?->startOfHour();
        $toHour = $this->normaliseUtcBoundary($timezone, $to, false)?->startOfHour();

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
                    'date_hour' => optional($metric->date_hour)?->toIso8601String(),
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
     * Compute the main overview metrics for the provided event.
     *
     * @param  DateTimeInterface|string|null  $from
     * @param  DateTimeInterface|string|null  $to
     * @return array<string, float|int|null>
     */
    public function overview(string $eventId, DateTimeInterface|string|null $from = null, DateTimeInterface|string|null $to = null): array
    {
        $event = Event::query()
            ->select('id', 'capacity', 'timezone')
            ->whereKey($eventId)
            ->first();

        $timezone = $this->resolveTimezone($event?->timezone);

        $fromBoundary = $this->normaliseLocalBoundary($timezone, $from, true);
        $toBoundary = $this->normaliseLocalBoundary($timezone, $to, false);

        $invited = Guest::query()
            ->where('event_id', $eventId)
            ->count();

        $confirmed = Guest::query()
            ->where('event_id', $eventId)
            ->where('rsvp_status', 'confirmed')
            ->count();

        $attendanceQuery = Attendance::query()
            ->where('event_id', $eventId);

        if ($fromBoundary !== null) {
            $attendanceQuery->where('scanned_at', '>=', $fromBoundary);
        }

        if ($toBoundary !== null) {
            $attendanceQuery->where('scanned_at', '<=', $toBoundary);
        }

        $validQuery = (clone $attendanceQuery)->where('result', 'valid');

        $validAttendances = (clone $validQuery)->count();

        $duplicateAttendances = (clone $attendanceQuery)
            ->where('result', 'duplicate')
            ->count();

        $uniqueAttendees = (int) ((clone $validQuery)
            ->whereNotNull('guest_id')
            ->select(DB::raw('count(distinct guest_id) as aggregate'))
            ->value('aggregate') ?? 0);

        $capacity = $event?->capacity;

        $occupancyRate = null;

        if ($capacity !== null && (int) $capacity > 0) {
            $capacity = (int) $capacity;
            $occupancyRate = $uniqueAttendees / $capacity;
        }

        return [
            'invited' => (int) $invited,
            'confirmed' => (int) $confirmed,
            'attendances' => (int) $validAttendances,
            'duplicates' => (int) $duplicateAttendances,
            'unique_attendees' => (int) $uniqueAttendees,
            'occupancy_rate' => $occupancyRate,
        ];
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
        $timezone = $this->resolveTimezone(
            Event::query()
                ->whereKey($eventId)
                ->value('timezone')
        );

        $fromBoundary = $this->normaliseLocalBoundary($timezone, $from, true);
        $toBoundary = $this->normaliseLocalBoundary($timezone, $to, false);

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
    private function normaliseUtcBoundary(string $timezone, DateTimeInterface|string|null $value, bool $isStart): ?CarbonImmutable
    {
        $date = $this->makeBoundaryDate($timezone, $value, $isStart);

        return $date?->setTimezone('UTC');
    }

    private function normaliseLocalBoundary(string $timezone, DateTimeInterface|string|null $value, bool $isStart): ?CarbonImmutable
    {
        return $this->makeBoundaryDate($timezone, $value, $isStart);
    }

    private function makeBoundaryDate(string $timezone, DateTimeInterface|string|null $value, bool $isStart): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($timezone === '') {
            $timezone = 'UTC';
        }

        $date = is_string($value)
            ? CarbonImmutable::parse($value, $timezone)
            : CarbonImmutable::make($value);

        if ($date === null) {
            return null;
        }

        $date = $date->setTimezone($timezone);

        return $isStart
            ? $date->startOfMinute()
            : $date->endOfMinute();
    }

    private function resolveTimezone(?string $timezone): string
    {
        if ($timezone === null || $timezone === '') {
            return 'UTC';
        }

        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (\Exception $exception) {
            return 'UTC';
        }
    }
}
