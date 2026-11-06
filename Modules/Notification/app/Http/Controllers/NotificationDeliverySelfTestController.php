<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\P10SessionFamily;
use Modules\Notification\Delivery\AndroidTestCanary;
use Modules\Notification\Delivery\DeliveryConfig;
use Modules\Notification\Delivery\InstallationRegistry;
use Modules\Notification\Models\NotificationRecipient;
use Modules\Notification\Services\NotificationService;

final class NotificationDeliverySelfTestController extends Controller
{
    use ApiResponse;

    public function store(Request $r, DeliveryConfig $cfg)
    {
        $family = $this->context($r, $cfg);
        $data = $r->json()->all();
        if (array_diff(array_keys($data), ['registration_id', 'binding_version', 'request_key']) !== [] || $r->query->count()
            || ! is_string($data['registration_id'] ?? null) || ! Str::isUuid($data['registration_id']) || ! is_string($data['request_key'] ?? null) || ! Str::isUuid($data['request_key'])
            || ! is_int($data['binding_version'] ?? null) || $data['binding_version'] < 1) {
            ApiError::abort(422, 'NOTIFICATION_SELF_TEST_INVALID', 'Yêu cầu gửi thử không hợp lệ.');
        }

        return DB::transaction(function () use ($r, $family, $data) {
            $u = User::whereKey($r->user()->user_id)->lockForUpdate()->first();
            if ($u === null || $u->status !== 'ACTIVE' || ! P10SessionFamily::query((string) $u->user_id, $family)->active()->exists()) {
                ApiError::abort(401, 'AUTH_SESSION_EXPIRED', 'Phiên đăng nhập không còn hợp lệ.');
            }
            $old = DB::table('notification_delivery_test_receipts')->where('id', $data['request_key'])->first();
            $canary = app(AndroidTestCanary::class);
            if ($old !== null) {
                if ($old->user_id !== (string) $u->user_id || $old->auth_session_id !== $family || $old->registration_id !== $data['registration_id'] || (int) $old->binding_version !== $data['binding_version']) {
                    ApiError::abort(409, 'NOTIFICATION_SELF_TEST_SCOPE_MISMATCH', 'Yêu cầu thuộc ngữ cảnh khác.');
                }
                [$binding,$installation] = $this->proof($r, $old->registration_id);
                if ($canary->configured()) {
                    $m = $canary->assertTest($r, $family, $binding, $installation);
                    $notice = NotificationRecipient::find($old->recipient_id)?->notification;
                    if ($notice === null || ! $canary->notificationMatches($m, $notice)) {
                        ApiError::abort(409, 'NOTIFICATION_CANARY_OLD_REQUEST', 'Chỉ GET đối chiếu yêu cầu cũ.');
                    }
                }

                return $this->result($this->present($old));
            }
            [$b,$i] = $this->proof($r, $data['registration_id'], true);
            if ($b->user_id !== (string) $u->user_id || $b->auth_session_id !== $family) {
                ApiError::abort(403, 'NOTIFICATION_BINDING_NOT_OWNED', 'Chỉ gửi thử đến đăng ký của chính phiên này.');
            }
            if ((int) $i->binding_version !== $data['binding_version'] || $i->current_binding_id !== $b->id || $b->status !== 'ACTIVE' || ! CarbonImmutable::parse($b->expires_at)->isFuture()) {
                ApiError::abort(412, 'NOTIFICATION_BINDING_STALE', 'Cần xác minh lại đăng ký thiết bị.');
            }
            $manifest = $canary->configured() ? $canary->assertTest($r, $family, $b, $i) : null;
            if ($manifest !== null) {
                $canary->assertUnspent($manifest, $b);
            }
            $rate = 'notification-self:'.hash('sha256', (string) $u->user_id);
            if (RateLimiter::tooManyAttempts($rate, 1)) {
                ApiError::abort(429, 'NOTIFICATION_SELF_TEST_RATE_LIMIT', 'Vui lòng chờ một phút trước khi gửi thử lại.');
            }
            RateLimiter::hit($rate, 60);
            $n = app(NotificationService::class)->notify('system.notification.test', 'TEST – Thông báo thiết bị', 'Kiểm thử FCM đến thiết bị vừa bật thông báo. Không thay đổi dữ liệu kho.', [(string) $u->user_id], sourceModule: 'notification', actorId: (string) $u->user_id, dispatchKey: $manifest === null ? 'notification-self:'.$data['request_key'] : $canary->dispatchKey($manifest), deliveryRegistrationIds: [$b->id], broadcastInApp: $manifest === null);
            $recipient = $n === null ? null : NotificationRecipient::where('notification_id', $n->id)->where('user_id', $u->user_id)->first();
            if ($recipient === null) {
                ApiError::abort(503, 'NOTIFICATION_SELF_TEST_SOURCE_UNAVAILABLE', 'Chưa ghi nhận được thông báo gửi thử.');
            }
            if ($manifest !== null && ! DB::table('notification_deliveries')->where('recipient_id', $recipient->id)->where('registration_id', $b->id)->where('binding_version', $data['binding_version'])->exists()) {
                ApiError::abort(503, 'NOTIFICATION_CANARY_CLOSED', 'Phạm vi TEST đã đóng. Không tự gửi lại.');
            }
            DB::table('notification_delivery_test_receipts')->insert(['id' => $data['request_key'], 'user_id' => $u->user_id, 'auth_session_id' => $family, 'registration_id' => $b->id, 'binding_version' => $data['binding_version'], 'recipient_id' => $recipient->id, 'created_at' => now()->utc()->toIso8601String()]);

            return $this->result($this->present(DB::table('notification_delivery_test_receipts')->where('id', $data['request_key'])->first()), 201);
        });
    }

