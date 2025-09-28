<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Requests\HostessAssignment\MyHostessAssignmentIndexRequest;
use App\Models\Event;
use App\Models\HostessAssignment;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Formatters\HostessAssignmentFormatter;
use Illuminate\Http\JsonResponse;

/**
 * Provide hostess users with their active assignments.
 */
class HostessAssignmentMeController extends Controller
{
    use InteractsWithTenants;

    /**
     * List current assignments for the authenticated hostess.
     */
    public function index(MyHostessAssignmentIndexRequest $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $authUser->loadMissing('roles');

        $payload = $request->validated();
        $tenantId = $this->resolveTenantContext($request, $authUser);

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Unable to determine tenant context for the authenticated user.'],
            ]);
        }

        $event = Event::query()->where('tenant_id', $tenantId)->find($payload['event_id']);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested event was not found.', null, 404);
        }

        $assignments = HostessAssignment::query()
            ->with(['hostess', 'event', 'venue', 'checkpoint'])
            ->forTenant($tenantId)
            ->where('hostess_user_id', $authUser->id)
            ->where('event_id', $event->id)
            ->currentlyActive()
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'data' => $assignments
                ->map(fn (HostessAssignment $assignment): array => HostessAssignmentFormatter::format($assignment))
                ->values()
                ->all(),
        ]);
    }
}
