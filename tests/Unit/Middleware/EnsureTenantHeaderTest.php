<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureTenantHeader;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureTenantHeaderTest extends MiddlewareTestCase
{
    public function test_allows_superadmin_without_tenant_header(): void
    {
        $middleware = new EnsureTenantHeader();
        $request = Request::create('/events', 'GET');
        $user = $this->makeUser(null, [
            ['code' => 'superadmin', 'tenant_id' => null],
        ]);

        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn (Request $req) => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
        $this->assertNull($request->attributes->get('tenant_id'));
    }

    public function test_rejects_missing_header_for_non_superadmin(): void
    {
        $middleware = new EnsureTenantHeader();
        $request = Request::create('/events', 'GET');
        $user = $this->makeUser('tenant-1', [
            ['code' => 'organizer', 'tenant_id' => 'tenant-1'],
        ]);

        $request->setUserResolver(fn () => $user);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(Response::HTTP_FORBIDDEN);

        $middleware->handle($request, fn (Request $req) => new Response('OK'));
    }

    public function test_rejects_when_user_does_not_belong_to_tenant(): void
    {
        $middleware = new EnsureTenantHeader();
        $request = Request::create('/events', 'GET');
        $request->headers->set('X-Tenant-ID', 'tenant-2');

        $user = $this->makeUser('tenant-1', [
            ['code' => 'organizer', 'tenant_id' => 'tenant-1'],
        ]);

        $request->setUserResolver(fn () => $user);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(Response::HTTP_FORBIDDEN);

        $middleware->handle($request, fn (Request $req) => new Response('OK'));
    }

    public function test_allows_user_belonging_to_tenant(): void
    {
        $middleware = new EnsureTenantHeader();
        $request = Request::create('/events', 'GET');
        $request->headers->set('X-Tenant-ID', 'tenant-1');

        $user = $this->makeUser('tenant-1', [
            ['code' => 'organizer', 'tenant_id' => 'tenant-1'],
        ]);

        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn (Request $req) => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
        $this->assertSame('tenant-1', $request->attributes->get('tenant_id'));
        $this->assertSame('tenant-1', config('tenant.id'));
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
