<?php

namespace App\Http\Middleware;

use App\Models\Event;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache dashboard API responses for short-lived reuse per user and parameter set.
 */
class CacheDashboardResponses
{
    public const BASE_TAG = 'dashboard:responses';

    private const DEFAULT_TTL_SECONDS = 60;

    private const LIVE_TTL_SECONDS = 30;

    public function __construct(private readonly CacheRepository $cache)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldAttemptCaching($request)) {
            return $next($request);
        }

        $user = $request->user();
        $eventId = (string) $request->route('event_id');
        $cacheKey = $this->buildCacheKey($request, $user, $eventId);
        $tags = self::tagsForEvent($eventId);

        $cached = $this->cache->tags($tags)->get($cacheKey);

        if (is_array($cached)) {
            $response = $this->rehydrateResponse($cached);
            $response->headers->set('X-Dashboard-Cache', 'HIT');

            return $response;
        }

        /** @var Response $response */
        $response = $next($request);

        if (! $this->isCacheableResponse($response)) {
            $response->headers->set('X-Dashboard-Cache', 'SKIP');

            return $response;
        }

        $ttl = $this->resolveTtlSeconds($eventId);
        $this->cache
            ->tags($tags)
            ->put($cacheKey, $this->dehydrateResponse($response), now()->addSeconds($ttl));

        $response->headers->set('X-Dashboard-Cache', 'MISS');

        return $response;
    }

    /**
     * Build the cache key for the request.
     */
    private function buildCacheKey(Request $request, ?Authenticatable $user, string $eventId): string
    {
        $routeName = $request->route()?->getName() ?? $request->path();
        $identifier = $user?->getAuthIdentifier();
        $userKey = $identifier !== null ? (string) $identifier : sprintf('guest:%s', $request->ip());

        $query = $request->query();
        ksort($query);
        $queryHash = $query !== [] ? sha1(http_build_query($query)) : 'none';

        return sprintf('dashboard:%s:%s:%s:%s', $eventId, $userKey, $routeName, $queryHash);
    }

    /**
     * Determine if the request should be cached.
     */
    private function shouldAttemptCaching(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($request->user() === null) {
            return false;
        }

        $route = $request->route();

        if ($route === null) {
            return false;
        }

        $name = (string) $route->getName();

        return Str::startsWith($name, 'events.dashboard.');
    }

    /**
     * Determine if the response can be cached.
     */
    private function isCacheableResponse(Response $response): bool
    {
        if (! $response instanceof JsonResponse) {
            return false;
        }

        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }

    /**
     * Convert the response into a cacheable array payload.
     *
     * @return array<string, mixed>
     */
    private function dehydrateResponse(Response $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->headers->all(),
            'content' => $response->getContent(),
        ];
    }

    /**
     * Convert the cached payload back into a response instance.
     *
     * @param  array<string, mixed>  $payload
     */
    private function rehydrateResponse(array $payload): JsonResponse
    {
        $status = (int) Arr::get($payload, 'status', 200);
        $content = (string) Arr::get($payload, 'content', '{}');
        $headers = Arr::get($payload, 'headers', []);

        $response = JsonResponse::fromJsonString($content, $status);

        foreach ($headers as $name => $values) {
            if (Str::lower($name) === 'x-dashboard-cache') {
                continue;
            }

            $response->headers->set($name, $values);
        }

        return $response;
    }

    /**
     * Resolve the TTL in seconds for the cache entry.
     */
    private function resolveTtlSeconds(string $eventId): int
    {
        if ($eventId === '') {
            return self::DEFAULT_TTL_SECONDS;
        }

        $event = Event::query()->find($eventId);

        if ($event === null) {
            return self::DEFAULT_TTL_SECONDS;
        }

        return $event->isLiveMode() ? self::LIVE_TTL_SECONDS : self::DEFAULT_TTL_SECONDS;
    }

    /**
     * Resolve the cache tags for the provided event.
     *
     * @return array<int, string>
     */
    public static function tagsForEvent(string $eventId): array
    {
        return [
            self::BASE_TAG,
            sprintf('%s:%s', self::BASE_TAG, $eventId),
        ];
    }
}
