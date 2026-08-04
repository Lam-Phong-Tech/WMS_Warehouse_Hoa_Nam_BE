<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class Wwhn172AuditFailClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_failure_rolls_back_business_outbox_and_hides_stack_trace_even_in_debug(): void
    {
        config([
            'app.debug' => true,
            'audit.write_policy' => 'fail_closed',
        ]);
        $this->seed(AuthDatabaseSeeder::class);
        $user = User::query()->where('email', 'test-super-admin@hoanam.com')->firstOrFail();
        $token = JWTAuth::fromUser($user);
        $eventName = 'eloquent.creating: '.AuditLog::class;

        Event::listen($eventName, static function (): never {
            throw new \RuntimeException('sensitive-audit-failure /vendor/project/secret.php:77');
        });

        try {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
                'Idempotency-Key' => 'wwhn172-audit-fail-001',
            ])->postJson('/api/v1/skus', [
                'code' => 'AUDIT-ROLLBACK-001',
                'name' => 'Must rollback',
            ]);
        } finally {
            Event::forget($eventName);
        }

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INTERNAL_SERVER_ERROR')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('line')
            ->assertJsonMissingPath('trace');

        $payload = $response->getContent();
        $this->assertStringNotContainsString('sensitive-audit-failure', $payload);
        $this->assertStringNotContainsString('/vendor/', $payload);
        $this->assertDatabaseMissing('products', ['code' => 'AUDIT-ROLLBACK-001']);
        $this->assertSame(0, DB::table('audit_log')->count());
        $this->assertSame(0, DB::table('audit_outbox')->count());
        $this->assertSame(0, DB::table('idempotency_keys')->where('idem_key', 'wwhn172-audit-fail-001')->count());
    }
}
