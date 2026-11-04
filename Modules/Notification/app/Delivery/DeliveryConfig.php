<?php

namespace Modules\Notification\Delivery;

use Illuminate\Support\Facades\Schema;

final class DeliveryConfig
{
    public const CONTRACT = 'notification-delivery-v1';
    public function status(): string
    {
        if (! config('notification_delivery.enabled', false)) return 'DISABLED';
        if (! Schema::hasTable('notification_delivery_installations') || ! Schema::hasTable('notification_delivery_bindings')) return 'SCHEMA_NOT_READY';
        $file = config('notification_delivery.credentials_file');
        if (! is_string($file) || ! is_file($file) || ! is_readable($file)) return 'MISSING';
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data) || ($data['type'] ?? null) !== 'service_account' || ($data['project_id'] ?? null) !== config('notification_delivery.project_id')
            || ! is_string($data['client_email'] ?? null) || ! is_string($data['private_key'] ?? null)
            || openssl_pkey_get_private($data['private_key']) === false) return 'INVALID';
        return 'CONFIGURED';
    }
    public function publicPayload(): array
    {
        $status=$this->status();$web=(array)config('notification_delivery.web_config',[]);
        $public=array_intersect_key($web,array_flip(['apiKey','authDomain','projectId','storageBucket','messagingSenderId','appId']));
        return ['enabled'=>$status==='CONFIGURED','configuration_status'=>$status,'sender_verification'=>'NOT_IMPLIED_BY_CONFIGURATION',
            'project_id'=>config('notification_delivery.project_id'),'public_web_config'=>$public,
            'vapid_public_key'=>config('notification_delivery.vapid_public_key'),'allowed_web_origins'=>config('notification_delivery.allowed_web_origins',[]),
            'android_package'=>config('notification_delivery.android_package'),'platforms'=>['WEB','ANDROID'],
            'self_test_enabled'=>$status==='CONFIGURED' && app()->environment(['testing','local','dev','dev-test']) && config('notification_delivery.self_test_enabled',false)];
    }
}
