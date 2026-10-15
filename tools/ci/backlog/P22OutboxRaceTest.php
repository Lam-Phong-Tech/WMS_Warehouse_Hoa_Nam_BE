<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use Tests\Feature\Concerns\RunsIsolatedPostgresRace;
use Tests\TestCase;

class P22OutboxRaceTest extends TestCase
{
    use RunsIsolatedPostgresRace;

    public function test_two_publishers_contend_and_only_one_audit_event_is_committed(): void
    {
        $object = (string) Str::uuid();
        $snapshot = ['schema' => 'nfc-audit-snapshot-v1', 'physical_code_id' => $object,
            'warehouse_id' => (string) Str::uuid(), 'hardware_uid' => 'ISOLATED-RACE', 'result' => null];
        $entry = AuditOutbox::create(['payload' => ['action' => 'ASSIGN_ITEM_NFC',
            'object_type' => 'physical_code', 'object_id' => $object, 'actor_id' => null,
            'result' => null, 'created_at' => now()->subMinute()->toIso8601String(),
            'after_data' => ['nfc_history_v1' => $snapshot], 'before_data' => []],
            'attempts' => 0, 'available_at' => now()->subSecond()]);
        $publish = fn (): array => ['exit' => Artisan::call('audit:reconcile', ['--limit' => 1])];
        [$first, $second] = $this->runDatabaseRace('audit_outbox', $publish, $publish);
        $this->assertSame(0, $first['exit']);
        $this->assertSame(0, $second['exit']);
        $events = AuditLog::query()->where('object_id', $object)->get();
        $this->assertCount(1, $events);
        $this->assertEquals($snapshot, $events[0]->after_data['nfc_history_v1']);
        $this->assertNotNull($entry->fresh()->processed_at);
        $this->assertSame(0, (int) $entry->fresh()->attempts);
    }
}
