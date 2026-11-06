<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Auth\Support\PermissionEnforcementMap;
use Modules\Notification\Http\Controllers\NotificationDeliveryController;
use Modules\Notification\Http\Controllers\NotificationDeliverySelfTestController;
use Tests\TestCase;

class NotificationDeliveryRouteOwnerInventoryTest extends TestCase
{
    public function test_each_delivery_route_is_pinned_to_its_exact_authenticated_owner_action(): void
    {
        $expected = [
            'GET api/v1/notification-delivery/capabilities' => NotificationDeliveryController::class.'@capabilities',
            'GET api/v1/notification-delivery/installations/{installationId}' => NotificationDeliveryController::class.'@show',
            'PUT api/v1/notification-delivery/installations/{installationId}' => NotificationDeliveryController::class.'@store',
            'DELETE api/v1/notification-delivery/installations/{installationId}' => NotificationDeliveryController::class.'@destroy',
            'POST api/v1/notification-delivery/test-self' => NotificationDeliverySelfTestController::class.'@store',
            'GET api/v1/notification-delivery/test-self/{requestKey}' => NotificationDeliverySelfTestController::class.'@show',
        ];
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $routes[$method.' '.$route->uri()] = $route;
            }
        }
        foreach ($expected as $signature => $action) {
            $this->assertSame($action, PermissionEnforcementMap::IN_CODE_GUARDED_ROUTES[$signature] ?? null, $signature);
            $this->assertSame($action, $routes[$signature]->getActionName());
            $this->assertContains('auth:api', $routes[$signature]->gatherMiddleware());
            $this->assertNotContains($signature, PermissionEnforcementMap::SELF_SCOPED_ROUTES);
        }
        $this->assertArrayNotHasKey('api/v1/notification-delivery/*', PermissionEnforcementMap::IN_CODE_GUARDED_ROUTES);
    }
}
