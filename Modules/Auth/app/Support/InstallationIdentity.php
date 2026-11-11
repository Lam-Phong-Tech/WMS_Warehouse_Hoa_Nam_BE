<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class InstallationIdentity
{
    public const COOKIE_NAME = 'wms_installation_id';

    /**
     * @return array{installation_id: string, cookie: ?Cookie}
     */
    public function resolve(Request $request, User $user): array
    {
        $metadata = [
            'device_id' => $request->header('X-Device-Id'),
            'device_label' => $request->header('X-Device-Label'),
            'platform' => $request->header('X-Client-Platform'),
            'client_type' => $request->header('X-Client-Type'),
        ];

        foreach ($metadata as $field => $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                ApiError::abort(422, 'INVALID_DEVICE_METADATA', 'Thông tin ứng dụng không đúng định dạng UTF-8.', ['fields' => [$field]]);
            }
        }

        $hasDeviceHeader = is_string($metadata['device_id']) && trim($metadata['device_id']) !== '';
        if ($hasDeviceHeader) {
            $validated = Validator::make([
                'device_id' => trim((string) $metadata['device_id']),
                'device_label' => $metadata['device_label'],
                'platform' => $metadata['platform'],
                'client_type' => $metadata['client_type'],
            ], [
                'device_id' => ['required', 'uuid:4'],
                'device_label' => ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
                'platform' => ['nullable', 'string', 'max:80', 'not_regex:/[\x00-\x1F\x7F]/'],
                'client_type' => ['nullable', 'in:web,app'],
            ])->validate();
            $headerUuid = strtolower($validated['device_id']);
        } else {
            $headerUuid = null;
        }

        $cookieUuid = $this->decryptCookieUuid($request);

        $clientDeviceId = $cookieUuid ?? $headerUuid ?? strtolower((string) Str::uuid());

        $existing = DB::table('auth_client_installations')
            ->where('user_id', $user->user_id)
            ->where(function ($query) use ($clientDeviceId): void {
                $query->where('client_device_id', $clientDeviceId)
                    ->orWhere('id', $clientDeviceId);
            })
            ->first();

        $id = $existing !== null ? (string) $existing->id : (string) Str::uuid();
        $storedClientDeviceId = $existing !== null ? (string) $existing->client_device_id : $clientDeviceId;

        $label = $this->cleanHeader($request->header('X-Device-Label'), 120)
            ?? ($existing->device_label ?? ($hasDeviceHeader ? null : $this->inferDeviceLabel((string) $request->userAgent())));
        $platform = $this->cleanHeader($request->header('X-Client-Platform'), 80)
            ?? ($existing->platform ?? ($hasDeviceHeader ? null : $this->inferPlatform((string) $request->userAgent())));
        $clientTypeHeader = $this->cleanHeader($request->header('X-Client-Type'), 16);
        $clientType = in_array($clientTypeHeader, ['web', 'app'], true)
            ? $clientTypeHeader
            : ($existing->client_type ?? ($hasDeviceHeader ? null : 'web'));

        $now = now('UTC');

        DB::table('auth_client_installations')->updateOrInsert(
            [
                'user_id' => $user->user_id,
                'client_device_id' => $storedClientDeviceId,
            ],
            [
                'id' => $id,
                'device_label' => $label,
                'platform' => $platform,
                'client_type' => $clientType,
                'first_seen_at' => $existing->first_seen_at ?? $now,
                'last_seen_at' => $now,
            ]
        );

        $cookie = $this->makeCookie($storedClientDeviceId);

        foreach ([$request, request()] as $req) {
            $req->attributes->set('wms.installation_cookie', $cookie);
            $req->attributes->set('wms.installation_id', $id);
        }

        return [
            'installation_id' => $id,
            'cookie' => $cookie,
        ];
    }

    public function currentInstallationId(Request $request, User $user): ?string
    {
        $attr = $request->attributes->get('wms.installation_id');
        if (is_string($attr) && $attr !== '') {
            return $attr;
        }

        $cookieUuid = $this->decryptCookieUuid($request);
        $headerUuid = $this->validHeaderUuid($request);
        $candidate = $cookieUuid ?? $headerUuid;

        if ($candidate !== null) {
            $row = DB::table('auth_client_installations')
                ->where('user_id', $user->user_id)
                ->where(function ($query) use ($candidate): void {
                    $query->where('client_device_id', $candidate)
                        ->orWhere('id', $candidate);
                })
                ->first();

            if ($row !== null) {
                return (string) $row->id;
            }
        }

        return null;
    }

    private function decryptCookieUuid(Request $request): ?string
    {
        $raw = $request->cookie(self::COOKIE_NAME);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $value = trim($raw);
        for ($i = 0; $i < 2; $i++) {
            $candidate = strtolower(CookieValuePrefix::remove($value));
            if (Str::isUuid($candidate)) {
                return $candidate;
            }
            if (Str::isUuid(strtolower($value))) {
                return strtolower($value);
            }
            try {
                $value = Crypt::decrypt($value, false);
            } catch (Throwable) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (Throwable) {
                    break;
                }
            }
        }

        $candidate = strtolower(CookieValuePrefix::remove($value));
        if (Str::isUuid($candidate)) {
            return $candidate;
        }

        return Str::isUuid(strtolower($value)) ? strtolower($value) : null;
    }

    private function validHeaderUuid(Request $request): ?string
    {
        $raw = $request->header('X-Device-Id');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        $trimmed = strtolower(trim($raw));

        return Str::isUuid($trimmed) ? $trimmed : null;
    }

    private function cleanHeader(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }

    private function inferPlatform(string $userAgent): ?string
    {
        $ua = strtolower($userAgent);
        if (str_contains($ua, 'windows')) {
            return 'Windows';
        }
        if (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh')) {
            return 'macOS';
        }
        if (str_contains($ua, 'android')) {
            return 'Android';
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad')) {
            return 'iOS';
        }
        if (str_contains($ua, 'linux')) {
            return 'Linux';
        }

        return null;
    }

    private function inferDeviceLabel(string $userAgent): ?string
    {
        $ua = strtolower($userAgent);
        if (str_contains($ua, 'edg/')) {
            return 'Microsoft Edge';
        }
        if (str_contains($ua, 'chrome/')) {
            return 'Chrome';
        }
        if (str_contains($ua, 'firefox/')) {
            return 'Firefox';
        }
        if (str_contains($ua, 'safari/')) {
            return 'Safari';
        }

        return 'Trình duyệt web';
    }

    private function makeCookie(string $clientDeviceId): Cookie
    {
        $prefixed = CookieValuePrefix::create(self::COOKIE_NAME, Crypt::getKey()).$clientDeviceId;
        $encrypted = Crypt::encrypt($prefixed, false);
        $path = (string) config('login_security.trusted_device.cookie_path', '/api/v1/auth');
        $secure = (bool) config('login_security.trusted_device.cookie_secure', true);
        $sameSite = strtolower((string) config('login_security.trusted_device.cookie_same_site', 'lax'));

        return Cookie::create(
            name: self::COOKIE_NAME,
            value: $encrypted,
            expire: now()->getTimestamp() + (365 * 86400),
            path: $path,
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: $sameSite,
        );
    }
}
