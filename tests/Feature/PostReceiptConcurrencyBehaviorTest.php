<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * WWHN-190: StockDocumentConcurrencyGuardTest only asserts the guard source
 * strings exist (lockForUpdate()/VERSION_CONFLICT/If-Match literal presence).
 * This suite drives the HTTP contract end-to-end to prove the guards actually
 * reject a second racing writer instead of silently double-applying the
 * stock effect. Genuine two-connection DB-level lock blocking is not
 * reachable under the sqlite ":memory:" test driver (single connection per
 * process), so races are simulated by holding a stale version/state and
 * replaying the second writer's request against it — exactly what a losing
 * racer observes after the winner commits first.
 */
class PostReceiptConcurrencyBehaviorTest extends TestCase
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
            'username' => 'race-'.Str::lower(Str::random(6)),
            'display_name' => 'Race Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create([
            'code' => 'KHO-TONG-HN',
            'name' => 'Kho tổng Hòa Nam',
            'status' => 'ACTIVE',
        ]);
    }

    private function readyToPostDocument(array $headers, string $skuCode): array
    {
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => 'Component race test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Nha cung cap Race',
            'lines' => [[
                'product_id' => $sku->sku_id,
                'qty_planned' => 4,
            ]],
        ], $headers);
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-RACE-'.Str::upper(Str::random(6)),
        ], $headers);
        $boxId = (string) $box->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 4,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        return ['document_id' => $documentId, 'sku_id' => $sku->sku_id];
    }

    /**
     * Two racing writers both read the document at version=2 (post-scan).
     * Writer A posts first and wins, bumping the document to version=3.
     * Writer B replays its stale version=2 If-Match against the now-committed
     * row: the optimistic lock (assertVersion -> PRECONDITION_FAILED) must
     * reject it, and the stock effect must be applied exactly once.
     */
    public function test_stale_version_racer_is_rejected_after_winner_commits_and_stock_effect_applies_once(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'COMP-RACE-VERSION-001');
        $documentId = $doc['document_id'];

        $movementBefore = DB::table('stock_movements')->count();
        $balanceBefore = (int) (DB::table('inventory_balances')->where('sku_id', $doc['sku_id'])->value('on_hand_qty') ?? 0);

        $winnerHeaders = $headers + ['If-Match' => '2', 'Idempotency-Key' => 'race-winner-001'];
        $winner = $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $winnerHeaders);
        $winner->assertOk()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.version', 3);

        // Loser read the document before the winner committed and still holds If-Match: 2.
        $loserHeaders = $headers + ['If-Match' => '2', 'Idempotency-Key' => 'race-loser-001'];
        $loser = $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $loserHeaders);
        $loser->assertStatus(412)->assertJsonPath('error.code', 'PRECONDITION_FAILED');

        $movementAfter = DB::table('stock_movements')->count();
        $this->assertSame($movementBefore + 1, $movementAfter, 'Losing racer must not create a second stock_movement.');

        $balanceAfter = (int) DB::table('inventory_balances')->where('sku_id', $doc['sku_id'])->value('on_hand_qty');
        $this->assertSame($balanceBefore + 4, $balanceAfter, 'Stock effect must be applied exactly once despite the race.');

        $this->assertDatabaseHas('stock_documents', ['id' => $documentId, 'status' => 'POSTED', 'version' => 3]);
    }

    /**
     * A racer who somehow resends a fresh If-Match (e.g. client retried after
     * re-fetching the already-POSTED document) must still be rejected by the
     * document-state guard (STATE_CONFLICT), not by version alone — proving
     * the guard is defense-in-depth, not solely reliant on optimistic locking.
     */
    public function test_repost_with_current_version_after_already_posted_is_rejected_by_state_guard(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'COMP-RACE-STATE-001');
        $documentId = $doc['document_id'];

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'race-state-winner-001',
        ])->assertOk()->assertJsonPath('data.version', 3);

        $movementAfterFirstPost = DB::table('stock_movements')->count();

        // Different idempotency key => not a replay; must be re-evaluated and rejected on state.
        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'race-state-second-writer-001',
        ])->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');

        $this->assertSame(
            $movementAfterFirstPost,
            DB::table('stock_movements')->count(),
            'Rejected second writer must not create an additional stock_movement.'
        );
    }

    /**
     * Duplicate business-key guard inside mutateBalance() is the last line of
     * defense against double stock effects even if two writers somehow got
     * past the document-level lock. Verified by directly re-invoking the
     * posting path against a document whose stock_movement business_key was
     * already recorded, via a second document racing on the *same* line
     * business key being impossible by construction — so instead we assert
     * the invariant holds by inspecting the persisted business_key uniqueness
     * after a normal post, which the DUPLICATE_STOCK_EFFECT guard depends on.
     */
    public function test_stock_movement_business_key_is_unique_per_document_line_after_post(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'COMP-RACE-BKEY-001');
        $documentId = $doc['document_id'];

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'race-bkey-001',
        ])->assertOk();

        $businessKeys = DB::table('stock_movements')->where('stock_document_id', $documentId)->pluck('business_key');
        $this->assertCount(1, $businessKeys);
        $this->assertSame(
            sha1('RECEIPT|'.$documentId.'|'.DB::table('stock_document_lines')->where('stock_document_id', $documentId)->value('id')),
            $businessKeys->first()
        );

        $this->assertSame(
            1,
            DB::table('stock_movements')->where('business_key', $businessKeys->first())->count(),
            'business_key must remain unique — this is what DUPLICATE_STOCK_EFFECT guards against for racing writers.'
        );
    }
}
