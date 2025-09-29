<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Tenant;
use App\Services\Analytics\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use function optional;

/**
 * Provide aggregated analytics for administrators across events.
 */
class AdminAnalyticsController extends Controller
{
    /**
     * Return analytics cards for events, optionally filtered by tenant and date range.
     */
    public function index(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $validated = $this->validate($request, [
            'tenant_id' => ['nullable', 'string', 'exists:tenants,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $tenantId = $validated['tenant_id'] ?? null;
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : null;
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : null;

        $eventsQuery = Event::query()
            ->select(['id', 'tenant_id', 'name', 'start_at', 'end_at', 'timezone', 'status'])
            ->orderByDesc('start_at');

        if ($tenantId !== null && $tenantId !== '') {
            $eventsQuery->where('tenant_id', $tenantId);
        }

        if ($from !== null) {
            $eventsQuery->where('start_at', '>=', $from);
        }

        if ($to !== null) {
            $eventsQuery->where('start_at', '<=', $to);
        }

        $events = $eventsQuery->get();

        $cards = $events->map(function (Event $event) use ($analytics, $from, $to): array {
            $overview = $analytics->overview($event->id, $from, $to);

            $attendanceSeries = array_map(
                static fn (array $entry): array => [
                    'hour' => $entry['date_hour'] ?? null,
                    'valid' => (int) Arr::get($entry, 'scans_valid', 0),
                    'duplicate' => (int) Arr::get($entry, 'scans_duplicate', 0),
                    'unique' => (int) Arr::get($entry, 'unique_guests_in', 0),
                ],
                $analytics->attendanceByHour($event->id, $from, $to)
            );

            return [
                'event' => [
                    'id' => (string) $event->id,
                    'tenant_id' => $event->tenant_id !== null ? (string) $event->tenant_id : null,
                    'name' => $event->name,
                    'start_at' => optional($event->start_at)?->toISOString(),
                    'end_at' => optional($event->end_at)?->toISOString(),
                    'timezone' => $event->timezone,
                    'status' => $event->status,
                ],
                'overview' => $overview,
                'attendance' => $attendanceSeries,
            ];
        });

        $tenants = Tenant::query()
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->get()
            ->map(static fn (Tenant $tenant): array => [
                'id' => (string) $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ])
            ->values();

        return response()->json([
            'data' => $cards->values()->all(),
            'meta' => [
                'tenants' => $tenants,
            ],
        ]);
    }
}
