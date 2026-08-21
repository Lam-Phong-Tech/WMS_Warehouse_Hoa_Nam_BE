<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthLoginContractSecurityTest extends TestCase
{
    public function test_login_openapi_never_publishes_a_password_example_or_default(): void
    {
        $source = file_get_contents(base_path('Modules/Auth/app/Http/Controllers/AuthController.php'));

        $this->assertIsString($source);
        $matched = preg_match(
            "/new OA\\\\Property\\(\\s*property: 'password'.*?\\),/s",
            $source,
            $matches,
        );

        $this->assertSame(1, $matched, 'Không tìm thấy OpenAPI property password của /auth/login.');
        $passwordProperty = $matches[0];

        $this->assertStringNotContainsString('example:', $passwordProperty);
        $this->assertStringNotContainsString('default:', $passwordProperty);

        foreach ((array) config('golive.credential.known_defaults', []) as $knownDefault) {
            $literal = var_export((string) $knownDefault, true);
            $this->assertStringNotContainsString('example: '.$literal, $source);
            $this->assertStringNotContainsString('default: '.$literal, $source);
        }
    }
}
