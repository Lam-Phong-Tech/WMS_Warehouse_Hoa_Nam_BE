<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\TrustedDevice;
use Modules\Auth\Models\User;
use Modules\Auth\Support\ClientInstallations;
use Modules\Auth\Support\InstallationIdentity;
use Modules\Auth\Support\P10ProfilePolicy;
use Modules\Auth\Support\P10SessionFamily;
use Modules\Auth\Support\TrustedDeviceService;

class TrustedDeviceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TrustedDeviceService $trustedDevices,
        private readonly InstallationIdentity $installationIdentity,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user() ?? auth('api')->user();
        if (! $user instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }

        $currentInstallationId = $this->resolveCurrentInstallationId($request, $user);

        $rows = TrustedDevice::query()
            ->where('user_id', $user->user_id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->orderByDesc('trusted_at')
            ->get();

        $installations = DB::table('auth_client_installations')
            ->where('user_id', $user->user_id)
            ->whereIn('id', $rows->pluck('installation_id')->all())
            ->get()
            ->keyBy('id');

        $data = $rows->map(function (TrustedDevice $trust) use ($installations, $currentInstallationId): array {
            $inst = $installations->get($trust->installation_id);
            $isCurrent = $currentInstallationId !== null
                && strcasecmp($currentInstallationId, (string) $trust->installation_id) === 0;

            return [
                'id' => (string) $trust->id,
                'installation_id' => (string) $trust->installation_id,
                'device_label' => $inst->device_label ?? 'Trình duyệt web',
                'platform' => $inst->platform ?? null,
                'client_type' => $inst->client_type ?? 'web',
                'is_current' => $isCurrent,
                'trusted_at' => $trust->trusted_at?->toIso8601String(),
                'last_used_at' => $trust->last_used_at?->toIso8601String(),
                'expires_at' => $trust->expires_at?->toIso8601String(),
                'can_revoke' => true,
            ];
        })->values()->all();

        return $this->respondSuccess($data)->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user() ?? auth('api')->user();
        if (! $user instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }

        if (! Str::isUuid($id)) {
            return $this->respondNotFound('Không tìm thấy thiết bị tin cậy.');
        }

        return DB::transaction(function () use ($request, $user, $id): JsonResponse {
            $trust = TrustedDevice::query()
                ->where('user_id', $user->user_id)
                ->where('id', $id)
                ->first();

            if ($trust === null) {
                return $this->respondNotFound('Không tìm thấy thiết bị tin cậy.');
            }

            $currentInstallationId = $this->resolveCurrentInstallationId($request, $user);
            $wasCurrent = $currentInstallationId !== null
                && strcasecmp($currentInstallationId, (string) $trust->installation_id) === 0;
            $alreadyRevoked = $trust->revoked_at !== null;

            $this->trustedDevices->revoke($trust, 'manual');

            $revokedSessions = 0;
            $activeRows = ClientInstallations::activeFamilies((string) $user->user_id)
                ->where('auth_device_id', (string) $trust->installation_id);

            foreach ($activeRows as $row) {
                $family = P10ProfilePolicy::family($row->auth_session_id, (string) $row->id);
                P10SessionFamily::revoke((string) $user->user_id, $family);
                $revokedSessions++;
            }

            if (! $alreadyRevoked) {
                $this->auditLogger->log(
                    'DEVICE_TRUST_REVOKED',
                    'trusted_device',
                    (string) $trust->id,
                    (string) $user->user_id,
                    $this->currentRequestId(),
                    [],
                    [
                        'installation_id' => (string) $trust->installation_id,
                        'was_current' => $wasCurrent,
                        'revoked_session_count' => $revokedSessions,
                    ],
                    'Trusted device revoked by user',
                    result: 'SUCCESS',
                );
            }

            $response = $this->respondSuccess([
                'id' => (string) $trust->id,
                'revoked' => true,
                'already_revoked' => $alreadyRevoked,
                'was_current' => $wasCurrent,
                'revoked_session_count' => $revokedSessions,
            ], 'Đã thu hồi thiết bị tin cậy.')->header('Cache-Control', 'private, no-store');

            if ($wasCurrent) {
                $response->headers->setCookie($this->trustedDevices->expiredCookie((string) $user->email));
            }

            return $response;
        });
    }

    private function resolveCurrentInstallationId(Request $request, User $user): ?string
    {
        $fromCookieOrHeader = $this->installationIdentity->currentInstallationId($request, $user);
        if ($fromCookieOrHeader !== null) {
            return $fromCookieOrHeader;
        }

        $rawTrust = $request->cookie($this->trustedDevices->cookieName((string) $user->email));
        if (is_string($rawTrust) && $rawTrust !== '') {
            $valid = $this->trustedDevices->findValid((string) $user->email, $rawTrust);
            if ($valid !== null) {
                return (string) $valid->installation_id;
            }
        }

        $currentFamily = P10SessionFamily::current($user, false);
        if ($currentFamily !== null) {
            $deviceId = P10SessionFamily::query((string) $user->user_id, $currentFamily)
                ->whereNotNull('auth_device_id')
                ->value('auth_device_id');
            if (is_string($deviceId) && $deviceId !== '') {
                return $deviceId;
            }
        }

        return null;
    }
}
