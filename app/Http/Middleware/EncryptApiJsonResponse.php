<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional browser response encryption. TLS and authorization remain the security
 * boundary: the recipient browser necessarily has the private decryption key.
 */
class EncryptApiJsonResponse
{
    public const REQUEST_HEADER = 'X-WMS-Response-Key';

    public const RESPONSE_HEADER = 'X-WMS-Encrypted';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*') || ! $request->headers->has(self::REQUEST_HEADER)) {
            return $next($request);
        }

        $encoded = $request->header(self::REQUEST_HEADER);
        $der = is_string($encoded) && strlen($encoded) <= 1024
            ? base64_decode($encoded, true)
            : false;
        if ($der === false || strlen($der) < 100 || strlen($der) > 512) {
            return $this->invalidKey();
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 2048) {
            return $this->invalidKey();
        }

        $request->attributes->set('wms.response_public_key', $key);

        return self::encrypt($request, $next($request));
    }

    public static function encrypt(Request $request, Response $response): Response
    {
        $key = $request->attributes->get('wms.response_public_key');
        if (! $key || $response->headers->has(self::RESPONSE_HEADER)
            || $request->isMethod('HEAD') || $response->getStatusCode() === 204
            || $response->getStatusCode() === 304
            || ! str_contains(strtolower((string) $response->headers->get('Content-Type')), 'application/json')) {
            return $response;
        }

        $plaintext = $response->getContent();
        if (! is_string($plaintext)) {
            return $response;
        }

        $aesKey = random_bytes(32);
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        $wrapped = openssl_public_encrypt($aesKey, $encryptedKey, $key, OPENSSL_PKCS1_OAEP_PADDING);
        if ($ciphertext === false || ! $wrapped) {
            throw new \RuntimeException('Cannot encrypt API response');
        }

        $response->setContent(json_encode([
            'v' => 1,
            'alg' => 'RSA-OAEP-SHA1+A256GCM',
            'key' => base64_encode($encryptedKey),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR));
        $response->headers->set(self::RESPONSE_HEADER, 'v1');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->remove('Content-Length');
        $response->headers->remove('ETag');

        return $response;
    }

    private function invalidKey(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Khóa phản hồi không hợp lệ.',
            'error_code' => 'INVALID_RESPONSE_KEY',
        ], 400);
    }
}
