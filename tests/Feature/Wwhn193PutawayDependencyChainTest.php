<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use RuntimeException;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\TestCase;

/**
 * WWHN-193 · EXT-DEP-01R — chuỗi phụ thuộc WWHN-43/190/191/192 chạy trên CÙNG MỘT build.
 *
 * Reviewer: "Dependency chưa qualified — cần Post Receipt -> movement/balance -> eligible
 * BOX -> Putaway E2E cùng build, kèm reconcile."
 *
 * Khác với `FoundationInboundPutawayReadinessTest` (đi hết golden path nhưng không đo tồn),
 * bài này đo và ghi lại từng mắt xích bằng số liệu thô: movement được sinh đúng một lần,
 * `inventory_balances` khớp đúng số lượng đã Post, BOX chuyển sang trạng thái eligible KÈM
 * linkage phiếu nhập POSTED thật, và toàn bộ vòng đời putaway sau đó không đụng vào tồn
 * (AC-SRS-009). Kết thúc bằng một phép đối soát movement-vs-balance mismatch = 0 (§7.5).
 *
 * Evidence: `build/evidence/wwhn-193/dependency-chain-proof.json`.
 */
class Wwhn193PutawayDependencyChainTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private static array $evidence = [];

    public static function tearDownAfterClass(): void
    {
        if (self::$evidence !== []) {
            $directory = dirname(__DIR__, 2).'/build/evidence/wwhn-193';
            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new RuntimeException("Không tạo được thư mục evidence: {$directory}");
            }
            $path = $directory.'/dependency-chain-proof.json';
            $written = file_put_contents(
                $path,
                json_encode(self::$evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            if ($written === false) {
                throw new RuntimeException("Không ghi được evidence: {$path}");
            }
            self::$evidence = [];
        }

        parent::tearDownAfterClass();
    }

    private function authHeaders(): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'SUPER_ADMIN', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => 'ptw-e2e-'.Str::lower(Str::random(6)),
            'display_name' => 'Putaway E2E Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function activeWarehouse(): Warehouse
    {
        return Warehouse::query()->where('status', 'ACTIVE')->first()
            ?? Warehouse::create(['code' => 'KHO-TONG-HN', 'name' => 'Kho tổng Hòa Nam', 'status' => 'ACTIVE']);
    }

    /**
     * @return array{rows_checked:int, mismatch_count:int, rows:list<array<string,mixed>>}
     */
    private function reconcileMovementsAgainstBalances(): array
    {
        $movementTotals = DB::table('stock_movements')
            ->selectRaw('warehouse_id, product_id, SUM(quantity) as net')
            ->groupBy('warehouse_id', 'product_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->warehouse_id.'|'.$row->product_id => (int) $row->net])
            ->all();

        $balanceTotals = DB::table('inventory_balances')
            ->selectRaw('warehouse_id, product_id, SUM(quantity) as total')
            ->groupBy('warehouse_id', 'product_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->warehouse_id.'|'.$row->product_id => (int) $row->total])
            ->all();

        $keys = array_unique(array_merge(array_keys($movementTotals), array_keys($balanceTotals)));
        $rows = [];
        $mismatch = 0;
        foreach ($keys as $key) {
            $ledger = $movementTotals[$key] ?? 0;
            $projection = $balanceTotals[$key] ?? 0;
            $ok = $ledger === $projection;
            if (! $ok) {
                $mismatch++;
            }
            $rows[] = ['key' => $key, 'ledger_net' => $ledger, 'balance_total' => $projection, 'match' => $ok];
        }

        return ['rows_checked' => count($rows), 'mismatch_count' => $mismatch, 'rows' => $rows];
    }

    public function test_post_receipt_produces_movement_balance_then_eligible_box_then_stock_neutral_putaway(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->activeWarehouse();
        $quantity = 7;

        $sku = Sku::create([
            'sku_code' => 'COMP-EXTDEP-001',
            'sku_name' => 'Component chuỗi phụ thuộc',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $tray = Container::create([
            'container_code' => 'TRAY-EXTDEP-001',
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'zone_code' => 'COMPONENT_AREA',
            'version' => 1,
        ]);

        // ── Mắt xích 1 · phiếu nhập COMPONENT header-only (GĐ1 scan-driven) ─
        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'NCC chuỗi phụ thuộc',
        ]);
        $documentId = $document['document_id'];
        $lineId = (string) $this->postJson(
            "/api/v1/inbound-documents/{$documentId}/component-lines",
            ['sku_id' => $sku->sku_id],
            $headers
        )->assertOk()->json('data.line_id');

        // ── Mắt xích 2 · đăng ký BOX + scan (WWHN-191) ──────────────────────
        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-EXTDEP-001',
        ], $headers)->assertCreated();
        $boxId = (string) $box->json('data.container_id');
        $this->assertSame('EMPTY', (string) $box->json('data.status'));

        $scan = $this->postJson("/api/v1/inbound-documents/{$documentId}/scans", [
            'line_id' => $lineId,
            'quantity' => $quantity,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => (string) $document['version']])
            ->assertOk()
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);
        $versionAfterScan = (int) $scan->json('data.version');

        $this->assertSame(0, DB::table('stock_movements')->count(), 'Scan phải stock-neutral.');

        // ── Mắt xích 3 · Post Receipt -> movement + balance (WWHN-192) ──────
        $post = $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => (string) $versionAfterScan,
            'Idempotency-Key' => 'wwhn193-extdep-receipt-001',
        ]);
        $post->assertOk()
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.stock_effect', 'INCREASE');

        $movementRows = DB::table('stock_movements')->get();
        $this->assertCount(1, $movementRows, 'Post Receipt phải sinh đúng một movement.');
        $this->assertSame($quantity, (int) $movementRows->first()->quantity);

        $balance = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $sku->sku_id)
            ->sum('quantity');
        $this->assertSame($quantity, $balance, 'Balance projection phải khớp ledger sau Post Receipt.');

        // ── Mắt xích 4 · BOX trở nên eligible KÈM linkage phiếu POSTED ──────
        $this->assertDatabaseHas('containers', [
            'container_id' => $boxId,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'primary_sku_id' => $sku->sku_id,
        ]);
        $tasks = $this->getJson('/api/v1/putaway-tasks', $headers)->assertOk();
        $listed = collect($tasks->json('data'))->firstWhere('box_container_id', $boxId);
        $this->assertNotNull($listed, 'BOX vừa Post Receipt phải xuất hiện trong danh sách chờ xếp khay.');
        $this->assertSame($documentId, (string) $listed['receipt_id']);
        $this->assertSame($quantity, (int) $listed['quantity']);

        // ── Mắt xích 5 · Putaway DRAFT -> CONFIRMED, stock-neutral ──────────
        $draft = $this->postJson('/api/v1/putaway-tasks', [
            'box_container_id' => $boxId,
            'to_tray_id' => $tray->container_id,
            'location_hint' => 'A-EXTDEP-01',
        ], $headers)->assertCreated();
        $eventId = (string) $draft->json('data.id');
        $this->assertDatabaseHas('putaway_events', [
            'id' => $eventId,
            'status' => 'DRAFT',
            'stock_document_id' => $documentId,
            'warehouse_id' => $warehouse->id,
        ]);

        $confirm = $this->postJson("/api/v1/putaway-tasks/{$eventId}/confirm", [], $headers + ['If-Match' => '1']);
        $confirm->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.box_status', 'STORED')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        // Putaway KHÔNG được đụng tồn: cả ledger lẫn projection giữ nguyên.
        $this->assertCount(1, DB::table('stock_movements')->get());
        $this->assertSame($quantity, (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $sku->sku_id)
            ->sum('quantity'));
        $this->assertDatabaseHas('containers', [
            'container_id' => $boxId,
            'parent_container_id' => $tray->container_id,
            'status' => 'STORED',
            'location_hint' => 'A-EXTDEP-01',
        ]);

        // ── Mắt xích 6 · đối soát ledger vs projection (§7.5) ───────────────
        $reconcile = $this->reconcileMovementsAgainstBalances();
        $this->assertSame(0, $reconcile['mismatch_count'], 'Đối soát movement vs balance phải 0 lệch.');
        $this->assertGreaterThan(0, $reconcile['rows_checked']);

        self::$evidence['dependency_chain'] = [
            'jira_dependencies' => ['WWHN-43', 'WWHN-190', 'WWHN-191', 'WWHN-192'],
            'same_build' => true,
            'quantity' => $quantity,
            'document_id' => $documentId,
            'box_container_id' => $boxId,
            'putaway_event_id' => $eventId,
            'steps' => [
                ['step' => 'create_inbound_document', 'http' => 201, 'stock_effect' => 'NONE'],
                ['step' => 'register_box', 'http' => 201, 'box_status' => 'EMPTY', 'stock_effect' => 'NONE'],
                ['step' => 'scan', 'http' => 200, 'movements_after' => 0, 'stock_effect' => 'NONE'],
                ['step' => 'post_receipt', 'http' => 200, 'movements_after' => 1, 'balance_after' => $quantity, 'stock_effect' => 'INCREASE'],
                ['step' => 'box_eligible', 'box_status' => 'RECEIVED_PENDING_PUTAWAY', 'receipt_id' => $documentId],
                ['step' => 'putaway_draft', 'http' => 201, 'stock_document_id' => $documentId, 'stock_effect' => 'NONE'],
                ['step' => 'putaway_confirm', 'http' => 200, 'box_status' => 'STORED', 'movements_after' => 1, 'balance_after' => $quantity, 'stock_effect' => 'NONE'],
            ],
            'reconcile' => [
                'rows_checked' => $reconcile['rows_checked'],
                'mismatch_count' => $reconcile['mismatch_count'],
                'rows' => $reconcile['rows'],
            ],
            'verdict' => 'PASS — chuỗi phụ thuộc qualified trên cùng build, mismatch=0',
        ];
    }

    /**
     * Mắt xích ngược: BOX chưa Post Receipt thì không có đường nào vào Putaway — kể cả khi
     * BOX đã được đăng ký cho phiếu và đã scan. Đây là điều kiện làm cho chuỗi phụ thuộc
     * trên có ý nghĩa: Putaway thật sự phụ thuộc Post Receipt chứ không chỉ tình cờ chạy sau.
     */
    public function test_putaway_is_blocked_until_receipt_is_actually_posted(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->activeWarehouse();

        $sku = Sku::create([
            'sku_code' => 'COMP-EXTDEP-002',
            'sku_name' => 'Component chưa post',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $tray = Container::create([
            'container_code' => 'TRAY-EXTDEP-002',
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'NCC chưa post',
        ]);
        $documentId = $document['document_id'];
        $lineId = (string) $this->postJson(
            "/api/v1/inbound-documents/{$documentId}/component-lines",
            ['sku_id' => $sku->sku_id],
            $headers
        )->assertOk()->json('data.line_id');

        $boxId = (string) $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-EXTDEP-002',
        ], $headers)->assertCreated()->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scans", [
            'line_id' => $lineId,
            'quantity' => 3,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => (string) $document['version']])->assertOk();

        // Chưa Post: BOX vẫn EMPTY, và putaway phải bị chặn.
        $this->assertDatabaseHas('containers', ['container_id' => $boxId, 'status' => 'EMPTY']);
        $blocked = $this->postJson('/api/v1/putaway-tasks', [
            'box_container_id' => $boxId,
            'to_tray_id' => $tray->container_id,
        ], $headers);
        $blocked->assertStatus(409)->assertJsonPath('error.code', 'BOX_NOT_PUTAWAY_ELIGIBLE');

        $this->assertSame(0, DB::table('putaway_events')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());

        self::$evidence['negative_dependency'] = [
            'scenario' => 'BOX đã scan nhưng phiếu chưa POSTED',
            'http' => 409,
            'error_code' => (string) $blocked->json('error.code'),
            'putaway_events' => 0,
            'movements' => 0,
            'verdict' => 'PASS — Putaway thực sự phụ thuộc Post Receipt',
        ];
    }
}
