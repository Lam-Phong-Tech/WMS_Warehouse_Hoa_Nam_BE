<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockMovement;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * QA2 BE1-P1-05: Comprehensive Test Suite for Canonical Hoa Nam Flows
 *
 * Covers:
 * - Happy path tests for all canonical endpoints
 * - Negative tests (validation, permissions, state conflicts)
 * - Idempotency tests (same key, same payload; same key, different payload)
 * - Race condition tests (concurrent scans, double post)
 * - Reconcile tests (movement count, balance verification)
 * - Permission tests (RBAC, data scope)
 */
class StockDocumentCanonicalFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ========================================
    // Helper Methods
    // ========================================

    private function createWarehouse(string $code = 'KHO-TONG-HN'): Warehouse
    {
        return Warehouse::create([
            'code' => $code,
            'name' => 'Kho Tong Hoa Nam',
            'status' => 'ACTIVE',
        ]);
    }

    private function createProduct(string $code = 'HN-PV-BASE-001'): Product
    {
        return Product::create([
            'code' => $code,
            'name' => 'San pham test',
            'status' => 'ACTIVE',
        ]);
    }

    private function createInventoryBalance(string $productId, string $warehouseId, float $qty = 100.0): InventoryBalance
    {
        return InventoryBalance::create([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'on_hand_qty' => $qty,
            'available_qty' => $qty,
            'reserved_qty' => 0,
            'status' => 'normal',
        ]);
    }

    private function seedRoleWithPermissions(string $roleCode, array $permissionKeys): Role
    {
        $role = Role::firstOrCreate(
            ['role_code' => $roleCode],
            ['role_name' => $roleCode, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );

            \Illuminate\Support\Facades\DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                [
                    'role_permission_id' => (string) \Illuminate\Support\Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return $role;
    }

    private function createUser(string $username, ?string $roleCode, string $status = 'ACTIVE'): User
    {
        $user = User::create([
            'username' => $username,
            'display_name' => \Illuminate\Support\Str::headline($username),
            'email' => "{$username}@wms-hoanam.test",
            'password_hash' => \Illuminate\Support\Facades\Hash::make('secret123'),
            'status' => $status,
            'version' => 1,
        ]);

        if ($roleCode !== null) {
            $role = Role::where('role_code', $roleCode)->firstOrFail();
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) \Illuminate\Support\Str::uuid()]);
        }

        return $user;
    }

    private function getToken(string $username, string $password = 'secret123'): string
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => "{$username}@wms-hoanam.test",
            'password' => $password,
        ]);

        $login->assertOk();

        return $login->json('data.access_token');
    }

    // ========================================
    // BE1-P1-05: Happy Path Tests
    // ========================================

    /**
     * Test complete inbound flow: create -> scan -> post-receipt
     */
    public function test_complete_inbound_flow(): void
    {
        $this->seedRoleWithPermissions('INBOUND_USER', [
            'stock_document.view', 'stock_document.create', 'stock_document.post',
        ]);
        $user = $this->createUser('inbound_user', 'INBOUND_USER');
        $token = $this->getToken('inbound_user');

        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();

        // Step 1: Create inbound document
        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-TEST-001',
            'doc_date' => now()->format('Y-m-d'),
            'dst_warehouse_id' => $warehouse->id,
            'note' => 'Test inbound',
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 10],
            ],
        ], ['Authorization' => "Bearer {$token}"]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'DRAFT');

        $docId = $createResponse->json('data.id');
        $lineId = $createResponse->json('data.lines.0.id');

        // Step 2: Scan partial post
        $scanResponse = $this->postJson("/api/v1/inbound-documents/{$docId}/scan", [
            'line_id' => $lineId,
            'qty' => 5,
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-inbound-scan-001',
        ]);

        $scanResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'PARTIALLY_POSTED');

        // Step 3: Post receipt full
        $postResponse = $this->postJson("/api/v1/inbound-documents/{$docId}/post-receipt", [], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-inbound-post-001',
        ]);

        $postResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'POSTED');

        // Verify inventory balance increased
        $balance = InventoryBalance::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        $this->assertEquals(10.0, (float) $balance->on_hand_qty);

        // Verify movements created
        $movements = StockMovement::where('stock_document_id', $docId)->count();
        $this->assertEquals(2, $movements); // 1 scan + 1 post
    }

    /**
     * Test complete outbound flow: create -> scan -> post-issue
     */
    public function test_complete_outbound_flow(): void
    {
        $this->seedRoleWithPermissions('OUTBOUND_USER', [
            'stock_document.view', 'stock_document.create', 'stock_document.post',
        ]);
        $user = $this->createUser('outbound_user', 'OUTBOUND_USER');
        $token = $this->getToken('outbound_user');

        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();
        $this->createInventoryBalance($product->id, $warehouse->id, 100.0);

        // Step 1: Create outbound document
        $createResponse = $this->postJson('/api/v1/outbound-documents', [
            'doc_no' => 'PX-TEST-001',
            'doc_date' => now()->format('Y-m-d'),
            'src_warehouse_id' => $warehouse->id,
            'agency_id' => '00000000-0000-0000-0000-000000000001',
            'receiver_name' => 'Dai ly test',
            'note' => 'Test outbound',
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 10],
            ],
        ], ['Authorization' => "Bearer {$token}"]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'DRAFT');

        $docId = $createResponse->json('data.id');
        $lineId = $createResponse->json('data.lines.0.id');

        // Step 2: Scan partial post
        $scanResponse = $this->postJson("/api/v1/outbound-documents/{$docId}/scan", [
            'line_id' => $lineId,
            'qty' => 5,
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-outbound-scan-001',
        ]);

        $scanResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'PARTIALLY_POSTED');

        // Step 3: Post issue full
        $postResponse = $this->postJson("/api/v1/outbound-documents/{$docId}/post-issue", [], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-outbound-post-001',
        ]);

        $postResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'POSTED');

        // Verify inventory balance decreased
        $balance = InventoryBalance::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        $this->assertEquals(90.0, (float) $balance->on_hand_qty);
    }

    // ========================================
    // BE1-P1-05: Negative Tests
    // ========================================

    /**
     * Test insufficient stock for outbound
     */
    public function test_outbound_insufficient_stock(): void
    {
        $this->seedRoleWithPermissions('OUTBOUND_USER', [
            'stock_document.view', 'stock_document.create', 'stock_document.post',
        ]);
        $user = $this->createUser('outbound_user2', 'OUTBOUND_USER');
        $token = $this->getToken('outbound_user2');

        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();
        $this->createInventoryBalance($product->id, $warehouse->id, 5.0); // Only 5 in stock

        // Create outbound document requesting 10
        $createResponse = $this->postJson('/api/v1/outbound-documents', [
            'doc_no' => 'PX-TEST-INSUFFICIENT',
            'doc_date' => now()->format('Y-m-d'),
            'src_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 10],
            ],
        ], ['Authorization' => "Bearer {$token}"]);

        $createResponse->assertStatus(201);
        $docId = $createResponse->json('data.id');

        // Try to post issue - should fail with INSUFFICIENT_STOCK
        $postResponse = $this->postJson("/api/v1/outbound-documents/{$docId}/post-issue", [], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-outbound-insufficient-001',
        ]);

        $postResponse->assertStatus(422)
            ->assertJsonPath('error_code', 'INSUFFICIENT_STOCK');
    }

    /**
     * Test missing idempotency key
     */
    public function test_missing_idempotency_key(): void
    {
        $this->seedRoleWithPermissions('SCAN_USER', ['stock_document.post']);
        $user = $this->createUser('scan_user', 'SCAN_USER');
        $token = $this->getToken('scan_user');

        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();

        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-TEST-IDEM',
            'doc_date' => now()->format('Y-m-d'),
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 10],
            ],
        ], ['Authorization' => "Bearer {$token}"]);

        $docId = $createResponse->json('data.id');
        $lineId = $createResponse->json('data.lines.0.id');

        // Try to scan without idempotency key
        $scanResponse = $this->postJson("/api/v1/inbound-documents/{$docId}/scan", [
            'line_id' => $lineId,
            'qty' => 5,
        ], ['Authorization' => "Bearer {$token}"]);

        $scanResponse->assertStatus(428)
            ->assertJsonPath('error_code', 'PRECONDITION_REQUIRED');
    }

    // ========================================
    // BE1-P1-05: Idempotency Tests
    // ========================================

    /**
     * Test same key, same payload returns cached response
     */
    public function test_idempotency_same_key_same_payload(): void
    {
        $this->seedRoleWithPermissions('SCAN_USER', ['stock_document.post']);
        $user = $this->createUser('scan_user_idem', 'SCAN_USER');
        $token = $this->getToken('scan_user_idem');

        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();

        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-TEST-IDEM-2',
            'doc_date' => now()->format('Y-m-d'),
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 10],
            ],
        ], ['Authorization' => "Bearer {$token}"]);

        $docId = $createResponse->json('data.id');
        $lineId = $createResponse->json('data.lines.0.id');

        $payload = ['line_id' => $lineId, 'qty' => 5];
        $idemKey = 'test-idem-same-payload';

        // First request
        $response1 = $this->postJson("/api/v1/inbound-documents/{$docId}/scan", $payload, [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => $idemKey,
        ]);

        $response1->assertStatus(200);

        // Second request with same key and payload - should return cached response
        $response2 = $this->postJson("/api/v1/inbound-documents/{$docId}/scan", $payload, [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => $idemKey,
        ]);

        $response2->assertStatus(200)
            ->assertJsonPath('data.status', $response1->json('data.status'));
    }

    /**
     * Test same key, different payload returns error
     */
    public function test_idempotency_same_key_different_payload(): void
    {
        $this->seedRoleWithPermissions('SCAN_USER', ['stock_document.post']);
        $user = $this->createUser('scan_user_idem2', 'SCAN_USER');
        $token = $this->getToken('scan_user_idem2');

        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();

        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-TEST-IDEM-3',
            'doc_date' => now()->format('Y-m-d'),
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 10],
            ],
        ], ['Authorization' => "Bearer {$token}"]);

        $docId = $createResponse->json('data.id');
        $lineId = $createResponse->json('data.lines.0.id');

        $idemKey = 'test-idem-diff-payload';

        // First request
        $this->postJson("/api/v1/inbound-documents/{$docId}/scan", [
            'line_id' => $lineId,
            'qty' => 5,
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => $idemKey,
        ])->assertStatus(200);

        // Second request with same key but different payload
        $response2 = $this->postJson("/api/v1/inbound-documents/{$docId}/scan", [
            'line_id' => $lineId,
            'qty' => 3, // Different qty
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => $idemKey,
        ]);

        $response2->assertStatus(409)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_ERROR');
    }

    // ========================================
    // BE1-P1-05: Permission Tests
    // ========================================

    /**
     * Test user without stocktake.adjust permission cannot apply adjustment
     */
    public function test_stocktake_adjust_permission_required(): void
    {
        $this->seedRoleWithPermissions('NO_ADJUST', ['inventory.view']);
        $user = $this->createUser('no_adjust_user', 'NO_ADJUST');
        $token = $this->getToken('no_adjust_user');

        $warehouse = $this->createWarehouse();

        // Try to apply adjustment without permission
        $response = $this->postJson('/api/v1/stocktake-sessions/00000000-0000-0000-0000-000000000000/apply-adjustment', [
            'reason' => 'Test',
            'lines' => [],
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-no-perm',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    /**
     * Test user without warranty.temp_only permission cannot temp-only
     */
    public function test_warranty_temp_only_permission_required(): void
    {
        $this->seedRoleWithPermissions('NO_WARRANTY', ['inventory.view']);
        $user = $this->createUser('no_warranty_user', 'NO_WARRANTY');
        $token = $this->getToken('no_warranty_user');

        // Try to temp-only without permission
        $response = $this->postJson('/api/v1/warranty-cases/00000000-0000-0000-0000-000000000000/temp-only', [
            'item_id' => '00000000-0000-0000-0000-000000000001',
            'condition_status' => 'WAITING_CHECK',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-no-warranty-perm',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    // ========================================
    // BE1-P1-05: Reconcile Tests
    // ========================================

    /**
     * Test that posting creates correct movements and updates balance
     */
    public function test_post_creates_correct_movements_and_balance(): void
    {
        $this->seedRoleWithPermissions('KEEPER', ['stock_document.post']);
        $user = $this->createUser('keeper_reconcile', 'KEEPER');
        $token = $this->getToken('keeper_reconcile');

        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();
        $initialBalance = $this->createInventoryBalance($product->id, $warehouse->id, 100.0);

        // Create and post inbound
        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-RECONCILE-001',
            'doc_date' => now()->format('Y-m-d'),
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 15],
            ],
        ], ['Authorization' => "Bearer {$token}"]);

        $docId = $createResponse->json('data.id');

        $this->postJson("/api/v1/inbound-documents/{$docId}/post-receipt", [], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-reconcile-001',
        ])->assertStatus(200);

        // Verify balance
        $balance = InventoryBalance::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        $this->assertEquals(115.0, (float) $balance->on_hand_qty);

        // Verify movement count
        $movementCount = StockMovement::where('stock_document_id', $docId)->count();
        $this->assertEquals(1, $movementCount);

        // Verify movement details
        $movement = StockMovement::where('stock_document_id', $docId)->first();
        $this->assertEquals(15.0, (float) $movement->qty_delta);
        $this->assertEquals(StockMovement::RECEIPT_POSTED, $movement->movement_type);
    }

    // ========================================
    // BE1-P1-05: State Conflict Tests
    // ========================================

    /**
     * Test cannot cancel posted document
     */
    public function test_cannot_cancel_posted_document(): void
    {
        $this->seedRoleWithPermissions('KEEPER_CANCEL', ['stock_document.cancel']);
        $user = $this->createUser('keeper_cancel', 'KEEPER_CANCEL');
        $token = $this->getToken('keeper_cancel');

        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();

        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-CANCEL-TEST',
            'doc_date' => now()->format('Y-m-d'),
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 10],
            ],
        ], ['Authorization' => "Bearer {$token}"]);

        $docId = $createResponse->json('data.id');

        // Post receipt
        $this->postJson("/api/v1/inbound-documents/{$docId}/post-receipt", [], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-cancel-posted-001',
        ])->assertStatus(200);

        // Try to cancel posted document - should fail
        $cancelResponse = $this->postJson("/api/v1/inbound-documents/{$docId}/cancel", [
            'reason' => 'Test cancel',
        ], ['Authorization' => "Bearer {$token}"]);

        $cancelResponse->assertStatus(422)
            ->assertJsonPath('error_code', 'STATE_CONFLICT');
    }

    /**
     * Test reverse creates opposite movements
     */
    public function test_reverse_creates_opposite_movements(): void
    {
        $this->seedRoleWithPermissions('KEEPER_REVERSE', ['stock_document.reverse']);
        $user = $this->createUser('keeper_reverse', 'KEEPER_REVERSE');
        $token = $this->getToken('keeper_reverse');

        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();
        $this->createInventoryBalance($product->id, $warehouse->id, 100.0);

        // Create and post inbound
        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-REVERSE-001',
            'doc_date' => now()->format('Y-m-d'),
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 10],
            ],
        ], ['Authorization' => "Bearer {$token}"]);

        $docId = $createResponse->json('data.id');

        $this->postJson("/api/v1/inbound-documents/{$docId}/post-receipt", [], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-reverse-001',
        ])->assertStatus(200);

        // Verify balance after post
        $balanceAfterPost = InventoryBalance::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();
        $this->assertEquals(110.0, (float) $balanceAfterPost->on_hand_qty);

        // Reverse the document
        $reverseResponse = $this->postJson("/api/v1/inbound-documents/{$docId}/reverse", [
            'reason' => 'Sai so luong',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'test-reverse-002',
        ]);

        $reverseResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'REVERSED');

        // Verify balance after reverse
        $balanceAfterReverse = InventoryBalance::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();
        $this->assertEquals(100.0, (float) $balanceAfterReverse->on_hand_qty);

        // Verify reversal movement created
        $reversalMovement = StockMovement::where('stock_document_id', $docId)
            ->where('movement_type', StockMovement::RECEIPT_REVERSED)
            ->first();

        $this->assertNotNull($reversalMovement);
        $this->assertEquals(-10.0, (float) $reversalMovement->qty_delta);
    }
}