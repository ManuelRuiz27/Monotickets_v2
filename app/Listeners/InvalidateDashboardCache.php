<?php

namespace App\Listeners;

use App\Events\AttendanceCreated;
use App\Http\Middleware\CacheDashboardResponses;
use App\Models\Event;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Arr;

/**
 * Flush dashboard response caches when new attendance activity occurs in live mode events.
 */
class InvalidateDashboardCache
{
    public function __construct(private readonly CacheRepository $cache)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(mixed $event): void
    {
        $eventId = $this->extractEventId($event);

        if ($eventId === null) {
            return;
        }

        /** @var Event|null $eventModel */
        $eventModel = Event::query()->find($eventId);

        if ($eventModel === null || ! $eventModel->isLiveMode()) {
            return;
        }

        $this->cache->tags(CacheDashboardResponses::tagsForEvent($eventId))->flush();
    }

    private function extractEventId(mixed $event): ?string
    {
        if ($event instanceof AttendanceCreated) {
            return $event->eventId;
        }

        if (is_array($event)) {
            $candidate = Arr::get($event, 'event_id') ?? Arr::get($event, 'eventId');

            return $candidate !== null ? (string) $candidate : null;
        }

        if (is_object($event)) {
            if (isset($event->event_id)) {
                return (string) $event->event_id;
            }

            if (isset($event->eventId)) {
                return (string) $event->eventId;
            }
        }

        return null;
    }
}
