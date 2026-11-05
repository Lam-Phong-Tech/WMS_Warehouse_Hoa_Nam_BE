<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Auth\Support\P10SessionFamily;
use Modules\Notification\Delivery\NotificationOutbox;
use Modules\Notification\Delivery\DeliverySender;
use Modules\Notification\Models\NotificationRecipient;
use Modules\Notification\Services\NotificationService;
use Tests\Feature\Concerns\PushDeliveryTestCase;

class NotificationDeliveryOutboxTest extends PushDeliveryTestCase
{
    use DatabaseMigrations;
    public function runDatabaseMigrations(): void
    {
        // Real outer commits are necessary for this test. Discard the isolated
        // memory DB instead of invoking irreversible business migration downs.
        $this->beforeRefreshingDatabase();
        $this->artisan('migrate:fresh',$this->migrateFreshUsing());
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function():void{\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=false;});
        $this->afterRefreshingDatabase();
    }
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Queue::fake();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('notification_deliveries'),'Durable delivery outbox must be migrated in the isolated test DB.');
    }
    private function registered(): array
    {
        [$u,$t]=$this->actor();$id=(string)Str::uuid();$proof=$this->proof();
        $r=$this->putJson(self::BASE.'/installations/'.$id,$this->body(),$this->headersFor($t,$proof))->assertCreated();
        return [$u,$r->json('data.registration_id')];
    }
    public function test_current_subject_warehouse_and_current_rights_are_revalidated_not_only_event_payload():void
    {
        [$u]=$this->registered();$warehouse=$this->ensureActiveWarehouse('PUSH-WH-A','TEST A');$other=\Modules\Warehouse\Models\Warehouse::create(['code'=>'PUSH-WH-B','name'=>'TEST B','status'=>'INACTIVE']);
        $this->assertNotSame((string)$warehouse->id,(string)$other->id);
        $role=\Modules\Auth\Models\Role::create(['role_code'=>'PUSH_'.Str::random(8),'role_name'=>'TEST operator','scope_type'=>'WAREHOUSE','status'=>'ACTIVE']);
        foreach([$warehouse,$other]as$w)$u->roles()->attach($role->role_id,['user_role_id'=>(string)Str::uuid(),'scope_ref'=>$w->id]);
        $permission=\Modules\Auth\Models\Permission::firstOrCreate(['permission_key'=>'inbound.view'],['permission_name'=>'TEST inbound read','scope_type'=>'FUNCTION','status'=>'ACTIVE']);
        DB::table('role_permission')->insert(['role_permission_id'=>(string)Str::uuid(),'role_id'=>$role->role_id,'permission_id'=>$permission->permission_id,'created_at'=>now(),'updated_at'=>now()]);
        \Modules\Auth\Support\AuthCache::bumpVersionForUsersWithRole($role->role_id);
        $this->assertTrue($u->fresh()->canAccessWarehouse((string)$other->id));$this->assertTrue($u->fresh()->hasPermission('inbound.view'));
        $doc=\Modules\Warehouse\Models\StockDocument::create(['doc_type'=>'receipt','doc_no'=>'TEST-PUSH-'.Str::uuid(),'doc_date'=>now()->toDateString(),'status'=>'DRAFT','dst_warehouse_id'=>$warehouse->id,'created_by'=>$u->user_id,'updated_by'=>$u->user_id]);
        app(NotificationService::class)->notify('inbound.created','TEST','TEST',[(string)$u->user_id],sourceModule:'warehouse',subjectType:'stock_document',subjectId:(string)$doc->id,payload:['document_id'=>(string)$doc->id,'type'=>'receipt','warehouse_id'=>$other->id],dispatchKey:'wrong-current-wh');
        $s=$this->sender();app(NotificationOutbox::class)->drain($s);$this->assertSame([],$s->seen);$this->assertSame('SUPPRESSED',DB::table('notification_deliveries')->value('state'));
        app(NotificationService::class)->notify('inbound.created','TEST','TEST',[(string)$u->user_id],sourceModule:'warehouse',subjectType:'stock_document',subjectId:(string)$doc->id,payload:['document_id'=>(string)$doc->id,'type'=>'receipt','warehouse_id'=>$warehouse->id],dispatchKey:'right-lost');
        DB::table('role_permission')->where('role_id',$role->role_id)->delete();\Modules\Auth\Support\AuthCache::bumpVersionForUsersWithRole($role->role_id);app(NotificationOutbox::class)->drain($s);$this->assertSame([],$s->seen);
    }
    private function notice($u,string $key='same-test-dispatch')
    {
        return app(NotificationService::class)->notify('system.notification.test','TEST notification','TEST no PII',[(string)$u->user_id],sourceModule:'notification',dispatchKey:$key);
    }
    public function test_worker_crash_lease_becomes_unknown_without_automatic_resend():void
    {
        [$u]=$this->registered();$n=$this->notice($u,'crash-lease');$id=DB::table('notification_deliveries')->value('id');
        DB::table('notification_deliveries')->where('id',$id)->update(['state'=>'SENDING','lease_token'=>(string)Str::uuid(),'lease_expires_at'=>now()->utc()->subMinutes(3)->toIso8601String()]);
        $sender=$this->sender();$outbox=app(NotificationOutbox::class);
        $this->assertSame(0,$outbox->drain($sender));
        $this->assertSame([],$sender->seen);
        $this->assertSame('UNKNOWN',DB::table('notification_deliveries')->where('id',$id)->value('state'));
        $this->travel(3)->minutes();
        $this->assertSame(0,$outbox->drain($sender));
        $this->assertSame([],$sender->seen);
        $this->assertSame(1,DB::table('notification_deliveries')->count());$this->assertSame(1,DB::table('notifications')->where('id',$n->id)->count());$this->assertSame(1,DB::table('notification_recipients')->where('notification_id',$n->id)->count());
    }
    private function sender(string $outcome='PROVIDER_ACCEPTED'): DeliverySender
    {
        return new class($outcome) implements DeliverySender {
            public array $seen=[];
            public function __construct(private string $outcome){}
            public function send(string $token,array $data): array { $this->seen[]=['token'=>$token,'data'=>$data];return ['state'=>$this->outcome,'provider_id'=>$this->outcome==='PROVIDER_ACCEPTED'?'TEST-PROVIDER-ID':null,'retry_after'=>null]; }
        };
    }
    public function test_recipient_and_delivery_survive_provider_flow_without_auto_read(): void
    {
        [$u,$binding]=$this->registered();$n=$this->notice($u);$r=NotificationRecipient::where('notification_id',$n->id)->firstOrFail();
        $this->notice($u);$this->assertSame(1,DB::table('notifications')->where('event_key','system.notification.test')->count());
        $this->assertSame(1,DB::table('notification_deliveries')->count());
        $s=$this->sender();$this->assertSame(1,app(NotificationOutbox::class)->drain($s));
        $this->assertSame((string)$r->id,$s->seen[0]['data']['recipient_id']);
        $this->assertNotSame((string)$n->id,$s->seen[0]['data']['recipient_id']);
        $this->assertSame($binding,$s->seen[0]['data']['registration_id']);
        $this->assertArrayNotHasKey('body',$s->seen[0]['data']);$this->assertArrayNotHasKey('title',$s->seen[0]['data']);
        $this->assertNull($r->fresh()->read_at);$this->assertSame(0,app(NotificationOutbox::class)->drain($s));
    }
    public function test_outer_rollback_and_in_transaction_drain_never_send(): void
    {
        [$u]=$this->registered();$s=$this->sender();DB::beginTransaction();$this->notice($u);
        $this->assertSame(0,app(NotificationOutbox::class)->drain($s));DB::rollBack();
        $this->assertSame(0,DB::table('notification_deliveries')->count());$this->assertSame([],$s->seen);
    }
    public function test_revoked_family_and_stale_binding_are_suppressed_before_send(): void
    {
        [$u,$binding]=$this->registered();$this->notice($u);$b=DB::table('notification_delivery_bindings')->where('id',$binding)->first();
        P10SessionFamily::revoke((string)$u->user_id,$b->auth_session_id);$s=$this->sender();
        app(NotificationOutbox::class)->drain($s);$this->assertSame([],$s->seen);
        $this->assertSame('SUPPRESSED',DB::table('notification_deliveries')->value('state'));
    }
    public function test_unknown_keeps_original_identity_without_retry_and_invalid_token_only_revokes_matching_binding(): void
    {
        [$u,$binding]=$this->registered();$this->notice($u);$s=$this->sender('UNKNOWN');$out=app(NotificationOutbox::class);
        $out->drain($s);$first=$s->seen[0]['data']['delivery_id'];
        $this->assertSame('UNKNOWN',DB::table('notification_deliveries')->value('state'));
        $original=DB::table('notification_deliveries')->where('id',$first)->first();
        $this->travel(2)->minutes();$this->assertSame(0,$out->drain($s));$this->assertCount(1,$s->seen);
        $unchanged=DB::table('notification_deliveries')->where('id',$first)->first();
        $this->assertEquals($original,$unchanged);
        $this->notice($u,'independent-new-event');$bad=$this->sender('UNREGISTERED');$this->assertSame(1,$out->drain($bad));
        $this->assertNotSame($first,$bad->seen[0]['data']['delivery_id']);
        $this->assertSame('UNKNOWN',DB::table('notification_deliveries')->where('id',$first)->value('state'));
        $this->assertSame('INVALID',DB::table('notification_delivery_bindings')->where('id',$binding)->value('status'));
        $this->assertNull(DB::table('notification_delivery_bindings')->where('id',$binding)->value('token_ciphertext'));
    }
    public function test_disabled_delivery_keeps_pending_transport_unchanged_and_never_sends(): void
    {
        [$user]=$this->registered();$notification=$this->notice($user,'disabled-transport');
        $before=DB::table('notification_deliveries')->first();
        config()->set('notification_delivery.enabled',false);
        $sender=$this->sender();$outbox=app(NotificationOutbox::class);
        $this->assertSame(0,$outbox->enqueue($notification));
        $this->assertSame(0,$outbox->drain($sender));
        $this->assertSame([],$sender->seen);
        $this->assertEquals($before,DB::table('notification_deliveries')->first());
        $this->assertNull(NotificationRecipient::where('notification_id',$notification->id)->value('read_at'));
    }
    public function test_missing_business_source_is_suppressed_without_replacing_recipient_data(): void
    {
        [$u]=$this->registered();app(NotificationService::class)->notify('warranty.case_created','TEST','TEST',[(string)$u->user_id],sourceModule:'warehouse',subjectType:'warranty_case',subjectId:(string)Str::uuid(),payload:['case_id'=>(string)Str::uuid()],dispatchKey:'missing-owner');
        $s=$this->sender();app(NotificationOutbox::class)->drain($s);
        $this->assertSame([],$s->seen);$this->assertSame(1,DB::table('notification_recipients')->count());
        $this->assertSame('SUPPRESSED',DB::table('notification_deliveries')->value('state'));
    }
}
