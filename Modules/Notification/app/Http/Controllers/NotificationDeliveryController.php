<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Modules\Auth\Support\P10SessionFamily;
use Modules\Notification\Delivery\AndroidTestCanary;
use Modules\Notification\Delivery\DeliveryConfig;
use Modules\Notification\Delivery\InstallationRegistry;

final class NotificationDeliveryController extends Controller
{
    use ApiResponse;

    public function capabilities(Request $r, DeliveryConfig $config)
    {
        $family = P10SessionFamily::current($r->user(), true);
        $data = $config->publicPayload();
        $canary = app(AndroidTestCanary::class);
        if ($canary->configured()) {
            $admitted = $canary->requestAdmitted($r, $family) && $config->transportReady();
            $data['enabled'] = $admitted;
            $data['configuration_status'] = $admitted ? 'CONFIGURED' : 'DISABLED';
            $data['self_test_enabled'] = $admitted;
            $data['platforms'] = ['ANDROID'];
            $data['canary'] = ['contract' => AndroidTestCanary::CONTRACT, 'mode' => 'ANDROID_ONE_TEST',
                'business_delivery_enabled' => false, 'admitted' => $admitted,
                'expires_at' => $admitted ? $canary->active()['expires_at'] ?? null : null,
                'max_messages' => 1, 'max_provider_attempts' => 1];
        }

        return $this->result($data);
    }

    public function show(Request $r, string $installationId, InstallationRegistry $registry)
    {
        [$family,$proof] = $this->context($r, $installationId);
        $data = $registry->read($installationId, $proof, $r->user(), $family);
        if ($r->header('X-Notification-Canary-Diagnostic') === AndroidTestCanary::CONTRACT) {
            $data['canary_diagnostic'] = app(AndroidTestCanary::class)->diagnostic($r, $family, $installationId, $data);
        }

        return $this->result($data);
    }

    public function store(Request $r, string $installationId, InstallationRegistry $registry, DeliveryConfig $config)
    {
        [$family,$proof] = $this->context($r, $installationId);
        $canary = app(AndroidTestCanary::class);
        if ($canary->configured() && ! $canary->requestAdmitted($r, $family)) {
            ApiError::abort(403, 'NOTIFICATION_CANARY_SCOPE_DENIED', 'Thiết bị không thuộc phạm vi TEST đã xác minh.');
        }
        if (! $config->transportReady()) {
            ApiError::abort(503, 'NOTIFICATION_DELIVERY_NOT_READY', 'Thông báo hệ thống chưa sẵn sàng.');
        }
        $input = $r->json()->all();
        if (array_diff(array_keys($input), ['platform', 'fcm_token', 'consent', 'expected_version']) !== [] || $r->query->count()) {
            ApiError::abort(422, 'NOTIFICATION_REGISTRATION_INVALID', 'Thông tin đăng ký thiết bị không hợp lệ.');
        }
        $v = Validator::make($input, ['platform' => 'required|in:WEB,ANDROID', 'fcm_token' => 'required|string|min:20|max:4096', 'consent' => 'required|in:GRANTED', 'expected_version' => 'required|integer|min:0|max:9007199254740991']);
        if ($v->fails() || ! is_int($input['expected_version'] ?? null) || preg_match('/[\x00-\x20\x7f]/', (string) ($input['fcm_token'] ?? ''))) {
            ApiError::abort(422, 'NOTIFICATION_REGISTRATION_INVALID', 'Thông tin đăng ký thiết bị không hợp lệ.');
        }
        $origin = $r->header('Origin');
        $allowed = (array) config('notification_delivery.allowed_web_origins', []);
        if (($origin !== null && ! in_array($origin, $allowed, true)) || ($input['platform'] === 'WEB' && ! in_array($origin, $allowed, true))) {
            ApiError::abort(403, 'NOTIFICATION_ORIGIN_DENIED', 'Nguồn đăng ký thiết bị chưa được phép.');
        }
        if ($input['platform'] === 'ANDROID' && $r->header('X-Notification-App-Package') !== config('notification_delivery.android_package')) {
            ApiError::abort(403, 'NOTIFICATION_PACKAGE_DENIED', 'Ứng dụng đăng ký chưa được phép.');
        }
        $input['expected_version'] = (int) $input['expected_version'];
        $out = $registry->register($installationId, $proof, $input, $r->user(), $family);

        return $this->result($out['data'], $out['created'] ? 201 : 200);
    }

    public function destroy(Request $r, string $installationId, InstallationRegistry $registry)
    {
        [$family,$proof] = $this->context($r, $installationId);
        $etag = (string) $r->header('If-Match');
        if (! preg_match('/^"(0|[1-9][0-9]*)"$/D', $etag, $m) || strlen($m[1]) > 16 || (int) $m[1] > 9007199254740991) {
            ApiError::abort(428, 'NOTIFICATION_BINDING_VERSION_REQUIRED', 'Cần phiên bản đăng ký đã xác minh.');
        }
        if ($r->getContent() !== '' || $r->query->count()) {
            ApiError::abort(422, 'NOTIFICATION_DELETE_BODY_NOT_ALLOWED', 'Không gửi thêm dữ liệu khi tắt thông báo.');
        }

        return $this->result($registry->deactivate($installationId, $proof, (int) $m[1], $r->user(), $family));
    }

    private function context(Request $r, string $id): array
    {
        $family = P10SessionFamily::current($r->user(), true);
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id)) {
            ApiError::abort(422, 'NOTIFICATION_INSTALLATION_ID_REQUIRED', 'Mã thiết bị không hợp lệ.');
        }
        if (! Schema::hasTable('notification_delivery_installations')) {
            ApiError::abort(503, 'NOTIFICATION_SCHEMA_NOT_READY', 'Thông báo hệ thống chưa sẵn sàng.');
        }
        $proof = (string) $r->header('X-Installation-Proof');
        if (! InstallationRegistry::validProof($proof)) {
            ApiError::abort(403, 'NOTIFICATION_INSTALLATION_PROOF_REQUIRED', 'Cần xác minh thiết bị này.');
        }

        return [$family, $proof];
    }

    private function result(array $data, int $status = 200)
    {
        $r = $this->respondSuccess($data, null, $status);
        $payload = $r->getData(true);
        $payload['meta']['contract'] = DeliveryConfig::CONTRACT;
        $r->setData($payload);
        $r->headers->set('Cache-Control','no-store');
        if (isset($data['binding_version'])) {
            $r->setEtag((string) $data['binding_version']);
        }

        return $r;
    }
}
