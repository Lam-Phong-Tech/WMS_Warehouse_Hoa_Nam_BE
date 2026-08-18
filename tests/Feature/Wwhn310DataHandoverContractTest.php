<?php

namespace Tests\Feature;

use App\Support\HoaNamErdSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * WWHN-310 — gói bàn giao dữ liệu/migration/báo cáo.
 *
 * Khoá lại đúng những lệch đã đóng trong ticket để không tái diễn:
 *  - ERD entity 15 là `defect_catalogs` (bảng thật), không phải view số ít.
 *  - Ràng buộc composite trong Data Dictionary phải giải được về tên cột vật lý.
 *  - Toàn vẹn tham chiếu: warranty -> danh mục lỗi, reversal -> ledger canonical.
 *  - Bất biến "item chỉ thuộc một inbound chưa Post" có index chốt ở tầng DB.
 *  - Cầu ghi INSTEAD OF của view compatibility còn sống sau khi view bị dựng lại.
 */
class Wwhn310DataHandoverContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_erd_names_entity_15_as_the_canonical_defect_catalogs_table(): void
    {
        $schema = HoaNamErdSchema::parse();

        $this->assertArrayHasKey('defect_catalogs', $schema, 'ERD-AMD-005: entity 15 phải là defect_catalogs.');
        $this->assertArrayNotHasKey('defect_catalog', $schema, 'Tên số ít chỉ là compatibility view, không phải entity của ERD.');
        $this->assertCount(HoaNamErdSchema::TABLE_COUNT, $schema);

        $markdown = (string) file_get_contents(base_path(HoaNamErdSchema::DOCUMENT_PATH));
        $this->assertStringContainsString('Amendment Register', $markdown, 'ERD phải có sổ đăng ký sửa đổi.');
        $this->assertStringContainsString('FK defect_catalogs.id', $markdown, 'Tham chiếu warranty_cases.defect_id phải trỏ defect_catalogs.id.');
    }

    /**
     * ERD-AMD-006: mệnh đề ràng buộc không được viết bằng bí danh văn xuôi, vì
     * cổng parity phải giải được chúng về cột vật lý thật.
     */
    public function test_documented_constraints_use_physical_column_names(): void
    {
        $markdown = (string) file_get_contents(base_path(HoaNamErdSchema::DOCUMENT_PATH));

        foreach (['UNIQUE(document,line_no)', 'UNIQUE(request,line_no)'] as $prose) {
            $this->assertStringNotContainsString($prose, $markdown, "Data Dictionary còn bí danh văn xuôi [{$prose}].");
        }
        foreach ([
            'UNIQUE(inbound_document_id,line_no)',
            'UNIQUE(outbound_request_id,line_no)',
            'UNIQUE(component_issue_document_id,line_no)',
        ] as $expected) {
            $this->assertStringContainsString($expected, $markdown);
        }
    }

    public function test_defect_catalog_master_matches_the_data_dictionary(): void
    {
        $this->requirePostgres();

        $this->assertSame('r', HoaNamErdSchema::relationKind('defect_catalogs'));
        $this->assertSame('v', HoaNamErdSchema::relationKind('defect_catalog'), 'Tên số ít phải giữ vai trò compatibility view.');
        $this->assertSame(['id'], $this->primaryKeyColumns('defect_catalogs'));

        $columns = collect(DB::select(<<<'SQL'
            SELECT column_name, data_type, is_nullable, column_default, character_maximum_length
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'defect_catalogs'
        SQL))->keyBy('column_name');

        $this->assertSame('NO', $columns['id']->is_nullable);
        // Default trên `id` sẽ chạy TRƯỚC BEFORE trigger và làm lệch cặp định danh.
        $this->assertNull($columns['id']->column_default, 'defect_catalogs.id không được có column default.');
        $this->assertSame(80, (int) $columns['defect_code']->character_maximum_length);
        $this->assertSame(200, (int) $columns['defect_name']->character_maximum_length);
        $this->assertSame('timestamp with time zone', $columns['created_at']->data_type);
        $this->assertSame('timestamp with time zone', $columns['updated_at']->data_type);
        $this->assertStringContainsString('0', (string) $columns['version']->column_default);

        foreach (['created_by', 'updated_by'] as $column) {
            $this->assertTrue(
                $this->hasForeignKey('defect_catalogs', $column, 'app_users', 'id'),
                "Thiếu FK defect_catalogs.{$column} -> app_users.id.",
            );
        }
        $this->assertTrue(
            $this->hasForeignKey('warranty_cases', 'defect_id', 'defect_catalogs', 'id'),
            'Thiếu FK warranty_cases.defect_id -> defect_catalogs.id.',
        );
    }

    public function test_defect_identity_columns_stay_in_sync_through_both_write_paths(): void
    {
        $this->requirePostgres();

        DB::table('defect_catalogs')->insert([
            'defect_id' => (string) Str::uuid(),
            'defect_code' => 'WWHN310-CANON',
            'defect_name' => 'Ghi thẳng bảng canonical',
            'status' => 'ACTIVE',
        ]);

        // Cầu ghi INSTEAD OF của view số ít phải còn sống sau khi view được dựng lại.
        DB::table('defect_catalog')->insert([
            'defect_id' => (string) Str::uuid(),
            'defect_code' => 'WWHN310-LEGACY',
            'defect_name' => 'Ghi qua view số ít',
            'status' => 'ACTIVE',
        ]);

        $this->assertSame(
            0,
            (int) DB::scalar('SELECT COUNT(*) FROM defect_catalogs WHERE id IS DISTINCT FROM defect_id'),
            'id và defect_id phải luôn bằng nhau.',
        );
        $this->assertSame(1, DB::table('defect_catalogs')->where('defect_code', 'WWHN310-LEGACY')->count());
    }

    public function test_reversal_lines_reference_the_authoritative_ledger(): void
    {
        $this->requirePostgres();

        foreach (['original_movement_id', 'posted_movement_id'] as $column) {
            $this->assertTrue(
                $this->hasForeignKey('reversal_lines', $column, 'stock_movements', 'id'),
                "Thiếu FK reversal_lines.{$column} -> stock_movements.id (ledger authoritative).",
            );
        }
    }

    /**
     * ERD bảng 28 đối xứng với bảng 41: một item chỉ được active ở một inbound
     * chưa Post. Phía outbound đã có index này từ lâu, phía inbound thì chưa.
     */
    public function test_inbound_scan_entries_enforce_one_active_entry_per_item(): void
    {
        $this->requirePostgres();

        $index = collect(Schema::getIndexes('inbound_scan_entries'))
            ->first(fn (array $definition): bool => ($definition['columns'] ?? []) === ['item_id'] && ($definition['unique'] ?? false));

        $this->assertNotNull($index, 'Thiếu partial UNIQUE(item_id) WHERE status=ACTIVE trên inbound_scan_entries.');
    }

    /**
     * §13.10 RPT-LABEL-01 cần cột "result" và số lần in lỗi. Trước ticket này, giao
     * của hai CHECK trên `label_print_events` chỉ còn 'PRINTED' nên một lần in thất
     * bại không ghi nổi vào DB tham chiếu.
     */
    public function test_failed_print_attempt_can_be_recorded_and_reprint_is_derived(): void
    {
        $this->requirePostgres();

        $this->adminHeaders();

        $snapshotId = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $snapshotId,
            'outbound_document_id' => $this->outboundDocumentIdForLabelFixture(),
            'label_payload' => json_encode(['sku_summary' => []], JSON_THROW_ON_ERROR),
            'status' => 'PENDING_PRINT',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $insert = function (string $status) use ($snapshotId): void {
            DB::table('label_print_events')->insert([
                'id' => (string) Str::uuid(),
                'label_snapshot_id' => $snapshotId,
                'status' => $status,
                'version' => 1,
                'created_at' => now(),
            ]);
        };

        $insert('PRINT_FAILED');
        $insert('PRINTED');
        $insert('PRINTED');

        $events = DB::table('label_print_events')
            ->where('label_snapshot_id', $snapshotId)
            ->orderBy('attempt_no')
            ->get(['status', 'attempt_no', 'event_type']);

        $this->assertCount(3, $events, 'Sự kiện in lỗi phải ghi được vào DB tham chiếu.');
        $this->assertSame(['FAILED', 'PRINTED', 'PRINTED'], $events->pluck('status')->all());
        $this->assertSame([1, 2, 3], $events->pluck('attempt_no')->map(fn ($no): int => (int) $no)->all());
        // Retry sau lỗi vẫn là PRINT; chỉ lần in thành công thứ hai mới là REPRINT.
        $this->assertSame(['PRINT', 'PRINT', 'REPRINT'], $events->pluck('event_type')->all());
    }

    /**
     * `label_snapshots.outbound_document_id` gắn FK vào bảng ERD `outbound_documents`,
     * nên fixture ghi thẳng vào bảng đó thay vì đi vòng qua `stock_documents` + tầng
     * mirror.
     */
    private function outboundDocumentIdForLabelFixture(): string
    {
        $existing = DB::table('outbound_documents')->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $warehouseId = DB::table('warehouses')->value('id');
        if ($warehouseId === null) {
            $warehouseId = (string) Str::uuid();
            DB::table('warehouses')->insert([
                'id' => $warehouseId,
                'code' => 'WWHN310',
                'name' => 'Kho kiểm chứng WWHN-310',
                'warehouse_code' => 'WWHN310',
                'warehouse_name' => 'Kho kiểm chứng WWHN-310',
                'status' => 'ACTIVE',
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $documentId = (string) Str::uuid();
        DB::table('outbound_documents')->insert([
            'id' => $documentId,
            'outbound_no' => 'WWHN310-LBL-'.Str::random(6),
            'warehouse_id' => (string) $warehouseId,
            'recipient_name' => 'Người nhận kiểm chứng',
            'document_date' => now()->toDateString(),
            'status' => 'DRAFT',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $documentId;
    }

    public function test_defect_master_api_enforces_documented_field_lengths(): void
    {
        $headers = $this->adminHeaders();

        $this->postJson('/api/v1/defect-catalogs', [
            'defect_code' => str_repeat('C', 80),
            'defect_name' => str_repeat('N', 200),
            'status' => 'ACTIVE',
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/defect-catalogs', [
            'defect_code' => str_repeat('C', 81),
            'defect_name' => 'Mã dài quá Data Dictionary',
            'status' => 'ACTIVE',
        ], $headers)->assertStatus(422);

        $this->postJson('/api/v1/defect-catalogs', [
            'defect_code' => 'WWHN310-LEN',
            'defect_name' => str_repeat('N', 201),
            'status' => 'ACTIVE',
        ], $headers)->assertStatus(422);
    }

    private function adminHeaders(): array
    {
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'wwhn310-'.Str::random(6),
            'display_name' => 'WWHN-310 tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function hasForeignKey(string $table, string $column, string $parent, string $parentColumn): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(function (array $foreignKey) use ($column, $parent, $parentColumn): bool {
            return in_array($column, $foreignKey['columns'] ?? [], true)
                && ($foreignKey['foreign_table'] ?? $foreignKey['foreignTable'] ?? null) === $parent
                && in_array($parentColumn, $foreignKey['foreign_columns'] ?? $foreignKey['foreignColumns'] ?? [], true);
        });
    }

    /** @return array<int, string> */
    private function primaryKeyColumns(string $table): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT array_agg(a.attname ORDER BY x.ordinality)::text AS columns
            FROM pg_constraint c
            JOIN unnest(c.conkey) WITH ORDINALITY AS x(attnum, ordinality) ON true
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = x.attnum
            WHERE c.conrelid = ?::regclass AND c.contype = 'p'
            GROUP BY c.oid
        SQL, [$table]);

        if ($row === null) {
            return [];
        }

        return collect(explode(',', trim((string) $row->columns, '{}')))
            ->map(fn (string $item): string => trim($item, '" '))
            ->filter()
            ->values()
            ->all();
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Cổng schema vật lý chỉ áp dụng cho DB tham chiếu PostgreSQL.');
        }
    }
}
