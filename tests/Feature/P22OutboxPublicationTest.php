<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use RuntimeException;
use Tests\TestCase;

/** Publication tests use a previously captured synthetic snapshot, not real NFC. */
class P22OutboxPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_publication_preserves_the_captured_snapshot_and_is_not_repeated(): void
    {
        $entry = $this->entry();
        $snapshot = $entry->payload['after_data']['nfc_history_v1'];
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(0);
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(0);
        $events = AuditLog::query()->where('object_id', $entry->payload['object_id'])->get();
        $this->assertCount(1, $events);
        $this->assertEquals($snapshot, $events[0]->after_data['nfc_history_v1']);
        $this->assertNull($events[0]->after_data['nfc_history_v1']['result']);
        $this->assertNotNull($entry->fresh()->processed_at);
        $this->assertSame($entry->payload, $entry->fresh()->payload);
    }

    public function test_failed_publication_keeps_snapshot_and_can_publish_once_after_backoff(): void
    {
        $entry = $this->entry();
        $fail = true;
        AuditLog::creating(function (AuditLog $event) use (&$fail): void {
            if ($fail && $event->action === 'ASSIGN_ITEM_NFC') {
                throw new RuntimeException('Isolated publication fault');
            }
        });
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(1);
        $this->assertSame(0, AuditLog::query()->where('object_id', $entry->payload['object_id'])->count());
        $this->assertNull($entry->fresh()->processed_at);
        $this->assertSame(1, (int) $entry->fresh()->attempts);
        $this->assertSame($entry->payload, $entry->fresh()->payload);
        $fail = false;
        $this->travelTo($entry->fresh()->available_at->addSecond());
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame(1, AuditLog::query()->where('object_id', $entry->payload['object_id'])->count());
    }

    public function test_failed_processed_marker_rolls_back_publication_before_backoff(): void
    {
        $entry = $this->entry();
        $fail = true;
        AuditOutbox::updating(function (AuditOutbox $row) use (&$fail): void {
            if ($fail && $row->isDirty('processed_at') && $row->processed_at !== null) {
                $fail = false;
                throw new RuntimeException('Isolated processed-marker fault');
            }
        });
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(1);
        $this->assertSame(0, AuditLog::query()->where('object_id', $entry->payload['object_id'])->count());
        $this->assertNull($entry->fresh()->processed_at);
        $this->assertSame(1, (int) $entry->fresh()->attempts);
        $this->travelTo($entry->fresh()->available_at->addSecond());
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(0);
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame(1, AuditLog::query()->where('object_id', $entry->payload['object_id'])->count());
        $this->assertSame($entry->payload, $entry->fresh()->payload);
    }

    public function test_delayed_publication_does_not_run_before_its_due_time(): void
    {
        $this->freezeTime();
        $entry = $this->entry();
        $due = now()->addMinutes(2);
        $entry->update(['available_at' => $due]);
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame(0, AuditLog::query()->where('object_id', $entry->payload['object_id'])->count());
        $this->assertNull($entry->fresh()->processed_at);
        $this->assertSame(0, (int) $entry->fresh()->attempts);
        $this->travelTo($due->copy()->addSecond());
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame(1, AuditLog::query()->where('object_id', $entry->payload['object_id'])->count());
        $this->assertNotNull($entry->fresh()->processed_at);
        $this->assertSame($entry->payload, $entry->fresh()->payload);
    }

    public function test_failed_publication_cannot_retry_until_backoff_is_due(): void
    {
        $this->freezeTime();
        $entry = $this->entry();
        $fail = true;
        AuditLog::creating(function (AuditLog $event) use (&$fail): void {
            if ($fail && $event->action === 'ASSIGN_ITEM_NFC') {
                throw new RuntimeException('Isolated backoff fault');
            }
        });
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(1);
        $this->assertSame(1, (int) $entry->fresh()->attempts);
        $fail = false;
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame(0, AuditLog::query()->where('object_id', $entry->payload['object_id'])->count());
        $this->assertSame(1, (int) $entry->fresh()->attempts);
        $this->assertNull($entry->fresh()->processed_at);
        $this->travel(3)->minutes();
        $this->artisan('audit:reconcile', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame(1, AuditLog::query()->where('object_id', $entry->payload['object_id'])->count());
        $this->assertSame($entry->payload, $entry->fresh()->payload);
    }

    private function entry(): AuditOutbox
    {
        $object = (string) Str::uuid();

        return AuditOutbox::create(['payload' => [
            'actor_id' => null, 'action' => 'ASSIGN_ITEM_NFC', 'object_type' => 'physical_code',
            'object_id' => $object, 'result' => null, 'created_at' => now()->subMinute()->toIso8601String(),
            'before_data' => [], 'after_data' => ['nfc_history_v1' => [
                'schema' => 'nfc-audit-snapshot-v1', 'physical_code_id' => $object,
                'warehouse_id' => (string) Str::uuid(), 'hardware_uid' => 'ISOLATED-SNAPSHOT',
                'serial_number' => null, 'result' => null,
            ]],
        ], 'attempts' => 0, 'available_at' => now()->subSecond()]);
    }
}
