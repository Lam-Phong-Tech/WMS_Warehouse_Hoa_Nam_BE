<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Notification\Delivery\FcmHttpSender;
use Modules\Notification\Delivery\FcmAccessTokenSource;
use Tests\Feature\Concerns\PushDeliveryTestCase;
class NotificationFcmTransportTest extends PushDeliveryTestCase
{
    use RefreshDatabase;
    public function test_validate_only_never_records_provider_acceptance_or_delivery():void
    {
        $sender=$this->sender();$this->assertTrue(method_exists($sender,'validate'),'Provider validation must be separate from send.');Http::preventStrayRequests();Http::fake(['https://fcm.googleapis.com/v1/projects/hoa-nam-scanner-devtest/messages:send'=>Http::response(['name'=>'projects/hoa-nam-scanner-devtest/messages/VALIDATION-ONLY'])]);
        $before=\Illuminate\Support\Facades\DB::table('notification_deliveries')->count();$result=$sender->validate('TEST-OPAQUE-FCM-TOKEN',$this->data());$this->assertSame('VALIDATED',$result['state']);$this->assertNull($result['provider_id']);Http::assertSent(fn($q)=>$q['validateOnly']===true&&!isset($q['message']['notification']));$this->assertSame($before,\Illuminate\Support\Facades\DB::table('notification_deliveries')->count());
    }
    public function test_oauth_is_single_fixed_endpoint_cached_and_never_uses_foreign_token_uri():void
    {
        Http::preventStrayRequests();Http::fake(['https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'TEST-OAUTH-CACHED','expires_in'=>3600,'token_type'=>'Bearer'])]);
        $source=new \Modules\Notification\Delivery\GoogleFcmAccessTokenSource();
        $this->assertSame('TEST-OAUTH-CACHED',$source->accessToken());$this->assertSame('TEST-OAUTH-CACHED',$source->accessToken());Http::assertSentCount(1);
        $key=json_decode(file_get_contents($this->credentialFile),true);$key['token_uri']='https://foreign.invalid/token';file_put_contents($this->credentialFile,json_encode($key));
        $this->assertSame('INVALID',app(\Modules\Notification\Delivery\DeliveryConfig::class)->status());
    }
    public function test_live_sender_key_is_rejected_inside_repository():void
    {
        $this->app->detectEnvironment(fn()=>'production');config()->set('notification_delivery.tier','dev-test');config()->set('app.url','https://khohoanamdev.lptech.info.vn');
        $this->assertSame('INVALID',app(\Modules\Notification\Delivery\DeliveryConfig::class)->status());
    }
    private function data():array{return ['contract'=>'notification-delivery-v1','delivery_id'=>(string)Str::uuid(),'recipient_id'=>(string)Str::uuid(),'registration_id'=>(string)Str::uuid(),'binding_version'=>'1','expires_at'=>now()->utc()->addMinutes(10)->toIso8601String()];}
    private function sender():FcmHttpSender
    {
        $this->assertTrue(class_exists(FcmHttpSender::class),'FCM HTTPv1 sender must exist.');
        return new FcmHttpSender(new class implements FcmAccessTokenSource{public function accessToken():string{return 'TEST-ONLY-OAUTH';}});
    }
    public function test_fixed_endpoint_data_only_and_provider_acceptance_is_not_delivery():void
    {
        $s=$this->sender();Http::preventStrayRequests();Http::fake(['https://fcm.googleapis.com/v1/projects/hoa-nam-scanner-devtest/messages:send'=>Http::response(['name'=>'projects/hoa-nam-scanner-devtest/messages/TEST-ACCEPTED'],200)]);
        $data=$this->data();$r=$s->send('TEST-OPAQUE-FCM-TOKEN',$data);$this->assertSame('PROVIDER_ACCEPTED',$r['state']);
        Http::assertSent(fn($q)=>$q->hasHeader('Authorization','Bearer TEST-ONLY-OAUTH') && $q['message']['data']===$data && !isset($q['message']['notification']) && $q['message']['android']['priority']==='HIGH');
        $this->assertArrayNotHasKey('delivered_at',$r);$this->assertArrayNotHasKey('read_at',$r);
    }
    public function test_unregistered_is_distinct_from_credentials_and_retryable_errors():void
    {
        $s=$this->sender();Http::fakeSequence()->push(['error'=>['details'=>[['errorCode'=>'UNREGISTERED']]]],404)->push(['error'=>['message'=>'DO-NOT-ECHO-DEVICE-TOKEN']],403)->push([],429,['Retry-After'=>'120']);
        $this->assertSame('UNREGISTERED',$s->send('TEST-OPAQUE-FCM-TOKEN',$this->data())['state']);
        $r=$s->send('TEST-OPAQUE-FCM-TOKEN',$this->data());$this->assertSame('CONFIGURATION_ERROR',$r['state']);$this->assertStringNotContainsString('DO-NOT-ECHO',json_encode($r));
        $r=$s->send('TEST-OPAQUE-FCM-TOKEN',$this->data());$this->assertSame('RETRYABLE',$r['state']);$this->assertSame(120,$r['retry_after']);
    }
    public function test_invalid_extra_payload_or_wrong_configuration_never_sends():void
    {
        $s=$this->sender();Http::fake();$d=$this->data();$this->assertSame('REJECTED',$s->send('TEST-OPAQUE-FCM-TOKEN',$d+['customer_phone'=>'TEST'])['state']);
        config()->set('notification_delivery.enabled',false);$this->assertSame('CONFIGURATION_ERROR',$s->send('TEST-OPAQUE-FCM-TOKEN',$d)['state']);Http::assertNothingSent();
    }
}
