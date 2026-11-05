<?php
namespace Modules\Notification\Delivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Notification\Jobs\DispatchNotificationDeliveries;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationRecipient;
use Throwable;

final class NotificationOutbox
{
    public function enqueue(Notification $n,?array $registrationIds=null): int
    {
        if(!config('notification_delivery.enabled',false)||!Schema::hasTable('notification_deliveries'))return 0;
        $count=0;$now=now()->utc()->toIso8601String();
        foreach(NotificationRecipient::where('notification_id',$n->id)->get() as $r){
            foreach(DB::table('notification_delivery_bindings')->where('user_id',$r->user_id)->where('status','ACTIVE')->when($registrationIds!==null,fn(Builder $q)=>$q->whereIn('id',$registrationIds))->get() as $b){
                if(!CarbonImmutable::parse($b->expires_at)->isFuture())continue;
                $count+=DB::table('notification_deliveries')->insertOrIgnore(['id'=>(string)Str::uuid(),'recipient_id'=>$r->id,'registration_id'=>$b->id,'binding_version'=>$b->binding_version,'state'=>'PENDING','attempts'=>0,'available_at'=>$now,'expires_at'=>now()->utc()->addSeconds(3600)->toIso8601String(),'created_at'=>$now,'updated_at'=>$now]);
            }
        }
        if($count>0)DB::afterCommit(function():void{try{DispatchNotificationDeliveries::dispatch();}catch(Throwable $e){Log::warning('Notification delivery enqueue deferred',['exception'=>$e::class]);}});
        return $count;
    }
    public function drain(DeliverySender $sender,int $limit=50): int
    {
        if(DB::transactionLevel()>0||!config('notification_delivery.enabled',false)||!Schema::hasTable('notification_deliveries'))return 0;
        $now=now()->utc()->toIso8601String();
        $this->due(DB::table('notification_deliveries')->where('state','SENDING'),'lease_expires_at',$now)->update(['state'=>'UNKNOWN','lease_token'=>null,'lease_expires_at'=>null,'available_at'=>$now,'last_code'=>'LEASE_EXPIRED']);
        // FCM has no receipt reconciliation by delivery_id. An uncertain send
        // or expired lease must not automatically send the same signal again.
        $rows=$this->due(DB::table('notification_deliveries')->whereIn('state',['PENDING','RETRYABLE','CONFIGURATION_ERROR']),'available_at',$now)->orderBy('created_at')->limit(max(1,min(100,$limit)))->get();
        $sent=0;
        foreach($rows as $candidate){
            $lease=(string)Str::uuid();
            $claimed=DB::table('notification_deliveries')->where('id',$candidate->id)->where('state',$candidate->state)->where('attempts',$candidate->attempts)
                ->update(['state'=>'SENDING','lease_token'=>$lease,'lease_expires_at'=>now()->utc()->addSeconds(120)->toIso8601String(),'updated_at'=>$now]);
            if($claimed!==1)continue;
            $row=DB::table('notification_deliveries')->where('id',$candidate->id)->first();
            if(!CarbonImmutable::parse($row->expires_at)->isFuture()){$this->finish($row,$lease,'EXPIRED','TTL_EXPIRED');continue;}
            $b=DB::table('notification_delivery_bindings')->where('id',$row->registration_id)->first();
            $i=$b===null?null:DB::table('notification_delivery_installations')->where('id',$b->installation_id)->first();
            $r=NotificationRecipient::find($row->recipient_id);
            $reason=$b===null||$i===null||$r===null?'SOURCE_MISSING':
                (($i->current_binding_id!==$b->id || (int)$i->binding_version!==(int)$row->binding_version || !CarbonImmutable::parse($b->expires_at)->isFuture())?'BINDING_STALE':app(NotificationDeliveryPolicy::class)->reason($r,$b));
            if($reason!==null){$this->finish($row,$lease,'SUPPRESSED',$reason);continue;}
            $data=['contract'=>DeliveryConfig::CONTRACT,'delivery_id'=>$row->id,'recipient_id'=>(string)$r->id,'registration_id'=>$b->id,'binding_version'=>(string)$row->binding_version,'expires_at'=>CarbonImmutable::parse($row->expires_at)->utc()->toIso8601String()];
            try{$result=$sender->send(Crypt::decryptString($b->token_ciphertext),$data);}catch(Throwable $e){$result=['state'=>'UNKNOWN','provider_id'=>null];Log::warning('Notification provider outcome unknown',['delivery_id'=>$row->id,'exception'=>$e::class]);}
            $state=$result['state']??'UNKNOWN';if(!in_array($state,['PROVIDER_ACCEPTED','UNREGISTERED','UNKNOWN','RETRYABLE','CONFIGURATION_ERROR','REJECTED','EXPIRED'],true))$state='UNKNOWN';
            $attempt=(int)$row->attempts+1;$delay=min(900,60*(5**min(2,$attempt-1)));$retry=$result['retry_after']??null;if(is_int($retry)&&$retry>0)$delay=min(3600,$retry);
            DB::transaction(function()use($row,$lease,$state,$result,$attempt,$delay,$b):void{
                $patch=['state'=>$state==='UNREGISTERED'?'REJECTED':$state,'attempts'=>$attempt,'lease_token'=>null,'lease_expires_at'=>null,'last_code'=>$state,'available_at'=>now()->utc()->addSeconds($delay)->toIso8601String(),'updated_at'=>now()->utc()->toIso8601String()];
                if($state==='PROVIDER_ACCEPTED')$patch+=['provider_id'=>substr((string)($result['provider_id']??''),0,512),'provider_accepted_at'=>now()->utc()->toIso8601String()];
                $changed=DB::table('notification_deliveries')->where('id',$row->id)->where('lease_token',$lease)->where('state','SENDING')->update($patch);
                if($changed===1&&$state==='UNREGISTERED')DB::table('notification_delivery_bindings')->where('id',$b->id)->where('binding_version',$row->binding_version)->where('status','ACTIVE')->update(['status'=>'INVALID','token_ciphertext'=>null,'token_fingerprint'=>null,'updated_at'=>now()->utc()->toIso8601String()]);
            });
            $sent++;
        }
        return $sent;
    }
    private function finish(object $row,string $lease,string $state,string $code):void
    {
        DB::table('notification_deliveries')->where('id',$row->id)->where('lease_token',$lease)->update(['state'=>$state,'last_code'=>$code,'lease_token'=>null,'lease_expires_at'=>null,'updated_at'=>now()->utc()->toIso8601String()]);
    }
    private function due(Builder $q,string $field,string $now):Builder
    {
        return DB::getDriverName()==='sqlite'?$q->whereRaw('julianday('.$field.') <= julianday(?)',[$now]):$q->where($field,'<=',$now);
    }
}
