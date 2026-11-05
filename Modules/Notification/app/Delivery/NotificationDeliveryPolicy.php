<?php
namespace Modules\Notification\Delivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\P10SessionFamily;
use Modules\Auth\Support\ProfilePreferences;
use Modules\Notification\Models\NotificationRecipient;
use Modules\Notification\Support\P13NotificationPresentation;
use Modules\Warehouse\Support\WarrantyWarehouseScope;

final class NotificationDeliveryPolicy
{
    public function reason(NotificationRecipient $r,object $binding): ?string
    {
        if ($binding->status!=='ACTIVE' || $binding->user_id!==(string)$r->user_id) return 'BINDING_NOT_ACTIVE';
        $u=User::find($binding->user_id);
        if ($u===null || $u->status!=='ACTIVE') return 'ACCOUNT_NOT_ACTIVE';
        if (!P10SessionFamily::query((string)$u->user_id,$binding->auth_session_id)->active()->exists()) return 'SESSION_NOT_ACTIVE';
        $n=$r->notification;if($n===null)return 'NOTIFICATION_MISSING';
        if(!in_array($n->event_key,[...array_keys(P13NotificationPresentation::EVENTS),'system.notification.test','reversal.created','reversal.posted','reversal.cancelled','stock.shortage_detected','user.account_changed','role.permissions_changed','security.alert_raised'],true))return 'SOURCE_POLICY_UNSUPPORTED';
        $category=ProfilePreferences::category($n->event_key,$n->source_module);
        if($category!==null && $category!=='account' && !ProfilePreferences::read((string)$u->user_id)['notifications'][$category]['in_app'])return 'CATEGORY_OPTED_OUT';
        $p=is_array($n->payload)?$n->payload:[];
        if($n->event_key==='system.notification.test' && $n->source_module==='notification' && $n->subject_id===null)return null;
        if(str_starts_with($n->event_key,'user.'))return ($u->hasPermission('iam.user.manage') || ($n->subject_type==='user' && (string)$n->subject_id===(string)$u->user_id))?null:'PERMISSION_REVOKED';
        if($n->event_key==='security.alert_raised')return $u->hasPermission('iam.user.manage')?null:'PERMISSION_REVOKED';
        if($n->event_key==='role.permissions_changed'){
            $holder=DB::table('user_role')->where('user_id',$u->user_id)->where('role_id',$n->subject_id)->exists();
            return ($u->hasPermission('iam.role.manage') || $holder)?null:'PERMISSION_REVOKED';
        }
        if($n->source_module!=='warehouse')return 'SOURCE_POLICY_UNSUPPORTED';
        $wh=$p['warehouse_id']??null;
        if(!is_string($wh)||!Str::isUuid($wh))return 'SOURCE_WAREHOUSE_REQUIRED';
        $warehouse=DB::table('warehouses')->where('id',$wh)->first();
        if($warehouse===null || !$u->canAccessWarehouse($wh))return 'WAREHOUSE_DENIED';
        foreach(['tenant_id','org_id','organization_id'] as $field){$a=$u->getAttribute($field);$w=$warehouse->{$field}??null;if(($a!==null||$w!==null)&&(!is_string($a)||$a===''||$a!==$w))return 'TENANCY_DENIED';}
        if(isset(P13NotificationPresentation::EVENTS[$n->event_key])){
            $mapped=P13NotificationPresentation::subject($r);
            if(($mapped['mapping_status']??null)!=='MAPPED'||($mapped['warehouse_id']??null)!==$wh)return 'SUBJECT_IDENTITY_UNVERIFIED';
            $kind=$mapped['business_type'];$id=$mapped['business_id'];
            if($kind==='INBOUND'||$kind==='OUTBOUND'){
                $current=\Modules\Warehouse\Models\StockDocument::find($id);$type=$kind==='INBOUND'?'receipt':'issue';$ownerColumn=$kind==='INBOUND'?'dst_warehouse_id':'src_warehouse_id';
                if($current===null||$current->doc_type!==$type||(string)$current->{$ownerColumn}!==$wh)return 'CURRENT_SUBJECT_SCOPE_UNVERIFIED';
            }else{
                if(!\Modules\Warehouse\Models\WarrantyCase::whereKey($id)->exists()||!Schema::hasTable(WarrantyWarehouseScope::TABLE))return 'CURRENT_SUBJECT_SCOPE_UNVERIFIED';
                $owners=DB::table(WarrantyWarehouseScope::TABLE)->where('warranty_case_id',$id)->pluck('warehouse_id')->unique();
                if($owners->count()!==1||(string)$owners->first()!==$wh)return 'CURRENT_SUBJECT_SCOPE_UNVERIFIED';
            }
        }
        $permission=match(true){
            $n->event_key==='inbound.sku_info_pending'=>'inbound.scan',str_starts_with($n->event_key,'inbound.')=>'inbound.view',
            str_starts_with($n->event_key,'outbound.')=>'outbound.view',str_starts_with($n->event_key,'warranty.')=>'warranty.view',
            str_starts_with($n->event_key,'reversal.')=>'inventory.view',default=>null};
        if($n->event_key==='stock.shortage_detected')return ($u->hasPermission('inbound.post')||$u->hasPermission('outbound.post'))?null:'PERMISSION_REVOKED';
        return $permission!==null && $u->hasPermission($permission)?null:'PERMISSION_REVOKED';
    }
    /** New warranty events can snapshot the explicit persisted owner; never repair history. */
    public static function withWarehouse(string $event,?string $subject,array $payload): array
    {
        if(!str_starts_with($event,'warranty.')||!is_string($subject)||!Str::isUuid($subject)||($payload['case_id']??null)!==$subject||isset($payload['warehouse_id'])||!Schema::hasTable(WarrantyWarehouseScope::TABLE))return $payload;
        $ids=DB::table(WarrantyWarehouseScope::TABLE)->where('warranty_case_id',$subject)->pluck('warehouse_id')->unique()->values();
        if($ids->count()===1 && Str::isUuid($ids[0]))$payload['warehouse_id']=$ids[0];
        return $payload;
    }
}
