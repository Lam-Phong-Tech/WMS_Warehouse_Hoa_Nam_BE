<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PushDeliveryTestCase;

class ControlledMultiSessionNotificationTest extends PushDeliveryTestCase
{
    use RefreshDatabase;

    public function test_two_consented_installations_keep_separate_live_family_ownership(): void
    {
        config()->set('refresh_token.single_active_session.enabled', true);
        config()->set('refresh_token.controlled_multi_session.enabled', true);
        config()->set('app.url', 'http://localhost');
        [$user, $a] = $this->actor();
        $this->headersFor($a);
        $login = $this->browserLogin($user->email, 'Push-Test-Only123!')->assertOk();
        $b = (string) $login->json('data.access_token');
        $pa = $this->proof();
        $pb = $this->proof();
        $ua = self::BASE.'/installations/'.Str::uuid();
        $ub = self::BASE.'/installations/'.Str::uuid();
        $this->putJson($ua, $this->body(), $this->headersFor($a, $pa))->assertCreated();
        $this->putJson($ub, $this->body('TEST-FCM-OPAQUE-DEVICE-TOKEN-B'), $this->headersFor($b, $pb))->assertCreated();
        $this->getJson($ua, $this->headersFor($a, $pa))->assertOk()->assertJsonPath('data.ownership', 'OWN')->assertJsonPath('data.active', true);
        $this->getJson($ub, $this->headersFor($b, $pb))->assertOk()->assertJsonPath('data.ownership', 'OWN')->assertJsonPath('data.active', true);
        $this->getJson($ua, $this->headersFor($b, $pa))->assertOk()->assertJsonPath('data.ownership', 'OTHER_SESSION');
        $this->delete($ua, [], $this->headersFor($b, $pa) + ['If-Match' => '"1"'])->assertForbidden();
        $this->getJson($ua, $this->headersFor($a, $pa))->assertOk()->assertJsonPath('data.active', true);
    }
}
