<?php

namespace Modules\Notification\Delivery;

use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\P10SessionFamily;

final class InstallationRegistry
{
    public static function proofHash(string $proof): string { return hash('sha256', "notification-installation-v1\0".$proof); }
    public static function validProof(string $proof): bool
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D',$proof)) return false;
        $bytes=base64_decode(strtr($proof,'-_','+/').'=',true);
        return is_string($bytes) && strlen($bytes)===32 && rtrim(strtr(base64_encode($bytes),'+/','-_'),'=')===$proof;
    }
    public function read(string $id, string $proof, User $user, string $family): array
    {
        $i=DB::table('notification_delivery_installations')->where('id',$id)->first();
        if ($i===null) return ['exists'=>false,'registration_id'=>null,'binding_version'=>0,'active'=>false,'ownership'=>'UNBOUND'];
        $this->assertProof($i,$proof);
        return $this->present($i,$user,$family);
    }
    public function register(string $id, string $proof, array $body, User $user, string $family): array
    {
        return DB::transaction(function () use ($id,$proof,$body,$user,$family): array {
            $this->assertLiveOwner($user,$family);
            $stamp=now()->utc()->toIso8601String();
            $created=DB::table('notification_delivery_installations')->insertOrIgnore(['id'=>$id,'proof_hash'=>self::proofHash($proof),'platform'=>$body['platform'],'binding_version'=>0,'created_at'=>$stamp,'updated_at'=>$stamp])===1;
            $i=DB::table('notification_delivery_installations')->where('id',$id)->lockForUpdate()->first();
            $this->assertProof($i,$proof);
            $version=(int)($i->binding_version??0);
            if ($body['expected_version']!==$version) ApiError::abort(412,'NOTIFICATION_BINDING_STALE','Đăng ký thiết bị đã thay đổi. Vui lòng đọc lại.');
            if ($i!==null && $i->platform!==$body['platform']) ApiError::abort(409,'NOTIFICATION_PLATFORM_CONFLICT','Nền tảng thiết bị không khớp đăng ký.');
            $fingerprint=hash('sha256',$body['fcm_token']);
            $taken=DB::table('notification_delivery_bindings')->where('token_fingerprint',$fingerprint)->where('status','ACTIVE')->first();
            if ($taken!==null && $taken->installation_id!==$id) ApiError::abort(409,'NOTIFICATION_TOKEN_ALREADY_BOUND','Token đã có đăng ký thiết bị khác.');
            $now=now()->utc()->toIso8601String();
            if ($i!==null) {
                $current=DB::table('notification_delivery_bindings')->where('id',$i->current_binding_id)->first();
                if ($current!==null && $current->status==='ACTIVE' && $current->user_id===(string)$user->user_id
                    && $current->auth_session_id===$family && hash_equals((string)$current->token_fingerprint,$fingerprint)
                    && CarbonImmutable::parse($current->expires_at)->isFuture()) {
                    DB::table('notification_delivery_bindings')->where('id',$current->id)->update(['updated_at'=>$now,'expires_at'=>now()->utc()->addDays((int)config('notification_delivery.binding_days',30))->toIso8601String()]);
                    return ['created'=>false,'data'=>$this->present($i,$user,$family)];
                }
                DB::table('notification_delivery_bindings')->where('installation_id',$id)->where('status','ACTIVE')
                    ->update(['status'=>'REPLACED','token_fingerprint'=>null,'token_ciphertext'=>null,'updated_at'=>$now]);
            }
            if ($version>=9007199254740991) ApiError::abort(503,'NOTIFICATION_BINDING_VERSION_LIMIT','Cần quản trị viên kiểm tra đăng ký thiết bị.');
            $version++;$binding=(string)Str::uuid();
            DB::table('notification_delivery_bindings')->insert(['id'=>$binding,'installation_id'=>$id,'user_id'=>$user->user_id,'auth_session_id'=>$family,
                'binding_version'=>$version,'status'=>'ACTIVE','token_fingerprint'=>$fingerprint,'token_ciphertext'=>Crypt::encryptString($body['fcm_token']),
                'expires_at'=>now()->utc()->addDays((int)config('notification_delivery.binding_days',30))->toIso8601String(),'created_at'=>$now,'updated_at'=>$now]);
            DB::table('notification_delivery_installations')->where('id',$id)->update(['binding_version'=>$version,'current_binding_id'=>$binding,'updated_at'=>$now]);
            return ['created'=>$created,'data'=>$this->present(DB::table('notification_delivery_installations')->where('id',$id)->first(),$user,$family)];
        });
    }
    public function deactivate(string $id,string $proof,int $expected,User $user,string $family): array
    {
        return DB::transaction(function () use ($id,$proof,$expected,$user,$family): array {
            $this->assertLiveOwner($user,$family);
            $i=DB::table('notification_delivery_installations')->where('id',$id)->lockForUpdate()->first();
            if ($i===null) ApiError::abort(404,'NOTIFICATION_INSTALLATION_NOT_FOUND','Chưa có đăng ký thiết bị.');
            $this->assertProof($i,$proof);
            if ((int)$i->binding_version!==$expected) ApiError::abort(412,'NOTIFICATION_BINDING_STALE','Đăng ký thiết bị đã thay đổi.');
            $b=DB::table('notification_delivery_bindings')->where('id',$i->current_binding_id)->first();
            if ($b===null || $b->user_id!==(string)$user->user_id || $b->auth_session_id!==$family) ApiError::abort(403,'NOTIFICATION_BINDING_NOT_OWNED','Không thể tắt đăng ký của phiên khác.');
            if ($b->status==='ACTIVE') {
                $now=now()->utc()->toIso8601String();
                DB::table('notification_delivery_bindings')->where('id',$b->id)->update(['status'=>'REVOKED','token_ciphertext'=>null,'token_fingerprint'=>null,'updated_at'=>$now]);
                DB::table('notification_delivery_installations')->where('id',$id)->update(['binding_version'=>$expected+1,'updated_at'=>$now]);
            }
            return $this->present(DB::table('notification_delivery_installations')->where('id',$id)->first(),$user,$family);
        });
    }
    private function assertProof(object $i,string $proof): void
    {
        if (! self::validProof($proof) || ! hash_equals($i->proof_hash,self::proofHash($proof))) ApiError::abort(403,'NOTIFICATION_INSTALLATION_PROOF_REQUIRED','Không xác minh được thiết bị này.');
    }
    private function assertLiveOwner(User $user,string $family): void
    {
        $fresh=User::query()->whereKey($user->user_id)->lockForUpdate()->first();
        if ($fresh===null || $fresh->status!=='ACTIVE') ApiError::abort(403,'ACCOUNT_INACTIVE','Tài khoản chưa được phép nhận thông báo.');
        if (! P10SessionFamily::query((string)$user->user_id,$family)->active()->exists()) ApiError::abort(401,'AUTH_SESSION_EXPIRED','Phiên đăng nhập không còn hợp lệ.');
    }
    private function present(object $i,User $user,string $family): array
    {
        $b=DB::table('notification_delivery_bindings')->where('id',$i->current_binding_id)->first();
        $own=$b!==null && $b->user_id===(string)$user->user_id && $b->auth_session_id===$family;
        return ['exists'=>true,'registration_id'=>$b?->id,'binding_version'=>(int)$i->binding_version,
            'active'=>$b!==null && $b->status==='ACTIVE' && CarbonImmutable::parse($b->expires_at)->isFuture(),
            'ownership'=>$own?'OWN':'OTHER_SESSION','state'=>$b?->status??'UNBOUND'];
    }
}
