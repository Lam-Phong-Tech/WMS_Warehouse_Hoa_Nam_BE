<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class NotificationDeliveryRegistrationTest extends \Tests\Feature\Concerns\PushDeliveryTestCase
{
    use RefreshDatabase;
    public function test_all_registry_routes_require_authentication(): void
    {
        $id=(string)Str::uuid();
        foreach ([self::BASE.'/capabilities',self::BASE.'/installations/'.$id] as $p) $this->getJson($p)->assertUnauthorized();
        $this->putJson(self::BASE.'/installations/'.$id,$this->body())->assertUnauthorized();
        $this->deleteJson(self::BASE.'/installations/'.$id)->assertUnauthorized();
    }
    public function test_capabilities_report_configuration_without_exposing_credentials(): void
    {
        [, $t]=$this->actor();
        $r=$this->getJson(self::BASE.'/capabilities',$this->headersFor($t))->assertOk()->assertJsonPath('meta.contract','notification-delivery-v1')->assertJsonPath('data.configuration_status','CONFIGURED');
        $this->assertStringNotContainsString('PRIVATE KEY',$r->getContent());
        $this->assertStringNotContainsString($this->credentialFile,$r->getContent());
        config()->set('notification_delivery.enabled',false);
        $this->getJson(self::BASE.'/capabilities',$this->headersFor($t))->assertOk()->assertJsonPath('data.enabled',false);
    }
    public function test_registration_readback_and_disable_are_owned_versioned_and_redacted(): void
    {
        [$u,$t]=$this->actor();$id=(string)Str::uuid();$p=$this->proof();$url=self::BASE.'/installations/'.$id;
        $r=$this->putJson($url,$this->body(),$this->headersFor($t,$p))->assertCreated()->assertJsonPath('data.binding_version',1)->assertJsonPath('data.ownership','OWN');
        $this->assertStringNotContainsString($p,$r->getContent());$this->assertStringNotContainsString('TEST-FCM-OPAQUE',$r->getContent());
        $rid=$r->json('data.registration_id');
        $this->getJson($url,$this->headersFor($t,$p))->assertOk()->assertJsonPath('data.registration_id',$rid)->assertJsonPath('data.active',true);
        $this->putJson($url,$this->body('TEST-FCM-OPAQUE-DEVICE-TOKEN-A',1),$this->headersFor($t,$p))->assertOk()->assertJsonPath('data.binding_version',1);
        $this->delete($url,[],$this->headersFor($t,$p)+['If-Match'=>'"1"'])->assertOk()->assertJsonPath('data.active',false)->assertJsonPath('data.binding_version',2);
    }
    public function test_wrong_proof_or_other_installation_cannot_take_over_a_token(): void
    {
        [, $t]=$this->actor();$id=(string)Str::uuid();$p=$this->proof();$url=self::BASE.'/installations/'.$id;
        $this->putJson($url,$this->body(),$this->headersFor($t,$p))->assertCreated();
        $this->getJson($url,$this->headersFor($t,$this->proof()))->assertForbidden();
        $this->putJson($url,$this->body('TEST-FCM-OPAQUE-DEVICE-TOKEN-B',1),$this->headersFor($t,$this->proof()))->assertForbidden();
        $this->putJson(self::BASE.'/installations/'.Str::uuid(),$this->body(),$this->headersFor($t,$this->proof()))->assertConflict();
    }
    public function test_new_actor_on_same_physical_installation_fences_old_binding_requests(): void
    {
        [$a,$ta]=$this->actor();$id=(string)Str::uuid();$p=$this->proof();$url=self::BASE.'/installations/'.$id;
        $ra=$this->putJson($url,$this->body(),$this->headersFor($ta,$p))->assertCreated()->json('data.registration_id');
        [$b,$tb]=$this->actor();
        $rb=$this->putJson($url,$this->body('TEST-FCM-OPAQUE-DEVICE-TOKEN-A',1),$this->headersFor($tb,$p))->assertOk()->assertJsonPath('data.binding_version',2)->json('data.registration_id');
        $this->assertNotSame($ra,$rb);
        $this->delete($url,[],$this->headersFor($ta,$p)+['If-Match'=>'"1"'])->assertStatus(412);
        $this->getJson($url,$this->headersFor($tb,$p))->assertOk()->assertJsonPath('data.active',true)->assertJsonPath('data.ownership','OWN');
    }
    public function test_consent_extra_actor_fields_and_disabled_configuration_do_not_register(): void
    {
        [, $t]=$this->actor();$id=(string)Str::uuid();$url=self::BASE.'/installations/'.$id;$h=$this->headersFor($t,$this->proof());
        $this->putJson($url,array_replace($this->body(),['consent'=>'DENIED']),$h)->assertStatus(422);
        $this->putJson($url,$this->body()+['user_id'=>(string)Str::uuid()],$h)->assertStatus(422);
        config()->set('notification_delivery.enabled',false);
        $this->putJson($url,$this->body(),$h)->assertStatus(503);
    }
    public function test_installation_requires_v4_and_json_version_requires_an_integer(): void
    {
        [, $t]=$this->actor();$h=$this->headersFor($t,$this->proof());
        $this->putJson(self::BASE.'/installations/'.Str::uuid7(),$this->body(),$h)->assertStatus(422);
        $this->putJson(self::BASE.'/installations/'.Str::uuid(),array_replace($this->body(),['expected_version'=>'0']),$h)->assertStatus(422);
    }
    public function test_proof_never_exposes_another_actor_and_disabled_delivery_still_allows_opt_out(): void
    {
        [, $a]=$this->actor();$id=(string)Str::uuid();$p=$this->proof();$url=self::BASE.'/installations/'.$id;
        $this->putJson($url,$this->body(),$this->headersFor($a,$p))->assertCreated();
        [, $b]=$this->actor();
        $r=$this->getJson($url,$this->headersFor($b,$p))->assertOk()->assertJsonPath('data.ownership','OTHER_SESSION');
        $this->assertArrayNotHasKey('user_id',$r->json('data'));$this->assertArrayNotHasKey('auth_session_id',$r->json('data'));
        $this->delete($url,[],$this->headersFor($b,$p)+['If-Match'=>'"1"'])->assertForbidden();
        config()->set('notification_delivery.enabled',false);
        $this->delete($url,[],$this->headersFor($a,$p)+['If-Match'=>'"1"'])->assertOk()->assertJsonPath('data.active',false);
    }
}
