<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use Modules\AuditLog\Services\AuditLogger;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-AUDIT-001: 3 giá trị AUDIT_WRITE_POLICY phải có hành vi phân biệt được bằng test,
 * đặc biệt khi cả audit_log lẫn audit_outbox đều ghi lỗi (trường hợp mất toàn bộ
 * traceability) — đây là nhánh mà trước ADR này best_effort không hề khác outbox_required.
 */
class AuditLoggerWritePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function forcePrimaryAndOutboxWritesToFail(): void
    {
        Event::listen('eloquent.creating: '.AuditLog::class, static function (): never {
            throw new RuntimeException('forced-primary-audit-failure');
        });

        Event::listen('eloquent.creating: '.AuditOutbox::class, static function (): never {
            throw new RuntimeException('forced-outbox-failure');
        });
    }

    protected function tearDown(): void
    {
        Event::forget('eloquent.creating: '.AuditLog::class);
        Event::forget('eloquent.creating: '.AuditOutbox::class);

        parent::tearDown();
    }

    public function test_outbox_required_throws_when_primary_and_outbox_both_fail(): void
    {
        config(['audit.write_policy' => 'outbox_required']);
        $this->forcePrimaryAndOutboxWritesToFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Không thể ghi audit hoặc audit outbox');

        AuditLogger::record('TEST_ACTION', 'test_object', 'obj-1', null);
    }

    public function test_fail_closed_throws_when_primary_and_outbox_both_fail(): void
    {
        config(['audit.write_policy' => 'fail_closed']);
        $this->forcePrimaryAndOutboxWritesToFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Không thể ghi audit hoặc audit outbox');

        AuditLogger::record('TEST_ACTION', 'test_object', 'obj-1', null);
    }

    public function test_fail_closed_throws_when_only_primary_fails_even_if_outbox_succeeds(): void
    {
        config(['audit.write_policy' => 'fail_closed']);

        Event::listen('eloquent.creating: '.AuditLog::class, static function (): never {
            throw new RuntimeException('forced-primary-audit-failure');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('policy yêu cầu fail-closed');

        AuditLogger::record('TEST_ACTION', 'test_object', 'obj-1', null);
    }

    public function test_best_effort_does_not_throw_even_when_primary_and_outbox_both_fail(): void
    {
        config(['audit.write_policy' => 'best_effort']);
        $this->forcePrimaryAndOutboxWritesToFail();

        // Không được throw: nghiệp vụ gọi AuditLogger::record() dưới best_effort phải
        // tiếp tục chạy bình thường kể cả khi mất toàn bộ traceability audit.
        AuditLogger::record('TEST_ACTION', 'test_object', 'obj-1', null);

        $this->assertSame(0, DB::table('audit_log')->count());
        $this->assertSame(0, DB::table('audit_outbox')->count());
    }

    public function test_best_effort_does_not_throw_when_only_primary_fails(): void
    {
        config(['audit.write_policy' => 'best_effort']);

        Event::listen('eloquent.creating: '.AuditLog::class, static function (): never {
            throw new RuntimeException('forced-primary-audit-failure');
        });

        AuditLogger::record('TEST_ACTION', 'test_object', 'obj-1', null);

        $this->assertSame(0, DB::table('audit_log')->count());
        $this->assertSame(1, DB::table('audit_outbox')->count());
    }
}
