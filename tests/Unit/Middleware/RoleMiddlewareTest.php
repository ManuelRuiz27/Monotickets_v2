<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\RoleMiddleware;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleMiddlewareTest extends MiddlewareTestCase
{
    public function test_allows_user_with_matching_role_in_tenant(): void
    {
        $middleware = new RoleMiddleware();
        $request = Request::create('/events', 'GET');
        $request->headers->set('X-Tenant-ID', 'tenant-1');

        $user = $this->makeUser('tenant-1', [
            ['code' => 'organizer', 'tenant_id' => 'tenant-1'],
        ]);

        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn (Request $req) => new Response('OK'), 'organizer');

        $this->assertSame('OK', $response->getContent());
    }

    public function test_denies_user_without_required_role(): void
    {
        $middleware = new RoleMiddleware();
        $request = Request::create('/events', 'GET');
        $request->headers->set('X-Tenant-ID', 'tenant-1');

        $user = $this->makeUser('tenant-1', [
            ['code' => 'hostess', 'tenant_id' => 'tenant-1'],
        ]);

        $request->setUserResolver(fn () => $user);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(Response::HTTP_FORBIDDEN);

        $middleware->handle($request, fn (Request $req) => new Response('OK'), 'organizer');
    }

    public function test_allows_superadmin_without_tenant_header(): void
    {
        $middleware = new RoleMiddleware();
        $request = Request::create('/events', 'GET');

        $user = $this->makeUser(null, [
            ['code' => 'superadmin', 'tenant_id' => null],
        ]);

        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn (Request $req) => new Response('OK'), 'organizer');

        $this->assertSame('OK', $response->getContent());
    }

    public function test_allows_user_when_header_missing_but_tenant_matches(): void
    {
        $middleware = new RoleMiddleware();
        $request = Request::create('/events', 'GET');

        $user = $this->makeUser('tenant-1', [
            ['code' => 'organizer', 'tenant_id' => 'tenant-1'],
        ]);

        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn (Request $req) => new Response('OK'), 'organizer');

        $this->assertSame('OK', $response->getContent());
    }

    /**
     * @param  array<int, array{code: string, tenant_id: ?string}>  $roles
     */
    private function makeUser(?string $tenantId, array $roles): User
    {
        $user = new User();
        $user->forceFill([
            'tenant_id' => $tenantId,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password_hash' => 'secret',
            'is_active' => true,
        ]);

        $roleModels = array_map(function (array $role): Role {
            $roleModel = new Role();
            $roleModel->forceFill([
                'code' => $role['code'],
                'tenant_id' => $role['tenant_id'],
                'name' => ucfirst($role['code']),
            ]);

            $roleModel->pivot = (object) ['tenant_id' => $role['tenant_id']];

            return $roleModel;
        }, $roles);

        $user->setRelation('roles', new Collection($roleModels));

        return $user;
    }
}
