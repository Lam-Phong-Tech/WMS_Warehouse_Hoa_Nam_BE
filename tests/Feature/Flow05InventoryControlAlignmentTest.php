<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Middleware\EnforceInventoryWarehouseScope;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Luồng 05 · Tồn kho & kiểm soát — docs alignment.
 *
 * Cùng phương pháp với Flow01/Flow02/Flow06 alignment test: khoá đúng những điều
 * SRS/FRD v1.12 + ERD nói về INV-01, TRACE-01, REV-00 và REV-01, để các lệch đã
 * sửa không quay lại.
 *
 * - §8.1 Enforcement standard + NFR-SRS-005: alias canonical phải chịu cùng data scope.
 * - REQ-SRS-030 / BR-TRACE-01..06: trace theo SKU/item/carton/box/tray/code.
 * - REQ-SRS-037 / DEC-NO-STOCKTAKE-001: zero Stocktake ở mọi lớp.
 * - REQ-SRS-013 / BR-REV-03..05: dependency guard + reversible_qty.
 * - §7.5: số đếm subledger phải khớp danh sách drill-down.
 */
class Flow05InventoryControlAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'flow05-'.Str::lower(Str::random(6)),
            'display_name' => 'Flow05 Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function canonicalWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hòa Nam', 'status' => 'ACTIVE']
        );
    }

    /** Receipt COMPONENT (quantity-mode) đã POSTED, đi qua HTTP thật. */
    private function postedComponentReceipt(array $headers, string $skuCode, int $qty = 5): array
    {
        $warehouse = $this->canonicalWarehouse();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => "Component {$skuCode}",
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Flow05 fixture',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => $qty]],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $register = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-'.Str::upper(Str::random(8)),
        ], $headers);
        $boxId = (string) $register->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => $qty,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'receipt-'.Str::random(12),
        ])->assertOk();

        return [
            'document_id' => $documentId,
            'movement_id' => (string) DB::table('stock_movements')
                ->where('stock_document_id', $documentId)
                ->where('movement_type', 'RECEIPT')
                ->value('id'),
            'sku_id' => (string) $sku->sku_id,
            'warehouse_id' => (string) $warehouse->id,
            'container_id' => $boxId,
            'qty' => $qty,
        ];
    }

    // ── §8.1 / NFR-SRS-005: data scope trên đúng alias canonical ──────────────

    public function test_canonical_inventory_endpoints_carry_the_same_data_scope_guard_as_legacy_paths(): void
    {
        $paths = [
            '/api/v1/inventory',
            '/api/v1/inventory/balances',
            '/api/v1/inventory/trace/ITEM-BC-A001',
            '/api/v1/stock-movements',
            '/api/v1/stock-movements/reversible',
            '/api/v1/stock-movements/trace-by-code',
        ];

        foreach ($paths as $path) {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
            $this->assertContains(
                EnforceInventoryWarehouseScope::class,
                $route->gatherMiddleware(),
                "[GET {$path}] phải gắn EnforceInventoryWarehouseScope — §8.1 yêu cầu exact permission + data scope cho mọi protected endpoint."
            );
        }
    }

    public function test_scope_middleware_covers_the_canonical_trace_path(): void
    {
        $middleware = new EnforceInventoryWarehouseScope;
        $reflection = new \ReflectionMethod($middleware, 'isTargetPath');

        foreach ([
            'api/v1/inventory',
            'api/v1/inventory/trace/ITEM-BC-A001',
            'api/v1/stock-movements/reversible',
        ] as $path) {
            $this->assertTrue(
                $reflection->invoke($middleware, $path),
                "[{$path}] phải nằm trong tập path được middleware kiểm soát."
            );
        }
    }

    // ── REQ-SRS-037 / DEC-NO-STOCKTAKE-001 ────────────────────────────────────

    public function test_no_stocktake_capability_remains_in_route_openapi_permission_or_schema(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsStringIgnoringCase(
                'stocktake',
                $route->uri(),
                'REQ-SRS-037 cấm mọi route kiểm kê.'
            );
        }

        foreach ([
            Request::create('/api/v1/stocktake/sessions', 'GET'),
            Request::create('/api/v1/stocktake-sessions', 'POST'),
        ] as $request) {
            try {
                Route::getRoutes()->match($request);
                $this->fail("Route [{$request->getMethod()} {$request->path()}] không được tồn tại.");
            } catch (NotFoundHttpException) {
                $this->assertTrue(true);
            }
        }

        // OpenAPI được sinh từ attribute trên controller — path/handler kiểm kê phải
        // biến mất khỏi chính source, không chỉ khỏi bảng route. Chỉ soi surface thực
        // thi (OA path, tên bảng, permission), không soi comment giải thích việc đã gỡ.
        $executableStocktakeSurface = [
            "path: '/api/v1/stocktake",
            "'stocktake_",
            '"stocktake_',
            'permission:stocktake',
        ];
        foreach (glob(base_path('Modules/Warehouse/app/Http/Controllers').'/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            foreach ($executableStocktakeSurface as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $source,
                    basename($file)." còn expose Stocktake qua [{$needle}]."
                );
            }
        }

        $this->assertFalse(
            Permission::query()->where('permission_key', 'like', '%stocktake%')->exists(),
            'Không được seed permission kiểm kê.'
        );

        foreach (['stocktake_session', 'stocktake_snapshot', 'stocktake_count', 'stocktake_variance'] as $table) {
            $this->assertFalse(
                DB::getSchemaBuilder()->hasTable($table),
                "Entity [{$table}] phải bị loại khỏi deployment v1.12."
            );
        }
    }

    /**
     * AC-SRS-037 nói "deployment v1.12 không chứa capability legacy". Contract artifact
     * được bàn giao ra ngoài (Postman handover set + release contract đang hiệu lực)
     * cũng là một phần deployment: QA/FE đọc chúng như hợp đồng thật. Guard cũ chỉ soi
     * route/permission/schema nên bộ `docs/postman` vẫn còn collection Stocktake của
     * WWHN-131 và request scan gửi `context=STOCKTAKE` — thứ runtime nay trả 422.
     *
     * Phạm vi cố ý loại trừ `docs/api-contract/*.yaml|json` ở gốc: đó là bản ghi lịch sử
     * của WWHN-150/180 đã superseded, giữ lại để truy vết, không nằm trong bộ bàn giao
     * hiện hành (xem docs/evidence/WWHN-254/README.md).
     *
     * WWHN-237 / DISP-STK-237 §4: cùng lý lẽ đó áp cho phần còn lại của pack immutable
     * WWHN-180 nằm trong `docs/postman`. `DISP-STK-236 §3` đã chốt một immutable handoff
     * chỉ được superseded bằng decision record trỏ tới nó, **không bao giờ bằng cách sửa
     * nội dung** — nên guard này không được đòi sửa chúng. Danh sách miễn là hằng số
     * tường minh: thêm phần tử là một thay đổi source có review, không phải im lặng.
     */
    public function test_published_contract_artifacts_carry_no_stocktake_capability(): void
    {
        $roots = [
            base_path('docs/postman'),
            base_path('docs/api-contract/releases'),
        ];

        $historicalAllowlist = [
            'docs/postman/WWHN-180 Canonical Contract.postman_collection.json',
            'docs/postman/WWHN-180 Canonical Contract.postman_environment.json',
        ];

        $offenders = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                if (in_array($relative, $historicalAllowlist, true)) {
                    continue;
                }

                if (stripos($file->getFilename(), 'stocktake') !== false) {
                    $offenders[] = $file->getPathname().' (tên file)';

                    continue;
                }

                // Chỉ soi surface THỰC THI, cùng quy ước với guard controller ở trên:
                // path, biến/khoá, tag. Câu văn mô tả "đã gỡ Stocktake theo REQ-SRS-037"
                // là bằng chứng tuân thủ, không phải capability.
                $source = (string) file_get_contents($file->getPathname());
                $extension = strtolower($file->getExtension());

                // CHANGELOG.md (và mọi .md khác dưới 2 root này) là văn xuôi thuật lại
                // lịch sử, không phải hợp đồng máy đọc được — guard tên file ở trên đã
                // phủ trường hợp ai đó đặt tên file theo Stocktake.
                if ($extension === 'md') {
                    continue;
                }

                // WWHN-190/DISP-STK-236 §3 xác lập khuôn mẫu: release pack mới ghi lại
                // surface đã gỡ bằng `x-removed-surfaces` (+ `x-supersedes`, `description`,
                // `reason`, `declared_in`) — đây LÀ bằng chứng tuân thủ REQ-SRS-037, không
                // phải capability sống, nên phải loại khỏi vùng quét trước khi so needle.
                // Path/tag/enum thật (nằm ngoài các khoá tường thuật này) vẫn bị bắt.
                $scanTarget = $source;
                if ($extension === 'json') {
                    $decoded = json_decode($source, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $scanTarget = json_encode($this->stripStocktakeNarrativeFields($decoded));
                    }
                } elseif (in_array($extension, ['yaml', 'yml'], true)) {
                    try {
                        $decoded = Yaml::parse($source);
                        if (is_array($decoded)) {
                            $scanTarget = json_encode($this->stripStocktakeNarrativeFields($decoded));
                        }
                    } catch (\Throwable) {
                        // YAML không parse được: giữ nguyên raw content để không bỏ lọt.
                    }
                }

                // `"stocktake` / `{{stocktake` = khoá JSON, tên tag hoặc biến Postman;
                // `/stocktake` = path. Từ "stocktake" trần trong một câu mô tả không khớp.
                foreach (['/stocktake', '"stocktake', "'stocktake", '{{stocktake'] as $needle) {
                    if (stripos($scanTarget, $needle) !== false) {
                        $offenders[] = $file->getPathname()." (surface [{$needle}])";

                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "REQ-SRS-037: contract artifact bàn giao còn Stocktake:\n".implode("\n", $offenders)
        );
    }

    /**
     * Gỡ các khoá tường thuật/metadata (không phải capability máy đọc) trước khi guard
     * REQ-SRS-037 so needle "stocktake". Danh sách này cố ý tường minh — thêm khoá mới là
     * một thay đổi source có review, không phải im lặng nới lỏng guard.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function stripStocktakeNarrativeFields(array $node): array
    {
        // Toàn bộ khối là bản ghi "đã gỡ/đã supersede" theo DISP-STK-236 §3, không phải
        // route/enum còn sống.
        unset($node['x-removed-surfaces'], $node['x-supersedes']);

        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, ['description', 'summary', 'reason', 'declared_in', 'notes', 'title'], true)) {
                unset($node[$key]);

                continue;
            }
            if (is_array($value)) {
                $node[$key] = $this->stripStocktakeNarrativeFields($value);
            }
        }

        return $node;
    }

    // ── §6 Reversal lifecycle ─────────────────────────────────────────────────

    public function test_reversal_lifecycle_routes_require_reversal_post_permission(): void
    {
        $id = '00000000-0000-0000-0000-000000000000';
        foreach (['confirm', 'post', 'cancel'] as $action) {
            $route = Route::getRoutes()->match(
                Request::create("/api/v1/reversal-documents/{$id}/{$action}", 'POST')
            );
            $this->assertContains(
                'permission:inventory.reversal.post',
                $route->gatherMiddleware(),
                "[{$action}] phải yêu cầu inventory.reversal.post (BR-REV-02 default-deny)."
            );
        }
    }

    // ── REQ-SRS-013 / BR-REV-05: contract của REV-01 ──────────────────────────

    public function test_reversal_lines_report_original_movement_qty_separately_from_reversed_qty(): void
    {
        $headers = $this->authHeaders();
        $fixture = $this->postedComponentReceipt($headers, 'COMP-F05-LINES-01', 5);

        $reverse = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reversals", [
            'reason' => 'Nhập thừa 2 đơn vị',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 2]],
        ], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'f05-lines-'.Str::random(12),
        ]);
        $reverse->assertOk();
        $reversalId = (string) $reverse->json('data.reversal_document_id');

        $lines = $this->getJson("/api/v1/reversal-documents/{$reversalId}/lines", $headers);
        $lines->assertOk()
            // Số lượng gốc lấy từ movement gốc (5), KHÔNG phải số lượng đảo (2).
            ->assertJsonPath('data.0.original_qty', 5)
            ->assertJsonPath('data.0.reversal_qty', 2)
            ->assertJsonPath('data.0.reversed_qty', 2)
            ->assertJsonPath('data.0.remaining_qty', 3)
            ->assertJsonPath('data.0.status', 'POSTED');

        $this->assertNotNull($lines->json('data.0.posted_movement_id'));
        $this->assertSame($fixture['movement_id'], $lines->json('data.0.original_movement_id'));
    }

    // ── BR-REV-03/04: dependency guard cho SKU quantity-mode ──────────────────

    public function test_quantity_mode_receipt_reversal_is_blocked_when_stock_was_consumed_downstream(): void
    {
        $headers = $this->authHeaders();
        $fixture = $this->postedComponentReceipt($headers, 'COMP-F05-DEP-01', 5);

        // Mô phỏng đúng hiệu ứng của Component Issue: ledger ghi thêm movement âm và
        // projection giảm tương ứng. 4/5 đơn vị vừa nhập đã bị tiêu thụ downstream.
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(),
            'movement_no' => 'MV-F05-DEP-'.Str::upper(Str::random(6)),
            'business_key' => 'f05-dep-'.Str::random(10),
            'movement_type' => 'COMPONENT_ISSUE',
            'warehouse_id' => $fixture['warehouse_id'],
            'product_id' => $fixture['sku_id'],
            'sku_id' => $fixture['sku_id'],
            'stock_status' => 'AVAILABLE',
            'quantity' => 4,
            'qty_before' => 5,
            'qty_delta' => -4,
            'qty_after' => 1,
            'source_type' => 'COMPONENT_ISSUE',
            'source_id' => (string) Str::uuid(),
            'created_by' => (string) auth('api')->id(),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
        DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->update(['quantity' => 1, 'on_hand_qty' => 1, 'available_qty' => 1]);

        $movementCountBefore = DB::table('stock_movements')->count();

        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reversals", [
            'reason' => 'Thử đảo receipt đã bị tiêu thụ',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 5]],
        ], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'f05-dep-'.Str::random(12),
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOWNSTREAM_DEPENDENCY_EXISTS');

        $this->assertSame($movementCountBefore, DB::table('stock_movements')->count(), 'Guard không được ghi movement.');

        // Preflight của REV-01 phải nói đúng điều backend sẽ enforce.
        $reversible = $this->getJson('/api/v1/stock-movements/reversible?reversible_only=1', $headers);
        $reversible->assertOk();
        $row = collect($reversible->json('data'))->firstWhere('id', $fixture['movement_id']);
        $this->assertNotNull($row, 'Movement gốc phải xuất hiện trong lookup.');
        $this->assertFalse($row['can_reverse']);
        $this->assertSame('DOWNSTREAM_CONSUMPTION_EXISTS', $row['dependency_guard']);
    }

    // ── REQ-SRS-030 / BR-TRACE-01..06 ─────────────────────────────────────────

    public function test_trace_resolves_item_container_and_sku_codes_case_insensitively(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->canonicalWarehouse();
        $sku = Sku::create([
            'sku_code' => 'DCH-F05-TRACE-01',
            'sku_name' => 'Máy khoan trace fixture',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);

        $containerId = (string) Str::uuid();
        DB::table('containers')->insert([
            'container_id' => $containerId,
            'container_code' => 'TRAY-F05-01',
            'container_type' => 'TRAY',
            // 'ACTIVE' was the legacy container status value; migration
            // 2026_08_10_000670_align_identity_container_and_scan_lifecycle.php
            // backfilled every 'ACTIVE' row to 'IN_STOCK' and the PostgreSQL
            // CHECK constraint chk_containers_status_current (added by the same
            // migration) only allows EMPTY/IN_STOCK/PARTIAL/ISSUED/
            // RECEIVED_PENDING_PUTAWAY/STORED/CLOSED. sqlite has no CHECK
            // constraint enforcement so this fixture silently inserted an
            // invalid value there while failing on the canonical PostgreSQL
            // reference DB (SQLSTATE 23514).
            'status' => 'IN_STOCK',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $sku->sku_id,
            'item_code' => 'ITEM-F05-A001',
            'serial_number' => 'SN-F05-0001',
            'item_status' => 'IN_STOCK',
            'container_id' => $containerId,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Wireframe TRACE-01 nhận "Item code / Box / Tray / RFID"; item chưa gán tem
        // vật lý vẫn phải tra được, và không phân biệt hoa thường.
        foreach ([
            ['ITEM-F05-A001', 'ITEM', $itemId, 'item_code'],
            ['item-f05-a001', 'ITEM', $itemId, 'item_code'],
            ['SN-F05-0001', 'ITEM', $itemId, 'item_code'],
            ['TRAY-F05-01', 'CONTAINER', $containerId, 'container_code'],
            ['tray-f05-01', 'CONTAINER', $containerId, 'container_code'],
            ['DCH-F05-TRACE-01', 'SKU', (string) $sku->sku_id, 'sku_code'],
        ] as [$code, $type, $id, $resolvedBy]) {
            $this->getJson('/api/v1/inventory/trace/'.rawurlencode($code), $headers)
                ->assertOk()
                ->assertJsonPath('data.resolved_type', $type)
                ->assertJsonPath('data.resolved_id', $id)
                ->assertJsonPath('data.resolved_by', $resolvedBy);
        }

        $this->getJson('/api/v1/inventory/trace/KHONG-TON-TAI-XYZ', $headers)
            ->assertStatus(404);
    }

    // ── §7.5: ba lớp phải nói cùng một con số ─────────────────────────────────

    public function test_subledger_counts_match_the_drilldown_rows(): void
    {
        $headers = $this->authHeaders();
        $fixture = $this->postedComponentReceipt($headers, 'COMP-F05-SUB-01', 6);

        $list = $this->getJson('/api/v1/inventory?keyword=COMP-F05-SUB-01', $headers);
        $list->assertOk();
        $row = collect($list->json('data'))->firstWhere('sku_id', $fixture['sku_id']);
        $this->assertNotNull($row, 'Filter keyword phải tìm được SKU vừa nhập.');
        $this->assertSame('AVAILABLE', $row['stock_status']);

        $drill = $this->getJson("/api/v1/inventory/balances/{$row['id']}/subledger", $headers);
        $drill->assertOk();

        $this->assertSame(
            $row['item_subledger_count'],
            $drill->json('data.item_count'),
            'Số đếm item subledger trên INV-01 phải bằng số dòng drill-down (§7.5).'
        );
        $this->assertSame(
            $row['container_subledger_count'],
            $drill->json('data.container_count'),
            'Số đếm container subledger phải bằng số container trong drill-down.'
        );
        $this->assertCount($drill->json('data.item_count'), $drill->json('data.items'));
    }

    public function test_inventory_list_filters_by_stock_status_and_sku_type(): void
    {
        $headers = $this->authHeaders();
        $fixture = $this->postedComponentReceipt($headers, 'COMP-F05-FILTER-01', 2);

        $this->getJson('/api/v1/inventory?stock_status=AVAILABLE&sku_type=COMPONENT', $headers)
            ->assertOk()
            ->assertJsonFragment(['sku_id' => $fixture['sku_id']]);

        // DEFECTIVE bucket chưa có dòng nào cho SKU này.
        $defective = $this->getJson('/api/v1/inventory?stock_status=DEFECTIVE', $headers);
        $defective->assertOk();
        $this->assertNull(
            collect($defective->json('data'))->firstWhere('sku_id', $fixture['sku_id']),
            'Filter stock_status phải lọc đúng bucket ERD bảng 29.'
        );

        $this->getJson('/api/v1/inventory?stock_status=NOT_A_BUCKET', $headers)
            ->assertStatus(422);
    }
}
