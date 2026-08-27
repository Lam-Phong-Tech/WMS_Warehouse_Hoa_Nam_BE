<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\TestCase;

/**
 * WWHN-207 (HN-FE-003, output "Inbound FE" — rà soát BE trước khi FE tích hợp).
 *
 * FoundationInboundPutawayReadinessTest/Wwhn193PutawayLifecycleTest cover đầy đủ
 * create → scan → post-receipt → putaway, nhưng không có test HTTP end-to-end
 * nào lái qua POST /inbound-documents/{id}/reverse (chỉ có route-surface
 * assertion tĩnh trong DocumentationBaselineContractTest). File này lái qua
 * flow thật để xác nhận CurrentBaselineReversalService::execute() hoạt động
 * đúng cho nhánh receipt (COMPONENT, quantity-mode) theo đúng phương pháp đã
 * dùng ở WWHN-190/191/202.
 */
class Wwhn207InboundReversalFlowTest extends TestCase
{
    use BuildsScanDrivenInbound;
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
            'username' => 'rev-in-'.Str::lower(Str::random(6)),
            'display_name' => 'Reversal Inbound Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function userWithoutReversalPermission(): array
    {
        $roleCode = 'REV_NOPERM_'.Str::upper(Str::random(6));
        $role = Role::create(['role_code' => $roleCode, 'role_name' => $roleCode, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        foreach (['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post'] as $permissionKey) {
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
            'username' => 'rev-in-noperm-'.Str::lower(Str::random(6)),
            'display_name' => 'Reversal Inbound No Perm',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function canonicalWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']
        );
    }

    /**
     * Tạo + scan + post một receipt COMPONENT hoàn chỉnh qua HTTP thật, trả về
     * toàn bộ id cần cho bước reverse (document_id, movement_id, line_id).
     */
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

        // Contract GĐ1 scan-driven: header-only + component-lines + Box + đếm thực tế.
        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'Reversal fixture',
        ]);
        $documentId = $document['document_id'];

        $received = $this->receiveComponentQuantity(
            $headers,
            $documentId,
            (string) $sku->sku_id,
            $qty,
            $document['version']
        );

        $post = $this->postInboundReceipt($headers, $documentId, $received['version'], 'receipt-'.Str::random(12));
        $post->assertOk()->assertJsonPath('data.status', 'POSTED');
        $postedVersion = (int) ($post->json('data.version') ?? ($received['version'] + 1));

