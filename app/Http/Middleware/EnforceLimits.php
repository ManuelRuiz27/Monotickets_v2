<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\Plan;
use App\Models\Qr;
use App\Models\Tenant;
use App\Models\UsageCounter;
use App\Services\UsageService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnforceLimits
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UsageService $usageService,
    ) {
    }

    public function handle(Request $request, Closure $next, string $action, ?string $option = null): Response
    {
        return match ($action) {
            'event.create' => $this->enforceEventCreationLimit($request, $next),
            'user.create' => $this->enforceUserCreationLimit($request, $next),
            'scan.record' => $this->enforceScanLimit($request, $next),
            'export' => $this->enforceExportAvailability($request, $next, $option),
            default => $next($request),
        };
    }

    private function enforceEventCreationLimit(Request $request, Closure $next): Response
    {
        if (! $this->shouldEnforceEventLimit($request)) {
            return $next($request);
        }

        $tenant = $this->resolveTenant($request);

        if ($tenant === null) {
            return $next($request);
        }

        $plan = $this->resolvePlan($tenant);

        if ($plan === null) {
            return $next($request);
        }

        $limit = $this->extractLimit($plan, 'max_events');

        if ($limit === null) {
            return $next($request);
        }

        $usage = $this->usageService->currentValue($tenant, UsageCounter::KEY_EVENT_COUNT);

        if ($limit <= 0 || $usage >= $limit) {
            return $this->limitExceededResponse(
                'You have reached the maximum number of active events allowed by your plan. Please upgrade to add more events.',
                [
                    'limit' => $limit,
                    'current_usage' => $usage,
                    'resource' => 'events',
                ],
                Response::HTTP_PAYMENT_REQUIRED
            );
        }

        return $next($request);
    }

    private function enforceUserCreationLimit(Request $request, Closure $next): Response
    {
        if (! $this->isActivatingUser($request)) {
            return $next($request);
        }

        $tenant = $this->resolveTenant($request);

        if ($tenant === null) {
            return $next($request);
        }

        $plan = $this->resolvePlan($tenant);

        if ($plan === null) {
            return $next($request);
        }

        $limit = $this->extractLimit($plan, 'max_users');

        if ($limit === null) {
            return $next($request);
        }

        $usage = $this->usageService->currentValue($tenant, UsageCounter::KEY_USER_COUNT);

        if ($limit <= 0 || $usage >= $limit) {
            return $this->limitExceededResponse(
                'You have reached the maximum number of active users allowed by your plan. Please upgrade to add more team members.',
                [
                    'limit' => $limit,
                    'current_usage' => $usage,
                    'resource' => 'users',
                ],
                Response::HTTP_PAYMENT_REQUIRED
            );
        }

        return $next($request);
    }

    private function enforceScanLimit(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        if ($tenant === null) {
            return $next($request);
        }

        $plan = $this->resolvePlan($tenant);

        if ($plan === null) {
            return $next($request);
        }

        $limit = $this->extractLimit($plan, 'max_scans_per_event');

        if ($limit === null) {
            return $next($request);
        }

        $eventId = $this->resolveEventIdForScan($request);

        if ($eventId === null) {
            return $next($request);
        }

        $usage = $this->usageService->currentValue(
            $tenant,
            UsageCounter::KEY_SCAN_COUNT,
            ['event_id' => $eventId]
        );

        if ($limit > 0 && $usage < $limit) {
            return $next($request);
        }

        Log::warning('limits.scan_exceeded', [
            'tenant_id' => (string) $tenant->id,
            'event_id' => $eventId,
            'limit' => $limit,
            'current_usage' => $usage,
            'suggestion' => 'Consider upgrading your subscription to increase scan capacity.',
        ]);

        return $this->limitExceededResponse(
            'The scan limit for this event has been reached. Please upgrade your plan to continue scanning guests.',
            [
                'limit' => $limit,
                'current_usage' => $usage,
                'event_id' => $eventId,
                'suggestion' => 'Upgrade your subscription to increase the scan allowance for this event.',
            ],
            Response::HTTP_PAYMENT_REQUIRED
        );
    }

    private function enforceExportAvailability(Request $request, Closure $next, ?string $type): Response
    {
        $tenant = $this->resolveTenantForExport($request);

        if ($tenant === null) {
            return $next($request);
        }

        $plan = $this->resolvePlan($tenant);

        if ($plan === null) {
            return $next($request);
        }

        $featureKey = $type === 'pdf' ? 'exports.pdf' : 'exports.csv';
        $featureEnabled = Arr::get($plan->features_json ?? [], $featureKey, true);

        if ($featureEnabled) {
            return $next($request);
        }

        $message = $type === 'pdf'
            ? 'Your current plan does not include PDF export capabilities.'
            : 'Your current plan does not include CSV export capabilities.';

        return ApiResponse::error(
            'FEATURE_NOT_AVAILABLE',
            $message,
            [
                'feature' => $featureKey,
                'suggestion' => 'Upgrade your subscription to unlock this export feature.',
            ],
            Response::HTTP_FORBIDDEN
        );
    }

    private function resolveTenantForExport(Request $request): ?Tenant
    {
        $eventId = $request->route('event_id');

        if (is_string($eventId) && $eventId !== '') {
            $event = Event::query()->with('tenant')->find($eventId);

            if ($event !== null && $event->tenant !== null) {
                return $event->tenant;
            }
        }

        return $this->resolveTenant($request);
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        $candidates = [];
        $payloadTenant = $request->input('tenant_id');

        if (is_string($payloadTenant) && $payloadTenant !== '') {
            $candidates[] = $payloadTenant;
        }

        $attributeTenant = $request->attributes->get('tenant_id');

        if (is_string($attributeTenant) && $attributeTenant !== '') {
            $candidates[] = $attributeTenant;
        }

        $headerTenant = $request->headers->get('X-Tenant-ID');

        if (is_string($headerTenant) && $headerTenant !== '') {
            $candidates[] = $headerTenant;
        }

        $contextTenant = $this->tenantContext->tenant();

        if ($contextTenant !== null) {
            $candidates[] = (string) $contextTenant->id;
        }

        $userTenant = $request->user()?->tenant_id;

        if (is_string($userTenant) && $userTenant !== '') {
            $candidates[] = $userTenant;
        }

        $uniqueCandidates = array_values(array_unique($candidates));

        if ($contextTenant !== null && in_array((string) $contextTenant->id, $uniqueCandidates, true)) {
            return $contextTenant;
        }

        foreach ($uniqueCandidates as $candidate) {
            $tenant = Tenant::query()->find($candidate);

            if ($tenant !== null) {
                return $tenant;
            }
        }

        return $contextTenant;
    }

    private function resolvePlan(Tenant $tenant): ?Plan
    {
        $subscription = $tenant->activeSubscription();

        return $subscription?->plan;
    }

    private function shouldEnforceEventLimit(Request $request): bool
    {
        $status = $request->input('status');

        return ! (is_string($status) && strtolower($status) === 'archived');
    }

    private function isActivatingUser(Request $request): bool
    {
        if (! $request->has('is_active')) {
            return true;
        }

        $value = $request->input('is_active');

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower($value);

            return ! in_array($normalized, ['0', 'false', 'off', 'no', ''], true);
        }

        return (bool) $value;
    }

    private function extractLimit(Plan $plan, string $key): ?int
    {
        $limits = $plan->limits_json ?? [];

        if (! is_array($limits) || ! array_key_exists($key, $limits) || $limits[$key] === null) {
            return null;
        }

        return (int) $limits[$key];
    }

    private function resolveEventIdForScan(Request $request): ?string
    {
        $eventId = $request->input('event_id');

        if (is_string($eventId) && $eventId !== '') {
            return $eventId;
        }

        $qrCode = $request->input('qr_code');

        if (! is_string($qrCode) || $qrCode === '') {
            return null;
        }

        $qr = Qr::query()->with(['ticket' => function ($query): void {
            $query->select(['id', 'event_id']);
        }, 'ticket.event' => function ($query): void {
            $query->select(['id', 'tenant_id']);
        }])->where('code', $qrCode)->first();

        if ($qr === null || $qr->ticket === null || $qr->ticket->event === null) {
            return null;
        }

        return (string) $qr->ticket->event->id;
    }

    private function limitExceededResponse(string $message, array $details, int $status): JsonResponse
    {
        return ApiResponse::error('LIMIT_EXCEEDED', $message, $details, $status);
    }
}
