<?php

namespace Modules\Notification\Delivery;

use Illuminate\Support\Facades\Schema;

final class DeliveryConfig
{
    public const CONTRACT = 'notification-delivery-v1';

    public function status(): string
    {
        if (app(AndroidTestCanary::class)->configured()) {
            return 'DISABLED';
        }
        if (! config('notification_delivery.enabled', false)) {
            return 'DISABLED';
        }

        return $this->credentialsStatus();
    }

    public function credentialsStatus(): string
    {
        if (! $this->devEnvironment()) {
            return 'ENVIRONMENT_NOT_ALLOWED';
        }
        if (! Schema::hasTable('notification_delivery_installations') || ! Schema::hasTable('notification_delivery_bindings') || ! Schema::hasTable('notification_deliveries') || ! Schema::hasColumn('notifications', 'delivery_dispatch_key')) {
            return 'SCHEMA_NOT_READY';
        }
        $file = config('notification_delivery.credentials_file');
        if (! is_string($file) || ! is_file($file) || ! is_readable($file)) {
            return 'MISSING';
        }
        $resolved = str_replace('\\', '/', (string) realpath($file));
        $root = rtrim(str_replace('\\', '/', (string) realpath(base_path())), '/').'/';
        if (! app()->environment('testing') && str_starts_with(strtolower($resolved), strtolower($root))) {
            return 'INVALID';
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data) || ($data['type'] ?? null) !== 'service_account' || ($data['project_id'] ?? null) !== config('notification_delivery.project_id')
            || ! is_string($data['client_email'] ?? null) || ! is_string($data['private_key'] ?? null)
            || ($data['token_uri'] ?? 'https://oauth2.googleapis.com/token') !== 'https://oauth2.googleapis.com/token'
            || openssl_pkey_get_private($data['private_key']) === false) {
            return 'INVALID';
        }

        return 'CONFIGURED';
    }

    public function publicPayload(): array
    {
        $status = $this->status();
        $web = (array) config('notification_delivery.web_config', []);
        $public = array_intersect_key($web, array_flip(['apiKey', 'authDomain', 'projectId', 'storageBucket', 'messagingSenderId', 'appId']));
        $vapid = config('notification_delivery.vapid_public_key');
        $webReady = ($public['projectId'] ?? null) === 'hoa-nam-scanner-devtest' && ($public['appId'] ?? null) === '1:57106079765:web:e996ecac38d01a7b60ee1c' && ($public['messagingSenderId'] ?? null) === '57106079765' && is_string($public['apiKey'] ?? null) && $public['apiKey'] !== '' && is_string($vapid) && preg_match('/^[A-Za-z0-9_-]{80,100}$/D', $vapid) === 1;

        return ['enabled' => $status === 'CONFIGURED', 'configuration_status' => $status, 'sender_verification' => 'NOT_IMPLIED_BY_CONFIGURATION',
            'project_id' => config('notification_delivery.project_id'), 'public_web_config' => $public,
            'vapid_public_key' => config('notification_delivery.vapid_public_key'), 'allowed_web_origins' => config('notification_delivery.allowed_web_origins', []),
            'android_package' => config('notification_delivery.android_package'), 'platforms' => $webReady ? ['WEB', 'ANDROID'] : ['ANDROID'],
            'platform_readiness' => ['WEB' => $webReady ? 'CONFIGURED' : 'MISSING_WEB_CONFIGURATION', 'ANDROID' => 'CONFIGURED'],
            'self_test_enabled' => $this->selfTestEnabled()];
    }

    public function devEnvironment(): bool
    {
        return app()->environment('testing') || (config('notification_delivery.tier') === 'dev-test' && rtrim((string) config('app.url'), '/') === 'https://khohoanamdev.lptech.info.vn' && config('notification_delivery.project_id') === 'hoa-nam-scanner-devtest');
    }

    public function selfTestEnabled(): bool
    {
        return $this->devEnvironment() && config('notification_delivery.self_test_enabled', false) && $this->status() === 'CONFIGURED';
    }

    public function transportReady(): bool
    {
        $canary = app(AndroidTestCanary::class);

        return $canary->configured()
            ? $canary->active() !== null && $this->credentialsStatus() === 'CONFIGURED'
            : $this->status() === 'CONFIGURED';
    }
}
