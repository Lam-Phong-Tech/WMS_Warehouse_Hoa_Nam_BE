<?php

namespace Tests\Feature;

use App\Console\Commands\BackendGoLiveCheck;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Support\PermissionEnforcementMap;
use ReflectionMethod;
use Tests\TestCase;

final class GoLiveOwnerGuardInventoryTest extends TestCase
{
    public function test_owner_guards_are_bound_to_exact_routes_and_actions(): void
    {
        $map = $this->inventory();
        foreach (PermissionEnforcementMap::IN_CODE_GUARDED_ROUTES as $signature => $action) {
            [$method, $uri] = explode(' ', $signature, 2);
            $methods = $method === 'GET' ? 'GET|HEAD' : $method;
            $this->assertSame('OWNER_GUARDED:'.$action, $map[$methods.' '.$uri]);
        }
    }

    public function test_replacing_an_owner_action_is_not_a_permission_exemption(): void
    {
        Route::get('api/v1/mini-app/inbound/context', fn () => response()->json([]))->middleware('auth:api');
        $this->assertSame('UNGATED', $this->inventory()['GET|HEAD api/v1/mini-app/inbound/context']);
    }

    public function test_recovery_capability_exemption_is_bound_to_its_owner(): void
    {
        $key = 'GET|HEAD api/v1/auth/recovery-requests/operations/{operationId}';
        $this->assertSame('PUBLIC_ANONYMOUS', $this->inventory()[$key]);
        Route::get('api/v1/auth/recovery-requests/operations/{operationId}', fn () => response()->json([]));
        $this->assertSame('UNAUTHENTICATED', $this->inventory()[$key]);
    }

    private function inventory(): array
    {
        return (new ReflectionMethod(BackendGoLiveCheck::class, 'routeGuardMap'))->invoke(new BackendGoLiveCheck);
    }
}
