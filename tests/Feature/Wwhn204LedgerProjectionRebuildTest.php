<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\InventoryProjectionRebuildService;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\TestCase;

/**
 * WWHN-204 — DEV204-P1-03 (rebuild projection từ ledger) và DEV204-P1-04 (append-only
 * ở tầng DB) của register khắc phục.
 *
 * Bộ test này chính là "ledger/balance/rebuild suite" mà DEV204-P1-02 giao cho QA1 chạy
 * tại exact SHA; runbook ở docs/evidence/WWHN-204/QA1_RUNBOOK.md.
 *
 * Ba nhóm bất biến được chứng minh bằng dữ liệu thật (post-receipt qua HTTP), không mock:
 *
 *  1. Ledger bất biến ở tầng DB — UPDATE/DELETE trên `stock_movements` bị từ chối trên
 *     CẢ HAI driver (pgsql: migration 2026_08_15_000100; sqlite: 2026_08_19_050100).
 *  2. WWHN-48 AC#2 — sau một intentional drift, `inventory:reconcile --rebuild` đưa
 *     projection về đúng ledger authoritative, mismatch trở về 0, và lần chạy thứ hai
 *     không sửa gì (idempotent).
 *  3. REQ-SRS-037 / BR-NOSTK — rebuild không mở ra bề mặt "sửa tồn trực tiếp": không có
 *     route HTTP nào, và ledger không hề bị ghi trong lúc rebuild.
 */
class Wwhn204LedgerProjectionRebuildTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use RefreshDatabase;

    private function headers(): array
    {
        $roleCode = 'WWHN204_REBUILD_'.Str::upper(Str::random(6));
        $role = Role::create(['role_code' => $roleCode, 'role_name' => $roleCode, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        foreach (['inventory.view', 'inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post', 'container.manage'] as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                ['role_permission_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $user = User::create([
            'username' => 'wwhn204rb-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-204 Rebuild Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']
        );
    }

    private function sku(string $suffix): Sku
    {
        return Sku::create([
            'sku_code' => 'RB-'.$suffix.'-'.Str::upper(Str::random(4)),
            'sku_name' => 'Rebuild fixture '.$suffix,
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Nhập kho thật qua HTTP (contract GĐ1 scan-driven: header-only + component-lines +
     * Box + đếm thực tế) để ledger và projection cùng sinh ra trong một transaction.
     */
    private function postReceipt(array $headers, Warehouse $warehouse, Sku $sku, int $qty, string $boxCode, string $idemKey): void
    {
        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'NCC WWHN-204 rebuild',
        ]);

        $received = $this->receiveComponentQuantity(
            $headers,
            $document['document_id'],
            (string) $sku->sku_id,
            $qty,
            $document['version'],
            $boxCode
        );

        $this->postInboundReceipt($headers, $document['document_id'], $received['version'], $idemKey)
            ->assertOk()->assertJsonPath('data.status', 'POSTED');
    }

    private function balanceRow(Warehouse $warehouse, Sku $sku): ?object
    {
        return DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where(function ($q) use ($sku): void {
                $q->where('sku_id', $sku->sku_id)->orWhere('product_id', $sku->sku_id);
            })
            ->where('stock_status', 'AVAILABLE')
            ->first();
    }

    private function ledgerQty(Warehouse $warehouse, Sku $sku): float
    {
        return (float) DB::table('stock_movements')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $sku->sku_id)
            ->where('stock_status', 'AVAILABLE')
            ->sum('qty_delta');
    }

    /**
     * §7.1 DEC-INV-001 "Movement append-only" phải được cưỡng chế ở tầng DB, không chỉ ở
     * tầng Eloquent — vì mọi đường ghi tồn thật đều dùng DB::table(), tức đi vòng qua model.
     *
     * Bọc trong DB::transaction() lồng để Laravel cấp SAVEPOINT: trên PostgreSQL, một câu
     * lệnh lỗi làm abort toàn bộ transaction đang mở, nên không có savepoint thì phần còn
     * lại của test (và cả RefreshDatabase) sẽ chết theo với 25P02 thay vì assert được.
     */
    public function test_stock_movements_rejects_update_and_delete_at_database_level(): void
    {
        $headers = $this->headers();
        $warehouse = $this->warehouse();
        $sku = $this->sku('APPEND');
        $this->postReceipt($headers, $warehouse, $sku, 4, 'BOX-RB-APPEND', 'wwhn204-rb-append');

        $movementId = (string) DB::table('stock_movements')
            ->where('product_id', $sku->sku_id)
            ->value('id');
        $this->assertNotEmpty($movementId, 'Post-receipt phải sinh ra một dòng ledger.');

        $updateRejected = false;
        try {
            DB::transaction(function () use ($movementId): void {
                DB::table('stock_movements')->where('id', $movementId)->update(['qty_delta' => 999]);
            });
        } catch (QueryException $e) {
            $updateRejected = true;
            $this->assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }
        $this->assertTrue($updateRejected, 'UPDATE trên stock_movements phải bị DB từ chối (append-only).');

        $deleteRejected = false;
        try {
            DB::transaction(function () use ($movementId): void {
                DB::table('stock_movements')->where('id', $movementId)->delete();
            });
        } catch (QueryException $e) {
            $deleteRejected = true;
            $this->assertStringContainsStringIgnoringCase('append-only', $e->getMessage());
        }
        $this->assertTrue($deleteRejected, 'DELETE trên stock_movements phải bị DB từ chối (append-only).');

        // Ledger còn nguyên sau hai lần bị từ chối — không có rollback bán phần.
        $this->assertSame(4.0, $this->ledgerQty($warehouse, $sku));
    }

    /**
     * WWHN-48 AC#2 — trọng tâm của DEV204-P1-03.
     *
     * Kịch bản đúng như register yêu cầu: tạo drift có chủ đích trên projection, xác nhận
     * đối soát phát hiện + ghi incident, rồi rebuild phải đưa projection về ledger
     * authoritative và mismatch bằng 0.
     */
    public function test_rebuild_restores_projection_to_ledger_after_intentional_drift(): void
    {
        $headers = $this->headers();
        $warehouse = $this->warehouse();
        $sku = $this->sku('DRIFT');
        $this->postReceipt($headers, $warehouse, $sku, 7, 'BOX-RB-DRIFT', 'wwhn204-rb-drift');

        $this->assertSame(7.0, $this->ledgerQty($warehouse, $sku));
        $this->assertSame(7.0, (float) $this->balanceRow($warehouse, $sku)->on_hand_qty);

        // --- intentional drift: chỉ chạm projection, ledger giữ nguyên ---
        DB::table('inventory_balances')
            ->where('id', $this->balanceRow($warehouse, $sku)->id)
            ->update(['on_hand_qty' => 99, 'quantity' => 99, 'available_qty' => 99]);

        $service = app(InventoryProjectionRebuildService::class);
        $fingerprintBefore = $service->ledgerFingerprint();

        // --- bước 1: phát hiện + lưu vết ---
        Artisan::call('inventory:reconcile');
        $incident = $this->openIncidentFor($warehouse, $sku);
        $this->assertNotNull($incident, 'Đối soát phải ghi lại incident cho drift vừa tạo.');

        $before = $service->plan();
        $this->assertNotEmpty($before, 'Trước rebuild phải còn ít nhất một dimension lệch.');

        // --- bước 2: rebuild ---
        $exit = Artisan::call('inventory:reconcile', ['--rebuild' => true, '--reason' => 'QA1 drift drill WWHN-204']);
        $this->assertSame(0, $exit, Artisan::output());

        // --- sau rebuild: projection == ledger, mismatch = 0 ---
        $balance = $this->balanceRow($warehouse, $sku);
        $this->assertSame(7.0, (float) $balance->on_hand_qty);
        $this->assertSame(7.0, (float) $balance->quantity);
        $this->assertSame(7.0, (float) $balance->available_qty);
        $this->assertSame([], $service->plan(), 'Sau rebuild không được còn dimension nào lệch.');

        // Ledger authoritative không bị đụng tới — bằng chứng rebuild là một chiều.
        $this->assertSame($fingerprintBefore, $service->ledgerFingerprint());
        $this->assertSame(7.0, $this->ledgerQty($warehouse, $sku));

        // Incident được đóng kèm resolution_ref trỏ về run id (§7.5 truy vết được).
        $resolved = $this->incidentById($incident->incident_id);
        $this->assertSame('RESOLVED', $resolved->status);
        $this->assertStringStartsWith('REBUILD:', (string) $resolved->resolution_ref);

        // Audit trail: ai/khi nào đã sửa projection.
        $audit = AuditLog::where('action', InventoryProjectionRebuildService::AUDIT_ACTION)->first();
        $this->assertNotNull($audit, 'Rebuild phải ghi audit_log INVENTORY_PROJECTION_REBUILT.');
    }

    /** Chạy lại rebuild trên hệ đã khớp phải là no-op — không bump version, không audit thêm. */
    public function test_rebuild_is_idempotent_on_a_consistent_system(): void
    {
        $headers = $this->headers();
        $warehouse = $this->warehouse();
        $sku = $this->sku('IDEM');
        $this->postReceipt($headers, $warehouse, $sku, 3, 'BOX-RB-IDEM', 'wwhn204-rb-idem');

        $versionBefore = (int) $this->balanceRow($warehouse, $sku)->version;

        Artisan::call('inventory:reconcile', ['--rebuild' => true]);
        Artisan::call('inventory:reconcile', ['--rebuild' => true]);

        $this->assertSame($versionBefore, (int) $this->balanceRow($warehouse, $sku)->version);
        $this->assertSame(0, AuditLog::where('action', InventoryProjectionRebuildService::AUDIT_ACTION)->count());
        $this->assertSame([], app(InventoryProjectionRebuildService::class)->plan());
    }

    /** --dry-run kết hợp --rebuild: báo cáo kế hoạch nhưng tuyệt đối không ghi. */
    public function test_rebuild_dry_run_reports_plan_without_writing(): void
    {
        $headers = $this->headers();
        $warehouse = $this->warehouse();
        $sku = $this->sku('DRY');
        $this->postReceipt($headers, $warehouse, $sku, 5, 'BOX-RB-DRY', 'wwhn204-rb-dry');

        DB::table('inventory_balances')
            ->where('id', $this->balanceRow($warehouse, $sku)->id)
            ->update(['on_hand_qty' => 41, 'quantity' => 41]);

        Artisan::call('inventory:reconcile', ['--rebuild' => true, '--dry-run' => true]);

        $this->assertSame(41.0, (float) $this->balanceRow($warehouse, $sku)->on_hand_qty, 'dry-run không được sửa projection.');
        $this->assertSame(0, AuditLog::where('action', InventoryProjectionRebuildService::AUDIT_ACTION)->count());
        $this->assertNotEmpty(app(InventoryProjectionRebuildService::class)->plan());
    }

    /**
     * Hai dạng lệch còn lại của §7.5: projection thiếu dòng mà ledger có, và projection
     * còn dòng mà ledger không có. Cả hai đều phải quy về đúng con số của ledger.
     */
    public function test_rebuild_handles_missing_and_orphan_projection_rows(): void
    {
        $headers = $this->headers();
        $warehouse = $this->warehouse();

        $skuMissing = $this->sku('MISS');
        $this->postReceipt($headers, $warehouse, $skuMissing, 6, 'BOX-RB-MISS', 'wwhn204-rb-miss');
        // BALANCE_MISSING_FOR_LEDGER: xoá dòng projection, ledger vẫn còn 6.
        DB::table('inventory_balances')->where('id', $this->balanceRow($warehouse, $skuMissing)->id)->delete();

        // LEDGER_MISSING_FOR_BALANCE: dựng một dòng projection không có movement nào.
        $skuOrphan = $this->sku('ORPH');
        $orphanId = (string) Str::uuid();
        DB::table('inventory_balances')->insert([
            'id' => $orphanId,
            'warehouse_id' => $warehouse->id,
            'product_id' => $skuOrphan->sku_id,
            'sku_id' => $skuOrphan->sku_id,
            'stock_status' => 'AVAILABLE',
            'quantity' => 15,
            'on_hand_qty' => 15,
            'available_qty' => 15,
            'reserved_qty' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('inventory:reconcile', ['--rebuild' => true]);

        $restored = $this->balanceRow($warehouse, $skuMissing);
        $this->assertNotNull($restored, 'Dimension có ledger phải được dựng lại dòng projection.');
        $this->assertSame(6.0, (float) $restored->on_hand_qty);

        $orphan = DB::table('inventory_balances')->where('id', $orphanId)->first();
        $this->assertNotNull($orphan, 'Dòng orphan bị đưa về 0, không bị xoá — giữ lại dấu vết.');
        $this->assertSame(0.0, (float) $orphan->on_hand_qty);
        $this->assertSame(0.0, (float) $orphan->available_qty);

        $this->assertSame([], app(InventoryProjectionRebuildService::class)->plan());
    }

    /**
     * REQ-SRS-037 / BR-NOSTK-01..03 — rebuild là công cụ vận hành ở CLI, không phải một
     * capability trong sản phẩm. Nếu có ai đó gắn nó lên HTTP thì test này phải đỏ.
     */
    public function test_rebuild_is_not_exposed_as_any_http_route(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());
            $this->assertStringNotContainsString('rebuild', $uri, "Route {$uri} phơi rebuild lên HTTP.");
            $this->assertStringNotContainsString('stocktake', $uri, "Route {$uri} phơi stocktake lên HTTP.");
        }
    }

    private function incidentTable(): array
    {
        return DB::getDriverName() === 'pgsql'
            ? ['reconciliation_incidents', 'sku_id', 'id']
            : ['reconciliation_incident', 'product_id', 'incident_id'];
    }

    private function openIncidentFor(Warehouse $warehouse, Sku $sku): ?object
    {
        [$table, $productColumn, $key] = $this->incidentTable();
        $row = DB::table($table)
            ->where('warehouse_id', $warehouse->id)
            ->where($productColumn, $sku->sku_id)
            ->whereIn('status', ['OPEN', 'INVESTIGATING'])
            ->first();

        return $row === null ? null : (object) ((array) $row + ['incident_id' => $row->{$key}]);
    }

    private function incidentById(string $incidentId): object
    {
        [$table, , $key] = $this->incidentTable();

        return DB::table($table)->where($key, $incidentId)->first();
    }
}
