<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_local_vite_origin_can_preflight_auth_login_with_credentials(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-request-id',
        ])->options('/api/v1/auth/login');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');

        $allowedMethods = (string) $response->headers->get('Access-Control-Allow-Methods');
        $allowedHeaders = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));

        $this->assertStringContainsString('POST', $allowedMethods);
        $this->assertStringContainsString('content-type', $allowedHeaders);
        $this->assertStringContainsString('x-request-id', $allowedHeaders);
    }

    public function test_loopback_ip_vite_origin_is_allowed_too(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5173',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/auth/login');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5173');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_unknown_origin_is_not_granted_cors_access(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/auth/login');

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
