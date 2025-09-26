<?php

namespace App\Http\Controllers;

use App\Http\Params\SearchParam;
use App\Http\Params\SortParam;
use App\Http\Requests\User\UserIndexRequest;
use App\Http\Requests\User\UserStoreRequest;
use App\Http\Requests\User\UserUpdateRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Audit\RecordsAuditLogs;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Manage application users.
 */
class UserController extends Controller
{
    use RecordsAuditLogs;
    /**
     * Display a paginated list of users for the current context.
     */
    public function index(UserIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        /** @var User $authUser */
        $authUser = $request->user();
        $authUser->loadMissing('roles');

        $filters = $request->validated();

        $query = User::query()->with('roles');

        if (! $this->isSuperAdmin($authUser)) {
            $tenantId = $this->resolveTenantContext($request, $authUser);

            if ($tenantId === null) {
                $this->throwValidationException([
                    'tenant_id' => ['Unable to determine tenant context.'],
                ]);
            }

            $query->where('tenant_id', $tenantId);
        }

        if (isset($filters['role'])) {
            $roleCode = $filters['role'];
            $tenantId = $this->resolveTenantContext($request, $authUser);

            $query->whereHas('roles', function ($roleQuery) use ($roleCode, $tenantId, $authUser): void {
                $roleQuery->where('roles.code', $roleCode);

                if (! $this->isSuperAdmin($authUser)) {
                    $roleQuery->where(function ($tenantQuery) use ($roleCode, $tenantId): void {
                        if ($roleCode === 'superadmin') {
                            $tenantQuery->whereNull('roles.tenant_id');
                        } else {
                            $tenantQuery->where('roles.tenant_id', $tenantId);
                        }
                    });
                }
            });
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $searchParam = SearchParam::fromString($filters['search'] ?? null);

        if ($searchParam !== null) {
            $searchParam->apply($query, ['name', 'email']);
        }

        $perPage = (int) ($filters['per_page'] ?? 15);

        $sortParam = SortParam::fromString(
            $filters['sort'] ?? null,
            ['name', 'created_at'],
            SortParam::asc('name')
        );

        $sortParam->apply($query);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(fn (User $user): array => $this->formatUser($user));

        return ApiResponse::paginate($paginator->items(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(UserStoreRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        /** @var User $authUser */
        $authUser = $request->user();
        $authUser->loadMissing('roles');

        $validated = $request->validated();
        $tenantId = $this->determineTenantForStore($request, $authUser, $validated);

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Tenant context is required to create users.'],
            ]);
        }

        $roles = $this->resolveAssignableRoles($authUser, $validated['roles'], $tenantId);

        if ($roles->isEmpty()) {
            $this->throwValidationException([
                'roles' => ['At least one valid role must be provided.'],
            ]);
        }

        $isActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true;

        $user = DB::transaction(function () use ($validated, $tenantId, $roles, $isActive, $authUser, $request) {
            $user = User::create([
                'tenant_id' => $tenantId,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password_hash' => Hash::make($validated['password']),
                'is_active' => $isActive,
            ]);

            $user->roles()->attach($this->buildRoleAttachments($roles, $tenantId));
            $user->load('roles');

            $this->recordAuditLog($authUser, $request, 'user', $user->id, 'created', [
                'after' => $this->formatUser($user),
            ], $user->tenant_id);

            return $user;
        });

        return response()->json([
            'data' => $this->formatUser($user),
        ], 201);
    }

    /**
     * Display the specified user details.
     */
    public function show(Request $request, string $userId): JsonResponse
    {
        $user = $this->findUserOrFail($userId);

        $this->authorize('view', $user);

        $user->load('roles');

        return response()->json([
            'data' => $this->formatUser($user),
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(UserUpdateRequest $request, string $userId): JsonResponse
    {
        $user = $this->findUserOrFail($userId);

        $this->authorize('update', $user);

        /** @var User $authUser */
        $authUser = $request->user();
        $authUser->loadMissing('roles');

        $validated = $request->validated();
        $user->load('roles');

        $original = [
            'name' => $user->name,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'roles' => $user->roles->pluck('code')->sort()->values()->all(),
        ];

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }

        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }

        if (array_key_exists('is_active', $validated)) {
            $user->is_active = (bool) $validated['is_active'];
        }

        $roles = null;
        if (array_key_exists('roles', $validated)) {
            $tenantId = $user->tenant_id;

            if ($tenantId === null && $this->containsNonSuperadminRole($validated['roles'])) {
                $this->throwValidationException([
                    'roles' => ['Tenant-specific roles require the user to belong to a tenant.'],
                ]);
            }

            $roles = $this->resolveAssignableRoles($authUser, $validated['roles'], $tenantId);

            if ($roles->isEmpty()) {
                $this->throwValidationException([
                    'roles' => ['At least one valid role must be provided.'],
                ]);
            }
        }

        $changes = [];

        DB::transaction(function () use (&$user, $roles, $original, &$changes, $authUser, $request): void {
            if ($roles instanceof Collection) {
                $user->roles()->sync($this->buildRoleAttachments($roles, $user->tenant_id));
                $user->load('roles');
            }

            if ($user->isDirty()) {
                $user->save();
            }

            $updated = [
                'name' => $user->name,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'roles' => $user->roles->pluck('code')->sort()->values()->all(),
            ];

            $changes = $this->calculateDifferences($original, $updated);

            if ($changes !== []) {
                $this->recordAuditLog($authUser, $request, 'user', $user->id, 'updated', [
                    'changes' => $changes,
                ], $user->tenant_id);
            }
        });

        return response()->json([
            'data' => $this->formatUser($user->fresh('roles')),
        ]);
    }

    /**
     * Soft delete the specified user.
     */
    public function destroy(Request $request, string $userId): JsonResponse
    {
        $user = $this->findUserOrFail($userId);

        $this->authorize('delete', $user);

        /** @var User $authUser */
        $authUser = $request->user();
        $authUser->loadMissing('roles');

        $user->load('roles');
        $snapshot = $this->formatUser($user);

        $user->delete();

        $this->recordAuditLog($authUser, $request, 'user', $user->id, 'deleted', [
            'before' => $snapshot,
        ], $user->tenant_id);

        return response()->json(null, 204);
    }

    /**
     * Determine whether the authenticated user holds the superadmin role.
     */
    private function isSuperAdmin(User $user): bool
    {
        return $user->roles->contains(fn (Role $role): bool => $role->code === 'superadmin');
    }

    /**
     * Resolve the tenant identifier for the current request context.
     */
    private function resolveTenantContext(Request $request, User $authUser): ?string
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        if ($tenantId !== '') {
            return $tenantId;
        }

        $configuredTenant = (string) config('tenant.id');

        if ($configuredTenant !== '') {
            return $configuredTenant;
        }

        $headerTenant = $request->headers->get('X-Tenant-ID');

        if ($headerTenant !== null && $headerTenant !== '') {
            return $headerTenant;
        }

        return $authUser->tenant_id !== null ? (string) $authUser->tenant_id : null;
    }

    /**
     * Determine the tenant identifier to be used while creating a user.
     *
     * @param array<string, mixed> $validated
     */
    private function determineTenantForStore(Request $request, User $authUser, array $validated): ?string
    {
        if ($this->isSuperAdmin($authUser) && array_key_exists('tenant_id', $validated)) {
            return $validated['tenant_id'];
        }

        return $this->resolveTenantContext($request, $authUser);
    }

    /**
     * Resolve assignable roles ensuring tenant scoping rules are honoured.
     *
     * @param array<int, string> $requestedRoles
     */
    private function resolveAssignableRoles(User $authUser, array $requestedRoles, ?string $tenantId): Collection
    {
        $requestedRoles = array_values(array_unique($requestedRoles));

        if ($requestedRoles === []) {
            return collect();
        }

        if (! $this->isSuperAdmin($authUser) && in_array('superadmin', $requestedRoles, true)) {
            abort(403, 'This action is unauthorised.');
        }

        $roleQuery = Role::query()
            ->whereIn('code', $requestedRoles)
            ->whereNull('deleted_at');

        $roleQuery->where(function ($query) use ($tenantId): void {
            $query->whereNull('tenant_id');

            if ($tenantId !== null) {
                $query->orWhere('tenant_id', $tenantId);
            }
        });

        /** @var EloquentCollection<int, Role> $roles */
        $roles = $roleQuery->get();

        $grouped = $roles->groupBy('code');

        $resolved = collect();

        foreach ($requestedRoles as $code) {
            /** @var EloquentCollection<int, Role>|null $matching */
            $matching = $grouped->get($code);

            if ($matching === null) {
                continue;
            }

            $role = $matching->first(function (Role $role) use ($tenantId): bool {
                if ($role->code === 'superadmin') {
                    return true;
                }

                return (string) $role->tenant_id === (string) $tenantId;
            });

            if ($role !== null) {
                $resolved->push($role);
            }
        }

        if ($resolved->count() !== count($requestedRoles)) {
            $this->throwValidationException([
                'roles' => ['One or more roles are invalid for the selected tenant.'],
            ]);
        }

        return $resolved;
    }

    /**
     * Build the role attachment payload for sync/attach operations.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function buildRoleAttachments(Collection $roles, ?string $tenantId): array
    {
        return $roles
            ->mapWithKeys(function (Role $role) use ($tenantId): array {
                $pivotTenantId = $role->code === 'superadmin' ? null : $tenantId;

                if ($pivotTenantId === null && $role->code !== 'superadmin') {
                    $this->throwValidationException([
                        'roles' => ['Tenant context is required for the selected roles.'],
                    ]);
                }

                return [
                    $role->id => ['tenant_id' => $pivotTenantId],
                ];
            })
            ->all();
    }

    /**
     * Format a user model for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatUser(User $user): array
    {
        $user->loadMissing('roles');

        return [
            'id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->roles
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'tenant_id' => $role->tenant_id,
                ])->values()->all(),
            'created_at' => optional($user->created_at)->toISOString(),
            'updated_at' => optional($user->updated_at)->toISOString(),
        ];
    }

    /**
     * Find a user by identifier or fail with a 404 response.
     */
    private function findUserOrFail(string $userId): User
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);

        if ($user === null) {
            throw (new ModelNotFoundException())->setModel(User::class, [$userId]);
        }

        return $user;
    }

    /**
     * Determine if the provided roles contain non superadmin codes.
     *
     * @param array<int, string> $roles
     */
    private function containsNonSuperadminRole(array $roles): bool
    {
        return collect($roles)->contains(fn (string $role): bool => $role !== 'superadmin');
    }

    /**
     * Throw a validation exception using the API error response structure.
     *
     * @param array<string, array<int, string>> $errors
     */
    private function throwValidationException(array $errors): void
    {
        throw ValidationException::withMessages($errors);
    }
}