        $movementId = (string) DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'RECEIPT')
            ->value('id');

        return [
            'document_id' => $documentId,
            'line_id' => $received['line_id'],
            'box_id' => $received['container_id'],
            'movement_id' => $movementId,
            'sku_id' => (string) $sku->sku_id,
            'warehouse_id' => (string) $warehouse->id,
            'qty' => $qty,
            'version' => $postedVersion,
        ];
    }

    /**
     * "Chứng từ nhập/xuất/hoàn trả": Create luôn dừng ở DRAFT (stock_effect=NONE);
     * đính kèm chứng từ rồi Post riêng mới thật sự ghi movement/tồn.
     */
    private function attachEvidenceThenPost(array $headers, string $reversalId, int $version, string $idempotencyKey)
    {
        $this->post("/api/v1/reversal-documents/{$reversalId}/attachments", [
            'file' => UploadedFile::fake()->image('w207-bien-ban-'.Str::lower(Str::random(6)).'.jpg'),
        ], $headers)->assertCreated();

        return $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + [
            'If-Match' => (string) $version,
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    public function test_reverse_inbound_partial_quantity_decreases_balance_and_creates_reversal_movement(): void
    {
        Storage::fake('local');
        $headers = $this->authHeaders();
        $fixture = $this->postedComponentReceipt($headers, 'COMP-REV-PARTIAL-01', 5);

        $balanceBeforeReverse = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('quantity');
        $this->assertSame(5, $balanceBeforeReverse);

        $reverse = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Nhập nhầm số lượng, trả lại 2',
            'lines' => [[
                'movement_id' => $fixture['movement_id'],
                'quantity' => 2,
            ]],
        ], $headers + [
            'If-Match' => (string) $fixture['version'],
            'Idempotency-Key' => 'reverse-partial-'.Str::random(12),
        ]);

        $reverse->assertOk()
            ->assertJsonPath('data.original_document_status', 'POSTED')
            ->assertJsonPath('data.reversal_status', 'DRAFT');
        $reversalDocumentId = (string) $reverse->json('data.reversal_document_id');
        $this->assertNotSame('', $reversalDocumentId);
        $this->attachEvidenceThenPost($headers, $reversalDocumentId, 1, 'reverse-partial-post-'.Str::random(12))
            ->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');

        $balanceAfterReverse = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('quantity');
        $this->assertSame(3, $balanceAfterReverse, 'Reverse 2/5 phải để lại đúng 3 trong balance.');

        $this->assertDatabaseHas('stock_movements', [
            'source_id' => $reversalDocumentId,
            'movement_type' => 'REVERSAL',
            'qty_delta' => -2,
        ]);

        // Original document vẫn giữ nguyên POSTED — reversal là chứng từ riêng, không sửa document gốc.
        $this->assertDatabaseHas('stock_documents', [
            'id' => $fixture['document_id'],
            'status' => 'POSTED',
        ]);
    }

    public function test_reverse_inbound_full_quantity_then_second_reverse_is_rejected(): void
    {
        Storage::fake('local');
        $headers = $this->authHeaders();
        $fixture = $this->postedComponentReceipt($headers, 'COMP-REV-FULL-01', 3);

        $first = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Trả toàn bộ do lỗi NCC',
            'lines' => [[
                'movement_id' => $fixture['movement_id'],
                'quantity' => 3,
            ]],
        ], $headers + [
            'If-Match' => (string) $fixture['version'],
            'Idempotency-Key' => 'reverse-full-'.Str::random(12),
        ]);
        $first->assertOk()->assertJsonPath('data.reversal_status', 'DRAFT');
        // REVERSAL_QTY_EXCEEDED bên dưới chỉ tính trên reversal đã POSTED nên phải
        // đính kèm chứng từ rồi Post thật sự trước khi thử reverse thêm.
        $this->attachEvidenceThenPost($headers, (string) $first->json('data.reversal_document_id'), 1, 'reverse-full-post-'.Str::random(12))
            ->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');

        $balanceAfterFullReverse = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('quantity');
        $this->assertSame(0, $balanceAfterFullReverse);

        // Đã reverse hết 3/3 — thử reverse thêm phải bị chặn bởi REVERSAL_QTY_EXCEEDED,
        // không được âm tồn hoặc tạo thêm movement.
        $movementCountBeforeSecondAttempt = DB::table('stock_movements')->count();
        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Thử reverse thêm sau khi đã hết',
            'lines' => [[
                'movement_id' => $fixture['movement_id'],
                'quantity' => 1,
            ]],
        ], $headers + [
            'If-Match' => (string) $fixture['version'],
            'Idempotency-Key' => 'reverse-overreverse-'.Str::random(12),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REVERSAL_QTY_EXCEEDED');

        $this->assertSame($movementCountBeforeSecondAttempt, DB::table('stock_movements')->count());
        $balanceAfterRejected = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('quantity');
        $this->assertSame(0, $balanceAfterRejected);
    }

    public function test_reverse_inbound_replays_idempotently_without_double_effect(): void
    {
        $headers = $this->authHeaders();
        $fixture = $this->postedComponentReceipt($headers, 'COMP-REV-IDEMP-01', 4);

        $idempotencyKey = 'reverse-idem-'.Str::random(12);
        $payload = [
            'reason' => 'Idempotency replay check',
            'lines' => [[
                'movement_id' => $fixture['movement_id'],
                'quantity' => 1,
            ]],
        ];
        $requestHeaders = $headers + ['If-Match' => (string) $fixture['version'], 'Idempotency-Key' => $idempotencyKey];

        $first = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", $payload, $requestHeaders);
        // Create luôn dừng ở DRAFT (stock_effect=NONE) — replay ở mức Create được
        // kiểm ở đây; replay ở mức Post riêng đã có test_reverse_inbound_partial_...
        // và các TC tương đương ở Wwhn266.
        $first->assertOk()->assertHeader('Idempotent-Replay', 'false')->assertJsonPath('data.reversal_status', 'DRAFT');
        $firstReversalId = (string) $first->json('data.reversal_document_id');

        $movementCountAfterFirst = DB::table('stock_movements')->count();
        $balanceAfterFirst = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('quantity');

        $replay = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", $payload, $requestHeaders);
        $replay->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.reversal_document_id', $firstReversalId);

        $this->assertSame($movementCountAfterFirst, DB::table('stock_movements')->count(), 'Replay không được tạo thêm movement.');
        $balanceAfterReplay = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('quantity');
        $this->assertSame($balanceAfterFirst, $balanceAfterReplay, 'Replay không được trừ tồn thêm lần nữa.');
    }

    public function test_reverse_inbound_rejects_quantity_above_reversible_and_invalid_movement(): void
    {
        $headers = $this->authHeaders();
        $fixture = $this->postedComponentReceipt($headers, 'COMP-REV-OVER-01', 2);

        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Yêu cầu vượt số lượng gốc',
            'lines' => [[
                'movement_id' => $fixture['movement_id'],
                'quantity' => 99,
            ]],
        ], $headers + [
            'If-Match' => (string) $fixture['version'],
            'Idempotency-Key' => 'reverse-overqty-'.Str::random(12),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REVERSAL_QTY_EXCEEDED');

        $foreignMovementId = (string) Str::uuid();
        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'movement_id không thuộc document',
            'lines' => [[
                'movement_id' => $foreignMovementId,
                'quantity' => 1,
            ]],
        ], $headers + [
            'If-Match' => (string) $fixture['version'],
            'Idempotency-Key' => 'reverse-badmovement-'.Str::random(12),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ORIGINAL_MOVEMENT_INVALID');

        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $fixture['warehouse_id'],
            'sku_id' => $fixture['sku_id'],
            'quantity' => 2,
        ]);
    }

    public function test_reverse_inbound_requires_reversal_post_permission(): void
    {
        $headers = $this->authHeaders();
        $fixture = $this->postedComponentReceipt($headers, 'COMP-REV-PERM-01', 1);

        $noPermHeaders = $this->userWithoutReversalPermission();
        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Không có quyền reverse',
            'lines' => [[
                'movement_id' => $fixture['movement_id'],
                'quantity' => 1,
            ]],
        ], $noPermHeaders + [
            'If-Match' => (string) $fixture['version'],
            'Idempotency-Key' => 'reverse-noperm-'.Str::random(12),
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->assertDatabaseHas('stock_documents', [
            'id' => $fixture['document_id'],
            'status' => 'POSTED',
        ]);
        $this->assertDatabaseMissing('reversal_document', [
            'stock_document_id' => $fixture['document_id'],
        ]);
    }

    public function test_reverse_inbound_requires_idempotency_key_and_if_match(): void
    {
        $headers = $this->authHeaders();
        $fixture = $this->postedComponentReceipt($headers, 'COMP-REV-HDR-01', 1);

        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Thiếu If-Match',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 1]],
        ], $headers + ['Idempotency-Key' => 'reverse-noifmatch-'.Str::random(12)])
            ->assertStatus(428);

        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Thiếu Idempotency-Key',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 1]],
        ], $headers + ['If-Match' => (string) $fixture['version']])
            ->assertStatus(428);
    }
}
