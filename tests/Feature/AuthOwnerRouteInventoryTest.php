<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\P10ProfileController;
use Modules\Auth\Support\P11SecurityCommands;
use Modules\Auth\Support\PermissionEnforcementMap;
use Tests\TestCase;

class AuthOwnerRouteInventoryTest extends TestCase
{
    public function test_eleven_personal_auth_routes_have_exact_concrete_owner_actions(): void
    {
        $expected = [
            'GET api/v1/auth/me/avatar' => P10ProfileController::class.'@avatar',
            'POST api/v1/auth/me/avatar' => P10ProfileController::class.'@uploadAvatar',
            'DELETE api/v1/auth/me/avatar' => P10ProfileController::class.'@deleteAvatar',
            'GET api/v1/auth/preferences' => P10ProfileController::class.'@preferences',
            'PATCH api/v1/auth/preferences' => P10ProfileController::class.'@updatePreferences',
            'GET api/v1/auth/devices' => P10ProfileController::class.'@devices',
            'GET api/v1/auth/device-history' => P10ProfileController::class.'@deviceHistory',
            'DELETE api/v1/auth/devices/{id}' => P10ProfileController::class.'@revokeDevice',
            'POST api/v1/auth/sessions/revoke-others' => P10ProfileController::class.'@revokeOtherSessions',
            'GET api/v1/auth/security-requests/{key}' => P11SecurityCommands::class.'@show',
            'POST api/v1/auth/security-requests/{key}/verify' => P11SecurityCommands::class.'@verify',
        ];
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $routes[$method.' '.$route->uri()] = $route;
            }
        }
        foreach ($expected as $signature => $owner) {
            $this->assertArrayHasKey($signature, $routes, $signature);
            $this->assertSame($owner, $routes[$signature]->getActionName(), $signature);
            $this->assertSame($owner, PermissionEnforcementMap::IN_CODE_GUARDED_ROUTES[$signature] ?? null, $signature);
            $this->assertNotContains($signature, PermissionEnforcementMap::SELF_SCOPED_ROUTES, $signature);
            $this->assertContains('auth:api', $routes[$signature]->gatherMiddleware(), $signature);
            [$class, $action] = explode('@', $owner, 2);
            $this->assertTrue(method_exists($class, $action), $signature);
        }
        $this->assertCount(11, $expected);
    }

    public function test_all_eleven_actions_require_authentication_without_public_fallback(): void
    {
        foreach ([['GET', 'me/avatar'], ['POST', 'me/avatar'], ['DELETE', 'me/avatar'], ['GET', 'preferences'],
            ['PATCH', 'preferences'], ['GET', 'devices'], ['GET', 'device-history'],
            ['DELETE', 'devices/00000000-0000-4000-8000-000000000001'], ['POST', 'sessions/revoke-others'],
            ['GET', 'security-requests/00000000-0000-4000-8000-000000000001'],
            ['POST', 'security-requests/00000000-0000-4000-8000-000000000001/verify']] as [$method, $path]) {
            $this->json($method, '/api/v1/auth/'.$path)->assertUnauthorized();
        }
    }
}
