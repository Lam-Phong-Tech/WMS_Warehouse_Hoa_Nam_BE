<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * WWHN-309 — handover vận hành.
 *
 * Lớp lỗi lặp lại của dự án (DEV219-P1-04R audit outbox, DEV330-P1-01 import error file):
 * lệnh artisan tồn tại và có test, nhưng không process nào gọi ⇒ nghĩa vụ vận hành/tuân thủ
 * không bao giờ được thực thi trên production. Test này khoá danh sách job nền bắt buộc và
 * các thuộc tính an toàn khi chạy nhiều node.
 */
class Wwhn309ScheduledOperationsTest extends TestCase
{
    /**
     * command => lý do bắt buộc phải chạy nền (dùng trong message khi fail).
     *
     * @var array<string, string>
     */
    private const REQUIRED_SCHEDULED_COMMANDS = [
        'inventory:reconcile' => 'SRS §7.5 — lệch ledger/balance phải sinh Reconciliation_Incident chủ động',
        'audit:reconcile' => 'audit_outbox không được rút ra thì audit trail mất và outbox phình vô hạn',
        'import:purge-error-files' => 'file lỗi CSV hết hạn phải bị xoá vật lý, nếu không đĩa phình vô hạn',
        'warranty:purge-pii' => 'SRS §13.8 / DR-PRIV-001 — PII hết retention phải được anonymize',
    ];

    /**
     * @return array<int, Event>
     */
    private function scheduledEvents(): array
    {
        return app(Schedule::class)->events();
    }

    private function findEvent(string $command): ?Event
    {
        foreach ($this->scheduledEvents() as $event) {
            if (str_contains((string) $event->command, "artisan' {$command}")
                || str_contains((string) $event->command, "artisan\" {$command}")
                || str_contains((string) $event->command, " {$command}")) {
                return $event;
            }
        }

        return null;
    }

    public function test_required_background_jobs_are_scheduled(): void
    {
        foreach (self::REQUIRED_SCHEDULED_COMMANDS as $command => $reason) {
            $this->assertNotNull(
                $this->findEvent($command),
                "Thiếu Schedule::command('{$command}') trong routes/console.php — {$reason}."
            );
        }
    }

    public function test_scheduled_jobs_are_safe_on_multiple_nodes(): void
    {
        foreach (array_keys(self::REQUIRED_SCHEDULED_COMMANDS) as $command) {
            $event = $this->findEvent($command);
            $this->assertNotNull($event, $command);

            $this->assertTrue(
                $event->withoutOverlapping,
                "{$command} phải withoutOverlapping() để lần chạy trước chưa xong không bị chồng lấn."
            );
            $this->assertTrue(
                $event->onOneServer,
                "{$command} phải onOneServer() để nhiều node không cùng ghi một tập dữ liệu."
            );
            $this->assertNotSame(
                '* * * * *',
                $event->expression,
                "{$command} không được chạy mỗi phút không kiểm soát."
            );
        }
    }

    public function test_purge_jobs_do_not_run_in_dry_run_mode(): void
    {
        foreach (['warranty:purge-pii', 'import:purge-error-files'] as $command) {
            $event = $this->findEvent($command);
            $this->assertNotNull($event, $command);
            $this->assertStringNotContainsString(
                '--dry-run',
                (string) $event->command,
                "{$command} chạy theo lịch với --dry-run thì không có gì bị xoá/anonymize thật."
            );
        }
    }

    public function test_retention_and_reconcile_commands_are_registered(): void
    {
        $registered = array_keys(Artisan::all());

        foreach (array_keys(self::REQUIRED_SCHEDULED_COMMANDS) as $command) {
            $this->assertContains($command, $registered, "Lệnh {$command} phải tồn tại để lịch chạy được.");
        }
    }
}
