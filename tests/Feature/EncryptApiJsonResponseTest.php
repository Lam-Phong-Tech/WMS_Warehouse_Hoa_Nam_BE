<?php

namespace Tests\Feature;

use App\Http\Middleware\EncryptApiJsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class EncryptApiJsonResponseTest extends TestCase
{
    public function test_json_response_is_encrypted_and_can_be_decrypted(): void
    {
        $pair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($pair);
        $pem = openssl_pkey_get_details($pair)['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        $request = Request::create('/api/v1/test', 'GET', server: [
            'HTTP_X_WMS_RESPONSE_KEY' => base64_encode($der),
        ]);

        $middleware = new EncryptApiJsonResponse;
        $response = $middleware->handle($request, fn () => response()->json([
            'success' => true, 'data' => ['name' => 'Hoa Nam'],
        ]));
        $packet = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('v1', $response->headers->get(EncryptApiJsonResponse::RESPONSE_HEADER));
        $this->assertArrayNotHasKey('success', $packet);
        $this->assertTrue(openssl_private_decrypt(base64_decode($packet['key']), $aesKey, $pair, OPENSSL_PKCS1_OAEP_PADDING));
        $plaintext = openssl_decrypt(base64_decode($packet['data']), 'aes-256-gcm', $aesKey,
            OPENSSL_RAW_DATA, base64_decode($packet['iv']), base64_decode($packet['tag']));
        $this->assertSame(['success' => true, 'data' => ['name' => 'Hoa Nam']], json_decode($plaintext, true));
    }

    public function test_invalid_key_stops_request_before_controller_runs(): void
    {
        $called = false;
        $request = Request::create('/api/v1/test', 'POST', server: ['HTTP_X_WMS_RESPONSE_KEY' => 'invalid']);
        $response = (new EncryptApiJsonResponse)->handle($request, function () use (&$called) {
            $called = true;

            return response()->json(['secret' => true]);
        });

        $this->assertFalse($called);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_framework_error_response_is_encrypted_too(): void
    {
        $pair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($pair);
        $pem = openssl_pkey_get_details($pair)['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);

        $response = $this->getJson('/api/v1/path-that-does-not-exist', [
            EncryptApiJsonResponse::REQUEST_HEADER => base64_encode($der),
        ]);

        $response->assertStatus(404);
        $response->assertHeader(EncryptApiJsonResponse::RESPONSE_HEADER, 'v1');
        $packet = $response->json();
        $this->assertArrayNotHasKey('message', $packet);
    }

    public function test_real_health_route_returns_encrypted_json(): void
    {
        $pair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($pair);
        $pem = openssl_pkey_get_details($pair)['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);

        $response = $this->getJson('/api/v1/health', [
            EncryptApiJsonResponse::REQUEST_HEADER => base64_encode($der),
        ]);

        $response->assertHeader(EncryptApiJsonResponse::RESPONSE_HEADER, 'v1');
        $packet = $response->json();
        $this->assertArrayNotHasKey('success', $packet);
        $this->assertTrue(openssl_private_decrypt(base64_decode($packet['key']), $aesKey, $pair, OPENSSL_PKCS1_OAEP_PADDING));
        $plaintext = openssl_decrypt(base64_decode($packet['data']), 'aes-256-gcm', $aesKey,
            OPENSSL_RAW_DATA, base64_decode($packet['iv']), base64_decode($packet['tag']));
        $this->assertIsArray(json_decode($plaintext, true));
    }
}