    public function show(Request $r, string $requestKey, DeliveryConfig $cfg)
    {
        $family = $this->context($r, $cfg, true);
        if (! Str::isUuid($requestKey)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không có yêu cầu gửi thử.');
        }
        $row = DB::table('notification_delivery_test_receipts')->where('id', $requestKey)->where('user_id', $r->user()->user_id)->where('auth_session_id', $family)->first();
        if ($row === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không có yêu cầu gửi thử.');
        }$this->proof($r, $row->registration_id);

        return $this->result($this->present($row));
    }

    private function context(Request $r, DeliveryConfig $cfg, bool $readOnly = false): string
    {
        $family = P10SessionFamily::current($r->user(), true);
        $canary = app(AndroidTestCanary::class);
        $allowed = $readOnly ? (! app()->environment('production') && ($canary->configured() ? $canary->environmentAllowed() : $cfg->devEnvironment())) : ($canary->configured()
            ? $canary->requestAdmitted($r, $family) && $cfg->transportReady() : $cfg->selfTestEnabled());
        if (! $allowed || ! Schema::hasTable('notification_delivery_test_receipts')) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Chức năng gửi thử chưa được bật.');
        }
        if ($r->user()->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCOUNT_INACTIVE', 'Tài khoản chưa được phép gửi thử.');
        }

        return $family;
    }

    private function proof(Request $r, string $id, bool $lock = false): array
    {
        $b = DB::table('notification_delivery_bindings')->where('id', $id)->first();
        $q = $b === null ? null : DB::table('notification_delivery_installations')->where('id', $b->installation_id);
        if ($lock && $q !== null) {
            $q->lockForUpdate();
        }$i = $q?->first();
        // Match registry writes: installation before binding, then re-read the
        // binding under lock after any concurrent replacement or opt-out.
        if ($lock && $i !== null) {
            $b = DB::table('notification_delivery_bindings')->where('id', $id)->where('installation_id', $i->id)->lockForUpdate()->first();
        }
        $p = (string) $r->header('X-Installation-Proof');
        if ($b === null || $i === null || ! InstallationRegistry::validProof($p) || ! hash_equals($i->proof_hash, InstallationRegistry::proofHash($p))) {
            ApiError::abort(403, 'NOTIFICATION_INSTALLATION_PROOF_REQUIRED', 'Không xác minh được thiết bị gửi thử.');
        }

        return [$b, $i];
    }

    private function present(object $row): array
    {
        $delivery = DB::table('notification_deliveries')->where('recipient_id', $row->recipient_id)->where('registration_id', $row->registration_id)->where('binding_version', $row->binding_version)->first();
        $accepted = $delivery?->provider_accepted_at;
        $state = $delivery?->state;
        if ($state === 'SENDING') {
            $state = $delivery->lease_expires_at !== null && ! CarbonImmutable::parse($delivery->lease_expires_at)->isFuture() ? 'UNKNOWN' : 'PENDING';
        }

        return ['request_key' => $row->id, 'recipient_id' => $row->recipient_id, 'registration_id' => $row->registration_id, 'binding_version' => (int) $row->binding_version, 'state' => $delivery === null ? 'SOURCE_UNAVAILABLE' : $state, 'delivery_id' => $delivery?->id, 'provider_accepted_at' => $accepted === null ? null : CarbonImmutable::parse($accepted)->utc()->toIso8601String()];
    }

    private function result(array $data, int $status = 200)
    {
        $r = $this->respondSuccess($data, null, $status);
        $p = $r->getData(true);
        $p['meta']['contract'] = DeliveryConfig::CONTRACT;

        return $r->setData($p)->header('Cache-Control', 'no-store');
    }
}
