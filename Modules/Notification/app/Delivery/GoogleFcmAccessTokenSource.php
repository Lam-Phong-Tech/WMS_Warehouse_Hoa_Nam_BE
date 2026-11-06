<?php

namespace Modules\Notification\Delivery;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class GoogleFcmAccessTokenSource implements FcmAccessTokenSource
{
    public function accessToken(): string
    {
        if (! app(DeliveryConfig::class)->transportReady()) {
            throw new RuntimeException('FCM_NOT_CONFIGURED');
        }
        $raw = file_get_contents((string) config('notification_delivery.credentials_file'));
        $key = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (($key['token_uri'] ?? 'https://oauth2.googleapis.com/token') !== 'https://oauth2.googleapis.com/token') {
            throw new RuntimeException('FCM_CREDENTIAL_ENDPOINT_INVALID');
        }
        $cacheKey = 'notification-fcm-oauth:'.hash('sha256', $raw);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && is_string($cached['token'] ?? null) && ($cached['expires_at'] ?? 0) > time() + 60) {
            return $cached['token'];
        }
        $credentials = new ServiceAccountCredentials('https://www.googleapis.com/auth/firebase.messaging', $key);
        $token = $credentials->fetchAuthToken(function (RequestInterface $request) {
            if ((string) $request->getUri() !== 'https://oauth2.googleapis.com/token' || $request->getMethod() !== 'POST') {
                throw new RuntimeException('FCM_CREDENTIAL_ENDPOINT_INVALID');
            }

            return Http::connectTimeout(5)->timeout(10)->send('POST', 'https://oauth2.googleapis.com/token', ['headers' => $request->getHeaders(), 'body' => (string) $request->getBody()])->toPsrResponse();
        });
        $access = $token['access_token'] ?? null;
        $expires = $token['expires_in'] ?? null;
        if (! is_string($access) || $access === '' || ! is_numeric($expires) || (int) $expires <= 60) {
            throw new RuntimeException('FCM_CREDENTIALS_UNAVAILABLE');
        }
        $seconds = min(3600, (int) $expires);
        Cache::put($cacheKey, ['token' => $access, 'expires_at' => time() + $seconds], $seconds - 60);

        return $access;
    }
}
