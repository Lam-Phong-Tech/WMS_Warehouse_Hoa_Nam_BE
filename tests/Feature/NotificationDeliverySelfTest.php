<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PushDeliveryTestCase;
class NotificationDeliverySelfTest extends PushDeliveryTestCase
{
    use RefreshDatabase;
    private function registration():array
    {
        [$u,$t]=$this->actor();$id=(string)Str::uuid();$p=$this->proof();
        $r=$this->putJson(self::BASE.'/installations/'.$id,$this->body(),$this->headersFor($t,$p))->assertCreated();
        return [$u,$t,$p,$r->json('data.registration_id')];
    }
    public function test_self_test_targets_only_the_selected_installation_not_other_owned_devices():void
    {
        Queue::fake();config()->set('notification_delivery.self_test_enabled',true);[$u,$t,$p,$reg]=$this->registration();
        $second=$this->putJson(self::BASE.'/installations/'.Str::uuid(),$this->body('TEST-FCM-SECOND-OWN-DEVICE-TOKEN'),$this->headersFor($t,$this->proof()))->assertCreated()->json('data.registration_id');
        $this->postJson(self::BASE.'/test-self',['registration_id'=>$reg,'binding_version'=>1,'request_key'=>(string)Str::uuid()],$this->headersFor($t,$p))->assertCreated();$this->assertSame(1,DB::table('notification_deliveries')->where('registration_id',$reg)->count());$this->assertSame(0,DB::table('notification_deliveries')->where('registration_id',$second)->count());
    }
    public function test_self_request_is_idempotent_readable_and_does_not_mutate_business():void
    {
        Queue::fake();config()->set('notification_delivery.self_test_enabled',true);[$u,$t,$p,$reg]=$this->registration();
        $key=(string)Str::uuid();$body=['registration_id'=>$reg,'binding_version'=>1,'request_key'=>$key];$h=$this->headersFor($t,$p);
        $counts=[DB::table('stock_documents')->count(),DB::table('stock_movements')->count()];
        $r=$this->postJson(self::BASE.'/test-self',$body,$h)->assertCreated()->assertJsonPath('data.state','PENDING');$recipient=$r->json('data.recipient_id');
        $this->postJson(self::BASE.'/test-self',$body,$h)->assertOk()->assertJsonPath('data.recipient_id',$recipient);
        $this->getJson(self::BASE.'/test-self/'.$key,$h)->assertOk()->assertJsonPath('data.recipient_id',$recipient);
        DB::table('notification_deliveries')->where('recipient_id',$recipient)->update(['state'=>'PROVIDER_ACCEPTED','provider_accepted_at'=>'2026-11-05 01:00:00+00']);
        $this->getJson(self::BASE.'/test-self/'.$key,$h)->assertOk()->assertJsonPath('data.provider_accepted_at','2026-11-05T01:00:00+00:00');
        $this->assertSame(1,DB::table('notifications')->where('event_key','system.notification.test')->count());
        $this->assertSame($counts,[DB::table('stock_documents')->count(),DB::table('stock_movements')->count()]);
        $this->postJson(self::BASE.'/test-self',array_replace($body,['request_key'=>(string)Str::uuid()]),$h)->assertStatus(429);
    }
    public function test_test_cannot_target_other_binding_or_arbitrary_message():void
    {
        Queue::fake();config()->set('notification_delivery.self_test_enabled',true);[$u,$t,$p,$reg]=$this->registration();[, $other]=$this->actor();
        $body=['registration_id'=>$reg,'binding_version'=>1,'request_key'=>(string)Str::uuid()];
        $this->postJson(self::BASE.'/test-self',$body,$this->headersFor($other,$p))->assertForbidden();
        $this->postJson(self::BASE.'/test-self',$body+['user_id'=>(string)Str::uuid(),'message'=>'NOT ALLOWED'],$this->headersFor($t,$p))->assertStatus(422);
        $this->assertSame(0,DB::table('notifications')->where('event_key','system.notification.test')->count());
    }
    public function test_production_or_disabled_self_test_is_not_available():void
    {
        [$u,$t,$p,$reg]=$this->registration();$body=['registration_id'=>$reg,'binding_version'=>1,'request_key'=>(string)Str::uuid()];
        $this->postJson(self::BASE.'/test-self',$body,$this->headersFor($t,$p))->assertNotFound();
        config()->set('notification_delivery.self_test_enabled',true);$this->app['env']='production';config()->set('app.url','https://production.invalid');
        $this->postJson(self::BASE.'/test-self',$body,$this->headersFor($t,$p))->assertNotFound();
    }
}
