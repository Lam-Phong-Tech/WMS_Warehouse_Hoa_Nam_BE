<?php

namespace Tests\Feature;

use App\Support\DocumentTimestamp;
use App\Support\IdempotencyStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\NfcTimestamp;
use Modules\Warehouse\Support\P22NfcHistory;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;
use Tests\TestCredentials;

/** Synthetic NFC only, guarded before RefreshDatabase. Never touches a physical tag or serving DB. */
final class P07NfcTimestampTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/';

    private CarbonImmutable $instant;

    protected function setUp(): void
    {
        $app = $this->createApplication();
        $connection = $app['config']->get('database.connections.'.$app['config']->get('database.default'));
        $safe = $app->environment('testing') && empty($connection['url'])
            && (($connection['driver'] === 'sqlite' && $connection['database'] === ':memory:')
                || ($connection['driver'] === 'pgsql' && $connection['host'] === '127.0.0.1'
                    && $connection['database'] === 'p12_contract_test' && (string) $connection['port'] === '55433'));
        $app->flush();
        if (! $safe) {
            throw new \LogicException('NFC timestamps require memory or owned loopback p12_contract_test:55433.');
        }
        parent::setUp();
        // Owner reservation guards compare with real time(); freeze near that clock.
        $this->instant = CarbonImmutable::now('Asia/Ho_Chi_Minh')->setMicrosecond(123456);
        $this->travelTo($this->instant);
    }

    public function test_confirm_db_receipt_and_gets_preserve_epoch_under_independent_php_pg_zones(): void
    {
        $originalZone = date_default_timezone_get();
        try {
            foreach ([['Asia/Ho_Chi_Minh', 'UTC'], ['UTC', 'Asia/Ho_Chi_Minh'],
                ['UTC', 'UTC'], ['Asia/Ho_Chi_Minh', 'Asia/Ho_Chi_Minh']] as [$phpZone, $pgZone]) {
                config(['app.timezone' => $phpZone]);
                date_default_timezone_set($phpZone);
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL TIME ZONE '$pgZone'");
                }
                [$user, $token, $warehouse] = $this->actor();
                [$item] = $this->item($user, $warehouse);
                $before = $this->stockFingerprint();
                [$body, $key, $receipt] = $this->confirm($token, $item);
                $expected = DocumentTimestamp::utc($this->instant);
                $tagId = $receipt->json('data.nfc.physical_code_id');
                $row = DB::table('physical_codes')->where('physical_code_id', $tagId)->first();
                foreach (['assigned_at', 'created_at', 'updated_at'] as $field) {
                    $this->assertSame($expected, $receipt->json('data.nfc.'.$field));
                    $this->assertSame($expected, NfcTimestamp::read($row->{$field}));
                    $this->assertEpoch('physical_codes', 'physical_code_id', $tagId, $field, $expected);
                }
                $this->assertNull($receipt->json('data.nfc.deactivated_at'));
                $receipt->assertJsonPath('meta.timestamp_contract', NfcTimestamp::CONTRACT)
                    ->assertJsonPath('data.stock_effect', 'NONE')->assertJsonPath('data.movement_created', false);
                $this->getJson(self::BASE.'nfc-tags/'.$tagId, $this->headers($token))->assertOk()
                    ->assertJsonPath('data.assigned_at', $expected)->assertJsonPath('data.created_at', $expected);
                $this->getJson(self::BASE.'nfc-tags?hardware_uid='.$body['hardware_uid'], $this->headers($token))->assertOk()
                    ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.assigned_at', $expected);
                $serial = DB::table('item_instances')->where('item_id', $item)->value('serial_number');
                $this->getJson(self::BASE.'items?nfc_status=ASSIGNED&keyword='.urlencode($serial), $this->headers($token))->assertOk()
                    ->assertJsonPath('data.0.nfc.assigned_at', $expected);
                $this->postJson(self::BASE.'nfc/resolve-tag', ['hardware_uid' => $body['hardware_uid'], 'raw_code' => $body['written_payload']], $this->headers($token))
                    ->assertOk()->assertJsonPath('data.nfc.assigned_at', $expected);
                $stored = DB::table(IdempotencyStore::TABLE)->where('idem_key', $key)->value('response_body');
                $this->getJson(self::BASE.'items/'.$item.'/nfc/confirmation', $this->headers($token, $key))->assertOk()
                    ->assertJsonPath('data.response.data.nfc.assigned_at', $expected);
                $again = $this->patchJson(self::BASE.'items/'.$item.'/nfc', $body, $this->headers($token, $key))->assertCreated();
                $this->assertSame($receipt->json(), $again->json());
                $this->assertSame($stored, DB::table(IdempotencyStore::TABLE)->where('idem_key', $key)->value('response_body'));
                $audit = DB::table('audit_log')->where('object_type', 'physical_code')->where('object_id', $tagId)->where('action', 'ASSIGN_ITEM_NFC')->first();
                $this->assertSame($expected, NfcTimestamp::read($audit->created_at));
                $this->assertEpoch('audit_log', 'id', $audit->id, 'created_at', $expected);
                $this->assertHistory($token, $warehouse->id, $tagId, $audit->id, $expected);
                $this->assertSame($before, $this->stockFingerprint());
            }
        } finally {
            date_default_timezone_set($originalZone);
            config(['app.timezone' => $originalZone]);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL TIME ZONE 'UTC'");
            }
        }
    }

    public function test_deactivation_uses_its_own_instant_and_preserves_assignment_and_historical_receipt(): void
    {
        [$user, $token, $warehouse] = $this->actor();
        [$item] = $this->item($user, $warehouse);
        [, $key, $receipt] = $this->confirm($token, $item);
        $tagId = $receipt->json('data.nfc.physical_code_id');
        $stored = DB::table(IdempotencyStore::TABLE)->where('idem_key', $key)->value('response_body');
        $before = $this->stockFingerprint();
        $this->travel(1)->minutes();
        $expected = DocumentTimestamp::utc($this->instant->addMinute());
        $this->postJson(self::BASE.'nfc-tags/'.$tagId.'/deactivate', ['status' => 'LOST', 'reason' => 'Synthetic test only'], $this->headers($token))
            ->assertOk()->assertJsonPath('data.nfc.deactivated_at', $expected)->assertJsonPath('data.nfc.updated_at', $expected)
            ->assertJsonPath('data.nfc.assigned_at', $receipt->json('data.nfc.assigned_at'));
        $audit = DB::table('audit_log')->where('object_id', $tagId)->where('action', 'REVOKE_ITEM_NFC')->first();
        $this->assertEpoch('physical_codes', 'physical_code_id', $tagId, 'deactivated_at', $expected);
        $this->assertHistory($token, $warehouse->id, $tagId, $audit->id, $expected);
        $this->getJson(self::BASE.'items/'.$item.'/nfc/confirmation', $this->headers($token, $key))->assertOk()
            ->assertJsonPath('data.response.data.nfc.status', 'ACTIVE')->assertJsonPath('data.response.data.nfc.deactivated_at', null);
        $this->assertSame($stored, DB::table(IdempotencyStore::TABLE)->where('idem_key', $key)->value('response_body'));
        $this->assertSame($before, $this->stockFingerprint());
    }

    public function test_legacy_bad_row_and_receipt_remain_unchanged_and_never_use_now_to_repair(): void
    {
        [$user, $token, $warehouse] = $this->actor();
        [$item] = $this->item($user, $warehouse);
        [, $key, $receipt] = $this->confirm($token, $item);
        $tagId = $receipt->json('data.nfc.physical_code_id');
        $legacy = $receipt->json();
        unset($legacy['meta']['timestamp_contract']);
        foreach (['assigned_at', 'created_at', 'updated_at'] as $field) {
            $legacy['data']['nfc'][$field] = '2026-10-21 12:09:01+00';
        }
        $rawReceipt = json_encode($legacy, JSON_THROW_ON_ERROR);
        DB::table(IdempotencyStore::TABLE)->where('idem_key', $key)->update(['response_body' => $rawReceipt]);
        DB::table('physical_codes')->where('physical_code_id', $tagId)->update([
            'assigned_at' => '2026-10-21 12:09:01+00', 'created_at' => '2026-10-21 12:09:01+00', 'updated_at' => '2026-10-21 12:09:01+00']);
        $rawRow = (array) DB::table('physical_codes')->where('physical_code_id', $tagId)->first();
        $this->getJson(self::BASE.'nfc-tags/'.$tagId, $this->headers($token))->assertOk()
            ->assertJsonPath('data.assigned_at', '2026-10-21T12:09:01.000000Z');
        $this->getJson(self::BASE.'items/'.$item.'/nfc/confirmation', $this->headers($token, $key))->assertOk()
            ->assertJsonPath('data.response', $legacy);
        $this->assertSame($rawReceipt, DB::table(IdempotencyStore::TABLE)->where('idem_key', $key)->value('response_body'));
        $this->assertSame($rawRow, (array) DB::table('physical_codes')->where('physical_code_id', $tagId)->first());
    }

    public function test_outbox_keeps_original_nfc_epoch_when_published_later(): void
    {
        [$user, $token, $warehouse] = $this->actor();
        [$item] = $this->item($user, $warehouse);
        $fail = true;
        AuditLog::creating(function (AuditLog $event) use (&$fail): void {
            if ($fail && $event->action === 'ASSIGN_ITEM_NFC') {
                $fail = false;
                throw new \RuntimeException('Synthetic audit publication failure');
            }
        });
        [, , $receipt] = $this->confirm($token, $item);
        $tagId = $receipt->json('data.nfc.physical_code_id');
        $entry = AuditOutbox::query()->whereNull('processed_at')->firstOrFail();
        $payload = $entry->payload;
        $this->assertSame(DocumentTimestamp::utc($this->instant), NfcTimestamp::read($payload['created_at']));
        $this->travel(2)->minutes();
        $this->artisan('audit:reconcile', ['--limit' => 5])->assertExitCode(0);
        $this->assertNotNull($entry->fresh()->processed_at);
        $this->assertSame($payload, $entry->fresh()->payload);
        $audit = DB::table('audit_log')->where('object_id', $tagId)->where('action', 'ASSIGN_ITEM_NFC')->first();
        $this->assertEpoch('audit_log', 'id', $audit->id, 'created_at', DocumentTimestamp::utc($this->instant));
        $this->assertHistory($token, $warehouse->id, $tagId, $audit->id, DocumentTimestamp::utc($this->instant));
    }

    public function test_serialization_null_missing_offsets_and_year_boundary(): void
    {
        foreach (['2026-12-31T17:00:00.654321Z', '2026-12-31T17:00:00.654321+00:00', '2027-01-01T00:00:00.654321+07:00'] as $raw) {
            $this->assertSame('2026-12-31T17:00:00.654321Z', NfcTimestamp::read($raw));
        }
        $record = NfcTimestamp::record(['assigned_at' => null, 'deactivated_at' => null, 'status' => 'ACTIVE']);
        $this->assertNull($record['assigned_at']);
        $this->assertNull($record['deactivated_at']);
        $this->assertArrayNotHasKey('created_at', $record);
        $this->assertArrayNotHasKey('updated_at', $record);
    }

    public function test_audit_publication_preserves_explicit_offset_independent_of_payload_key_order(): void
    {
        $event = AuditLog::recordPayload([
            'created_at' => '2027-01-01T00:00:00.654321+07:00',
            'object_type' => 'physical_code', 'object_id' => (string) Str::uuid(), 'action' => 'ASSIGN_ITEM_NFC',
        ]);
        $this->assertEpoch('audit_log', 'id', $event->id, 'created_at', '2026-12-31T17:00:00.654321Z');
        // A queued legacy wall clock has no new provenance. Preserve its prior binding behavior.
        $legacy = AuditLog::recordPayload([
            'created_at' => '2026-10-21 12:09:01',
            'object_type' => 'physical_code', 'object_id' => (string) Str::uuid(), 'action' => 'ASSIGN_ITEM_NFC',
        ]);
        $this->assertSame('Y-m-d H:i:s', $legacy->getDateFormat());
        if (DB::getDriverName() === 'pgsql') {
            $zone = DB::selectOne("SELECT current_setting('TimeZone') AS zone")->zone;
            $this->assertEpoch('audit_log', 'id', $legacy->id, 'created_at',
                DocumentTimestamp::utc('2026-10-21 12:09:01', $zone));
        } else {
            $this->assertSame('2026-10-21 12:09:01', $legacy->fresh()->getRawOriginal('created_at'));
        }
    }

    private function assertHistory(string $token, string $warehouse, string $tag, string $event, string $expected): void
    {
        $query = '?warehouse_id='.$warehouse.'&source=NFC';
        $headers = $this->headers($token) + [P22NfcHistory::HEADER => P22NfcHistory::CONTRACT];
        $history = $this->get(self::BASE.'history/records'.$query.'&event_id='.$event, $headers)->assertOk()
            ->assertJsonPath('data.0.time', $expected)->assertJsonPath('meta.timestamp_contract', NfcTimestamp::CONTRACT);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $history->json('meta.asOf'));
        $this->get(self::BASE.'history/records/NFC:'.$tag.$query.'&activityId=NFC:'.$event, $this->headers($token) + [P22NfcHistory::HEADER => P22NfcHistory::CONTRACT])->assertOk()
            ->assertJsonPath('data.selectedActivity.time', $expected);
        $this->getJson(self::BASE.'nfc-tags/'.$tag.'/events', $this->headers($token))->assertOk()
            ->assertJsonPath('data.0.created_at', $expected);
    }

    private function assertEpoch(string $table, string $idField, string $id, string $field, string $expected): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame('timestamptz', Schema::getColumnType($table, $field));
            $epoch = DB::selectOne('SELECT extract(epoch from '.$field.')::text AS epoch FROM '.$table.' WHERE '.$idField.' = ?', [$id])->epoch;
            $this->assertSame(DocumentTimestamp::instant($expected)->format('U.u'), $epoch);
        } else {
            $this->assertSame($expected, NfcTimestamp::read(DB::table($table)->where($idField, $id)->value($field)));
        }
    }

    private function actor(): array
    {
        $warehouse = $this->warehouseFixture();
        $suffix = Str::lower(Str::random(10));
        $user = User::create(['username' => 'nfctime_'.$suffix, 'display_name' => 'Synthetic NFC timestamp actor',
            'email' => 'nfctime_'.$suffix.'@example.test', 'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'NFCTIME_'.$suffix, 'role_name' => 'Test only', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['history.read', 'inventory.trace', 'physical_code.assign_rfid'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $token = $this->browserLogin($user->email, TestCredentials::PASSWORD)->assertOk()->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $this->headers($token))
            ->assertOk()->json('data.access_token');

        return [$user, $token, $warehouse];
    }

    private function item(User $actor, object $warehouse): array
    {
        $sku = Sku::create(['sku_code' => 'NFC-TIME-'.Str::upper(Str::random(10)), 'sku_name' => 'Synthetic NFC time product',
            'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => true, 'status' => 'ACTIVE']);
        $item = $this->seedItemInStock($sku->sku_id);
        DB::table('item_instances')->where('item_id', $item)->update(['serial_number' => 'SYNTHETIC-'.Str::upper(Str::random(12))]);
        $id = (string) Str::uuid();
        $movement = ['id' => $id, 'movement_no' => 'NFC-TIME-'.Str::random(12), 'business_key' => 'NFC-TIME-'.$id,
            'movement_type' => 'OPENING', 'warehouse_id' => $warehouse->id, 'product_id' => $sku->sku_id, 'stock_status' => 'AVAILABLE',
            'qty_before' => 0, 'qty_delta' => 1, 'qty_after' => 1, 'created_by' => $actor->user_id,
            'occurred_at' => now()->subHour(), 'created_at' => now()->subHour()];
        foreach (['item_id' => $item, 'sku_id' => $sku->sku_id, 'quantity' => 1, 'source_type' => 'OPENING', 'source_id' => $id] as $field => $value) {
            if (Schema::hasColumn('stock_movements', $field)) {
                $movement[$field] = $value;
            }
        }
        DB::table('stock_movements')->insert($movement);

        return [$item, $sku];
    }

    private function confirm(string $token, string $item): array
    {
        $url = self::BASE.'items/'.$item.'/nfc';
        $prepare = $this->postJson($url.'/prepare', [], $this->headers($token))->assertOk();
        $body = ['hardware_uid' => strtoupper(bin2hex(random_bytes(7))), 'written_payload' => $prepare->json('data.nfc_payload'),
            'reservation_token' => $prepare->json('data.reservation_token')];
        $key = (string) Str::uuid();
        $response = $this->patchJson($url, $body, $this->headers($token, $key))->assertCreated();

        return [$body, $key, $response];
    }

    private function headers(string $token, ?string $key = null): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token] + ($key === null ? [] : ['Idempotency-Key' => $key]);
    }

    private function stockFingerprint(): array
    {
        return array_map(fn (string $table): string => hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all())),
            ['stock_documents', 'stock_movements', 'inventory_balances']);
    }
}
