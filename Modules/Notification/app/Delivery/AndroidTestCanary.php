<?php

namespace Modules\Notification\Delivery;

use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\P10SessionFamily;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationRecipient;
use Throwable;

/** External operator-controlled policy. Never cache an activation in a worker. */
final class AndroidTestCanary
{
    public const CONTRACT = 'notification-delivery-android-canary-v1';

    public function configured(): bool
    {
        return is_string(config('notification_delivery.canary_file')) && config('notification_delivery.canary_file') !== '';
    }

    public function environmentAllowed(): bool
    {
        return ! app()->environment('production')
            && config('notification_delivery.tier') === 'dev-test'
            && rtrim((string) config('app.url'), '/') === 'https://khohoanamdev.lptech.info.vn'
            && config('notification_delivery.project_id') === 'hoa-nam-scanner-devtest'
            && config('notification_delivery.android_package') === 'vn.info.lptech.wmshoanam';
    }

    public function active(): ?array
    {
        if (! $this->configured() || ! $this->environmentAllowed()
            || config('notification_delivery.enabled', false) || config('notification_delivery.self_test_enabled', false)) {
            return null;
        }
        $path = config('notification_delivery.canary_file');
        clearstatcache(true, $path);
        $resolved = realpath($path);
        $root = rtrim(str_replace('\\', '/', (string) realpath(base_path())), '/').'/';
        if (! is_string($resolved) || ! is_file($resolved) || ! is_readable($resolved)
            || str_starts_with(strtolower(str_replace('\\', '/', $resolved)), strtolower($root))
            || filesize($resolved) > 8192) {
            return null;
        }
        try {
            $m = json_decode((string) file_get_contents($resolved), true, 16, JSON_THROW_ON_ERROR);
            $keys = ['contract', 'enabled', 'run_id', 'user_id', 'auth_session_id', 'installation_id', 'proof_fingerprint',
                'base_version', 'binding_version', 'registration_id', 'starts_at', 'expires_at', 'source', 'max_messages', 'max_provider_attempts'];
            if (! is_array($m) || array_diff(array_keys($m), $keys) || array_diff($keys, array_keys($m))
                || $m['contract'] !== self::CONTRACT || $m['enabled'] !== true
                || $m['source'] !== 'system.notification.test' || $m['max_messages'] !== 1 || $m['max_provider_attempts'] !== 1) {
                return null;
            }
            foreach (['run_id', 'user_id', 'auth_session_id', 'installation_id'] as $k) {
                if (! is_string($m[$k]) || ! Str::isUuid($m[$k])) {
                    return null;
                }
            }
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $m['installation_id'])
                || ! is_string($m['proof_fingerprint']) || ! preg_match('/^[a-f0-9]{64}$/D', $m['proof_fingerprint'])) {
                return null;
            }
            if (! is_int($m['base_version']) || $m['base_version'] < 0 || ! is_int($m['binding_version'])
                || $m['binding_version'] < 1 || $m['binding_version'] > 9007199254740990) {
                return null;
            }
            if ($m['registration_id'] === null) {
                if ($m['binding_version'] !== $m['base_version'] + 1) {
                    return null;
                }
            } elseif (! is_string($m['registration_id']) || ! Str::isUuid($m['registration_id']) || $m['base_version'] !== $m['binding_version']) {
                return null;
            }
            foreach (['starts_at', 'expires_at'] as $k) {
                if (! is_string($m[$k]) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D', $m[$k])) {
                    return null;
                }
            }
            $start = CarbonImmutable::parse($m['starts_at']);
            $end = CarbonImmutable::parse($m['expires_at']);
            if ($end->getTimestamp() - $start->getTimestamp() > 1200 || $end->lessThanOrEqualTo($start)
                || now()->lt($start) || ! now()->lt($end)) {
                return null;
            }

