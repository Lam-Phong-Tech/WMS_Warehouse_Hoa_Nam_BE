<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\MiniAppNfcController;
use Modules\Warehouse\Http\Controllers\P07NfcController;
use Tests\TestCase;

final class P07NfcRouteContractTest extends TestCase
{
    public function test_existing_nfc_routes_resolve_to_hardened_adapter(): void
    {
        $this->assertInstanceOf(P07NfcController::class, app(MiniAppNfcController::class));
    }

    public function test_added_routes_require_authentication_and_existing_permissions(): void
    {
        $expected = [
            'api/v1/mini-app/nfc-tags/{physicalCodeId}' => ['GET', 'permission:physical_code.assign_rfid|inventory.trace'],
            'api/v1/mini-app/items/{itemId}/nfc/confirmation' => ['GET', 'permission:physical_code.assign_rfid'],
            'api/v1/mini-app/items/{itemId}/nfc/release' => ['POST', 'permission:physical_code.assign_rfid'],
        ];
        foreach ($expected as $uri => [$method, $permission]) {
            $matches = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true));
            $this->assertCount(1, $matches, $uri);
            $middleware = $matches->first()->gatherMiddleware();
            $this->assertContains('auth:api', $middleware);
            $this->assertContains($permission, $middleware);
        }
    }
}
