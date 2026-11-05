<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Notification\Services\NotificationService;
use Tests\Feature\Concerns\PushDeliveryTestCase;
class NotificationDeliveryPruneTest extends PushDeliveryTestCase
{
 use RefreshDatabase;
 public function test_pruning_erases_stale_tokens_and_old_transport_audits_but_keeps_p13_and_version_fences():void
 {
  $this->assertTrue(class_exists(\Modules\Notification\Delivery\PruneNotificationDeliveryState::class),'Bounded notification privacy pruning must exist.');
  Queue::fake();[$u,$t]=$this->actor();$id=(string)Str::uuid();$proof=$this->proof();$r=$this->putJson(self::BASE.'/installations/'.$id,$this->body(),$this->headersFor($t,$proof))->assertCreated();$reg=$r->json('data.registration_id');
  $n=app(NotificationService::class)->notify('system.notification.test','TEST','TEST',[(string)$u->user_id],sourceModule:'notification',dispatchKey:'prune-test');$recipient=DB::table('notification_recipients')->where('notification_id',$n->id)->value('id');
  DB::table('notification_delivery_bindings')->where('id',$reg)->update(['expires_at'=>now()->utc()->subDays(31)->toIso8601String()]);
  DB::table('notification_deliveries')->where('registration_id',$reg)->update(['state'=>'EXPIRED','expires_at'=>now()->utc()->subDays(31)->toIso8601String(),'updated_at'=>now()->utc()->subDays(31)->toIso8601String()]);
  DB::table('notification_delivery_test_receipts')->insert(['id'=>(string)Str::uuid(),'user_id'=>$u->user_id,'auth_session_id'=>DB::table('notification_delivery_bindings')->where('id',$reg)->value('auth_session_id'),'registration_id'=>$reg,'binding_version'=>1,'recipient_id'=>$recipient,'created_at'=>now()->utc()->subDays(31)->toIso8601String()]);
  app(\Modules\Notification\Delivery\PruneNotificationDeliveryState::class)->prune(1);
  $b=DB::table('notification_delivery_bindings')->where('id',$reg)->first();$this->assertSame('EXPIRED',$b->status);$this->assertNull($b->token_ciphertext);$this->assertNull($b->token_fingerprint);
  $this->assertSame(1,(int)DB::table('notification_delivery_installations')->where('id',$id)->value('binding_version'));$this->assertSame($reg,DB::table('notification_delivery_installations')->where('id',$id)->value('current_binding_id'));
  $this->assertSame(0,DB::table('notification_deliveries')->count());$this->assertSame(0,DB::table('notification_delivery_test_receipts')->count());$this->assertSame(1,DB::table('notifications')->where('id',$n->id)->count());$this->assertSame(1,DB::table('notification_recipients')->where('id',$recipient)->count());
  $this->putJson(self::BASE.'/installations/'.Str::uuid(),$this->body(),$this->headersFor($t,$this->proof()))->assertCreated();
 }
}