            return $m;
        } catch (Throwable) {
            return null;
        }
    }

    public function fingerprint(User $user, string $family, string $id, string $proof): string
    {
        return hash_hmac('sha256', implode("\0", [self::CONTRACT, (string) $user->user_id, $family, $id,
            InstallationRegistry::proofHash($proof)]), (string) config('app.key'));
    }

    public function matches(User $user, string $family, string $id, string $proof): bool
    {
        $m = $this->active();

        return $m !== null && $user->status === 'ACTIVE' && $m['user_id'] === (string) $user->user_id
            && $m['auth_session_id'] === $family && $m['installation_id'] === $id
            && InstallationRegistry::validProof($proof)
            && hash_equals($m['proof_fingerprint'], $this->fingerprint($user, $family, $id, $proof))
            && P10SessionFamily::query((string) $user->user_id, $family)->active()->exists();
    }

    public function requestAdmitted(Request $r, string $family): bool
    {
        $id = (string) $r->header('X-Notification-Installation-ID');
        $origin = $r->header('Origin');
        $matches = ($origin === null || in_array($origin, (array) config('notification_delivery.allowed_web_origins', []), true))
            && $r->header('X-Notification-App-Package') === config('notification_delivery.android_package')
            && $this->matches($r->user(), $family, $id, (string) $r->header('X-Installation-Proof'));
        if (! $matches) {
            return false;
        }
        $m = $this->active();
        if ($m === null) {
            return false;
        }
        $i = DB::table('notification_delivery_installations')->where('id', $id)->first();
        if ($i === null) {
            return $m['base_version'] === 0 && $m['registration_id'] === null;
        }
        if ($i->platform !== 'ANDROID' || ! hash_equals($i->proof_hash, InstallationRegistry::proofHash((string) $r->header('X-Installation-Proof')))) {
            return false;
        }
        if ($i->current_binding_id === null) {
            return (int) $i->binding_version === 0 && $m['base_version'] === 0 && $m['registration_id'] === null;
        }
        $b = DB::table('notification_delivery_bindings')->where('id', $i->current_binding_id)->first();

        return $b !== null && $this->bindingMatches($m, $b, $i);
    }

    public function diagnostic(Request $r, string $family, string $id, array $registration): array
    {
        if (! $this->environmentAllowed() || $r->header('X-Notification-App-Package') !== config('notification_delivery.android_package')
            || ($r->header('Origin') !== null && ! in_array($r->header('Origin'), (array) config('notification_delivery.allowed_web_origins', []), true))) {
            ApiError::abort(403, 'NOTIFICATION_CANARY_DIAGNOSTIC_DENIED', 'Chẩn đoán chỉ dành cho ứng dụng DEV/TEST.');
        }

        return ['contract' => self::CONTRACT, 'actor_id' => (string) $r->user()->user_id,
            'auth_session_id' => $family, 'installation_id' => $id,
            'proof_fingerprint' => $this->fingerprint($r->user(), $family, $id, (string) $r->header('X-Installation-Proof')),
            'proof_status' => $registration['exists'] ? 'SERVER_PROOF_VERIFIED' : 'UNBOUND_CLIENT_PROPOSED',
            'hardware_attestation' => 'NOT_PROVIDED', 'canary_active' => $this->requestAdmitted($r, $family),
            'diagnostic_only' => true, 'max_window_seconds' => 1200];
    }

    public function assertRegistration(User $u, string $family, string $id, string $proof, array $body, object $i): void
    {
        $m = $this->active();
        if (! $this->matches($u, $family, $id, $proof) || $body['platform'] !== 'ANDROID') {
            ApiError::abort(403, 'NOTIFICATION_CANARY_SCOPE_DENIED', 'Thiết bị không thuộc phạm vi TEST đã xác minh.');
        }
        $version = (int) $i->binding_version;
        if ($version === $m['base_version'] && $m['registration_id'] === null && $i->current_binding_id === null) {
            return;
        }
        $b = DB::table('notification_delivery_bindings')->where('id', $i->current_binding_id)->first();
        if ($version !== $m['binding_version'] || $b === null || ! $this->bindingMatches($m, $b, $i)
            || ! hash_equals((string) $b->token_fingerprint, hash('sha256', $body['fcm_token']))) {
            ApiError::abort(409, 'NOTIFICATION_CANARY_REBIND_DENIED', 'Không thay đổi đăng ký trong lượt TEST này.');
        }
    }

    public function dispatchKey(array $m): string
    {
        return 'notification-android-canary:'.$m['run_id'];
    }

    public function assertTest(Request $r, string $family, object $b, object $i): array
    {
        $m = $this->active();
        if (! $this->requestAdmitted($r, $family) || $m === null || ! $this->bindingMatches($m, $b, $i)) {
            ApiError::abort(403, 'NOTIFICATION_CANARY_SCOPE_DENIED', 'Yêu cầu không thuộc thiết bị TEST đã xác minh.');
        }

        return $m;
    }

    public function assertUnspent(array $m, object $b): void
    {
        // Caller holds the same parent-user lock as registry and self-test writes.
        if (Notification::where('delivery_dispatch_key', hash('sha256', $this->dispatchKey($m)))->exists()
            || DB::table('notification_delivery_test_receipts')->where('registration_id', $b->id)->where('binding_version', $b->binding_version)->exists()) {
            ApiError::abort(409, 'NOTIFICATION_CANARY_TEST_ALREADY_USED', 'Lượt TEST đã có yêu cầu. Chỉ GET đối chiếu key gốc.');
        }
    }

    public function notificationMatches(array $m, Notification $n): bool
    {
        return $n->event_key === 'system.notification.test' && $n->source_module === 'notification'
            && $n->subject_id === null && $n->subject_type === null && (string) $n->actor_id === $m['user_id']
            && $n->delivery_dispatch_key === hash('sha256', $this->dispatchKey($m));
    }

    private function bindingMatches(array $m, object $b, object $i): bool
    {
        $u = User::find($m['user_id']);

        return $i->id === $m['installation_id'] && $i->platform === 'ANDROID' && $i->current_binding_id === $b->id
            && $b->installation_id === $i->id && $b->user_id === $m['user_id'] && $b->auth_session_id === $m['auth_session_id']
            && (int) $i->binding_version === $m['binding_version'] && (int) $b->binding_version === $m['binding_version']
            && ($m['registration_id'] === null || $m['registration_id'] === $b->id)
            && $b->status === 'ACTIVE' && CarbonImmutable::parse($b->expires_at)->isFuture()
            && $u !== null && $u->status === 'ACTIVE'
            && P10SessionFamily::query($m['user_id'], $m['auth_session_id'])->active()->exists()
            && hash_equals($m['proof_fingerprint'], hash_hmac('sha256', implode("\0", [self::CONTRACT, $m['user_id'],
                $m['auth_session_id'], $i->id, $i->proof_hash]), (string) config('app.key')));
    }

    public function enqueueAllowed(Notification $n, object $b, NotificationRecipient $r, ?array $ids): bool
    {
        $m = $this->active();
        $i = DB::table('notification_delivery_installations')->where('id', $b->installation_id)->first();

        return $m !== null && $i !== null && $ids === [$b->id] && (string) $r->user_id === $m['user_id']
            && $this->notificationMatches($m, $n) && $this->bindingMatches($m, $b, $i);
    }

    public function selected(): Builder
    {
        $m = $this->active();
        $q = DB::table('notification_deliveries');
        if ($m === null) {
            return $q->whereRaw('1=0');
        }

        return $q->whereIn('recipient_id', DB::table('notification_delivery_test_receipts as t')
            ->join('notification_recipients as r', 'r.id', '=', 't.recipient_id')
            ->join('notifications as n', 'n.id', '=', 'r.notification_id')
            ->join('notification_delivery_bindings as b', 'b.id', '=', 't.registration_id')
            ->where('t.user_id', $m['user_id'])->where('t.auth_session_id', $m['auth_session_id'])
            ->where('t.binding_version', $m['binding_version'])->where('b.installation_id', $m['installation_id'])
            ->where('n.delivery_dispatch_key', hash('sha256', $this->dispatchKey($m)))
            ->where('n.event_key', 'system.notification.test')->where('n.source_module', 'notification')
            ->whereNull('n.subject_id')->whereNull('n.subject_type')->where('n.actor_id', $m['user_id'])
            ->whereColumn('r.user_id', 't.user_id')->select('t.recipient_id'))
            ->whereIn('registration_id', DB::table('notification_delivery_bindings')->where('installation_id', $m['installation_id'])
                ->where('user_id', $m['user_id'])->where('auth_session_id', $m['auth_session_id'])->where('binding_version', $m['binding_version'])->select('id'))
            ->where('binding_version', $m['binding_version']);
    }

    public function deliveryAllowed(object $row): bool
    {
        $m = $this->active();
        if ($m === null || ! $this->selected()->where('id', $row->id)->exists()) {
            return false;
        }
        $b = DB::table('notification_delivery_bindings')->where('id', $row->registration_id)->first();
        $i = $b === null ? null : DB::table('notification_delivery_installations')->where('id', $b->installation_id)->first();
        $r = NotificationRecipient::find($row->recipient_id);

        return $b !== null && $i !== null && $r !== null && $this->bindingMatches($m, $b, $i)
            && app(NotificationDeliveryPolicy::class)->reason($r, $b) === null;
    }

    public function providerAllowed(string $token, array $data, int $attempt = 0, ?string $lease = null): bool
    {
        $row = DB::table('notification_deliveries')->where('id', $data['delivery_id'] ?? null)->first();
        if ($row === null || $row->state !== 'SENDING' || (int) $row->attempts !== $attempt
            || ! is_string($row->lease_token) || ! CarbonImmutable::parse($row->lease_expires_at)->isFuture()
            || ($lease !== null && $lease !== $row->lease_token) || ! $this->deliveryAllowed($row)) {
            return false;
        }
        $b = DB::table('notification_delivery_bindings')->where('id', $row->registration_id)->first();

        return $b !== null && is_string($b->token_fingerprint) && hash_equals($b->token_fingerprint, hash('sha256', $token))
            && ($data['recipient_id'] ?? null) === (string) $row->recipient_id
            && ($data['registration_id'] ?? null) === $row->registration_id
            && ($data['binding_version'] ?? null) === (string) $row->binding_version
            && ($data['expires_at'] ?? null) === CarbonImmutable::parse($row->expires_at)->utc()->toIso8601String();
    }

    public function claimProvider(string $token, array $data): ?string
    {
        if (! $this->providerAllowed($token, $data)) {
            return null;
        }
        $row = DB::table('notification_deliveries')->where('id', $data['delivery_id'])->first();
        $changed = DB::table('notification_deliveries')->where('id', $row->id)->where('state', 'SENDING')
            ->where('lease_token', $row->lease_token)->where('attempts', 0)->update(['attempts' => 1]);

        return $changed === 1 ? $row->lease_token : null;
    }
}
