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
 * WWHN-219: `posting_event.operation` ('reverse_stock_document_'.$documentId,
 * 'post_component_issue_'.$documentId, ...) là "<verb>_<uuid 36 ký tự>". Trigger
 * hn_erd_normalize_canonical_posting_events() derive posting_events.posting_action
 * (varchar(50), Data Dictionary bảng 31) từ operation này — với verb dài
 * ("reverse_stock_document_" = 23 ký tự, "post_component_issue_" = 21 ký tự) tổng
 * vượt quá 50 và Postgres ném SQLSTATE 22001 (string data, right truncated). SQLite
 * không enforce varchar length nên bug này CHỈ lộ ra khi chạy thật trên PostgreSQL —
 * test này phải chạy trên pgsql, không có static/sqlite fallback nào thay thế được.
 *
 * Lỗi xảy ra ngay cả khi post tuần tự (không cần 2 connection/race) — proof gốc là
 * scripts/wwhn219-runtime-concurrency.php scenario "concurrent_reversal", nhưng đó là
 * multi-process orchestrator (WWHN219_RUNTIME_CONCURRENCY=1). Test này verify riêng,
 * đơn giản, lái qua đúng HTTP endpoint POST /inbound-documents/{id}/reverse như thật.
 */
class Wwhn219PostingEventOperationLengthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('posting_events.posting_action overflow chỉ tái hiện trên PostgreSQL (varchar length không được SQLite enforce).');
        }
    }

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'postop-len-'.Str::lower(Str::random(6)),
            'display_name' => 'Posting Operation Length Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_sequential_reverse_on_document_with_uuid_id_does_not_truncate_posting_action(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']
        );
        $sku = Sku::create([
            'sku_code' => 'COMP-POSTOP-LEN-01',
            'sku_name' => 'Component posting-op length fixture',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Posting operation length fixture',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 3]],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        // documentId chắc chắn là UUID 36 ký tự — chính là phần bị cắt cụt trong bug.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $documentId);

        $register = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-'.Str::upper(Str::random(8)),
        ], $headers);
        $boxId = (string) $register->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 3,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'postop-len-receipt-'.Str::random(12),
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        $movementId = (string) DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'RECEIPT')
            ->value('id');

        // Đây chính là POST tuần tự (không có process/connection thứ 2 nào) đã FAIL với
        // SQLSTATE[22001] trước khi sửa trigger hn_erd_normalize_canonical_posting_events().
        $reverse = $this->postJson("/api/v1/inbound-documents/{$documentId}/reverse", [
            'reason' => 'WWHN-219 posting_action overflow regression',
            'lines' => [['movement_id' => $movementId, 'quantity' => 3]],
        ], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'postop-len-reverse-'.Str::random(12),
        ]);

        $reverse->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');
        $reversalDocumentId = (string) $reverse->json('data.reversal_document_id');

        $postingAction = DB::table('posting_events')
            ->where('document_id', $reversalDocumentId)
            ->value('posting_action');
        $this->assertNotNull($postingAction, 'Mirror posting_event -> posting_events phải tạo được row cho reversal.');
        // Không hardcode giá trị cụ thể ('REVERSE_RECEIPT' nếu application layer cung
        // cấp tường minh, hoặc 'reverse_stock_document' nếu fallback derive từ operation
        // — cả 2 đều hợp lệ tuỳ code path) — chỉ assert đúng bất biến của bug: không
        // vượt varchar(50) và không còn nhúng nguyên UUID gây truncation gốc.
        $this->assertLessThanOrEqual(50, strlen($postingAction));
        $this->assertStringNotContainsString($documentId, $postingAction, 'posting_action không được nhúng UUID document — đó chính là nguyên nhân gây SQLSTATE 22001.');
    }

    /**
     * Test tầng DB thuần (insert thẳng vào posting_event, không qua HTTP/application
     * code) cho đúng 3 case của bug: (1) operation dài verb+UUID không kèm
     * posting_action tường minh (bug gốc: reverse_stock_document_<uuid>, 59 ký tự),
     * (2) một biến thể khác cùng pattern (post_component_issue_<uuid>, 57 ký tự),
     * (3) posting_action tường minh do application cung cấp phải đi xuyên qua view
     * posting_event -> bảng vật lý posting_events không đổi. Tách khỏi flow HTTP đầy
     * đủ để không phụ thuộc các thay đổi khác đang song song diễn ra ở tầng service.
     */
    public function test_posting_event_insert_never_truncates_posting_action_on_postgres(): void
    {
        $cases = [
            ['operation' => 'reverse_stock_document_'.Str::uuid(), 'posting_action' => null, 'expected' => 'reverse_stock_document'],
            ['operation' => 'post_component_issue_'.Str::uuid(), 'posting_action' => null, 'expected' => 'post_component_issue'],
            ['operation' => 'reverse_stock_document_'.Str::uuid(), 'posting_action' => 'REVERSE_ISSUE', 'expected' => 'REVERSE_ISSUE'],
        ];

        foreach ($cases as $case) {
            $idempotencyKey = 'db-only-'.Str::random(12);
            $row = [
                'posting_event_id' => (string) Str::uuid(),
                'operation' => $case['operation'],
                'idempotency_key' => $idempotencyKey,
                'request_hash' => str_repeat('a', 64),
                'status' => 'COMMITTED',
                'document_id' => (string) Str::uuid(),
                'created_at' => now(),
            ];
            if ($case['posting_action'] !== null) {
                $row['posting_action'] = $case['posting_action'];
            }

            DB::table('posting_event')->insert($row);

            $postingAction = DB::table('posting_events')->where('idempotency_key', $idempotencyKey)->value('posting_action');
            $this->assertNotNull($postingAction, "posting_action phải được set cho operation={$case['operation']}");
            $this->assertLessThanOrEqual(50, strlen($postingAction), "posting_action vượt varchar(50) cho operation={$case['operation']}");
            $this->assertSame($case['expected'], $postingAction);
        }
    }
}
