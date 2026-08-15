<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use Modules\AuditLog\Services\AuditLogger;
use RuntimeException;
use Tests\TestCase;

class Wwhn219AuditPolicySchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_audit_policy_fails_safe_before_writing(): void
    {
        config(['audit.write_policy' => 'typo_policy']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('audit.write_policy không hợp lệ');

        AuditLogger::record('DEV219_INVALID_POLICY', 'test', '1', null);
    }

    public function test_best_effort_explicitly_continues_when_primary_and_outbox_both_fail(): void
    {
        config(['audit.write_policy' => 'best_effort']);
        $auditEvent = 'eloquent.creating: '.AuditLog::class;
        $outboxEvent = 'eloquent.creating: '.AuditOutbox::class;

        Event::listen($auditEvent, static function (): never {
            throw new RuntimeException('forced primary audit failure');
        });
        Event::listen($outboxEvent, static function (): never {
            throw new RuntimeException('forced outbox failure');
        });

        try {
            AuditLogger::record('DEV219_BEST_EFFORT', 'test', '2', null, 'req-best-effort');
        } finally {
            Event::forget($auditEvent);
            Event::forget($outboxEvent);
        }

        $this->assertDatabaseCount('audit_log', 0);
        $this->assertDatabaseCount('audit_outbox', 0);
    }

    public function test_outbox_required_fails_when_primary_and_outbox_both_fail(): void
    {
        config(['audit.write_policy' => 'outbox_required']);
        $auditEvent = 'eloquent.creating: '.AuditLog::class;
        $outboxEvent = 'eloquent.creating: '.AuditOutbox::class;

        Event::listen($auditEvent, static function (): never {
            throw new RuntimeException('forced primary audit failure');
        });
        Event::listen($outboxEvent, static function (): never {
            throw new RuntimeException('forced outbox failure');
        });

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Không thể ghi audit hoặc audit outbox');
            AuditLogger::record('DEV219_OUTBOX_REQUIRED', 'test', '3', null, 'req-outbox-required');
        } finally {
            Event::forget($auditEvent);
            Event::forget($outboxEvent);
        }
    }

    public function test_fail_closed_fails_after_durable_outbox_fallback(): void
    {
        config(['audit.write_policy' => 'fail_closed']);
        $auditEvent = 'eloquent.creating: '.AuditLog::class;

        Event::listen($auditEvent, static function (): never {
            throw new RuntimeException('forced primary audit failure');
        });

        try {
            try {
                AuditLogger::record('DEV219_FAIL_CLOSED', 'test', '4', null, 'req-fail-closed');
                $this->fail('fail_closed must throw after primary audit failure');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('policy yêu cầu fail-closed', $error->getMessage());
            }
        } finally {
            Event::forget($auditEvent);
        }

        $this->assertDatabaseCount('audit_log', 0);
        $this->assertDatabaseCount('audit_outbox', 1);
    }

    public function test_reconciliation_commands_are_registered_with_scheduler(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());
        $commands = $events->pluck('command')->filter()->implode("\n");

        $this->assertStringContainsString('audit:reconcile', $commands);
        $this->assertStringContainsString('inventory:reconcile', $commands);

        Artisan::call('schedule:list');
        $output = Artisan::output();
        $this->assertStringContainsString('audit:reconcile', $output);
        $this->assertStringContainsString('inventory:reconcile', $output);
    }
}
