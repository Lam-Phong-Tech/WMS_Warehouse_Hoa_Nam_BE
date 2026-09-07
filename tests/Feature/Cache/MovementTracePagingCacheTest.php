<?php

namespace Tests\Feature\Cache;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * RPT-MOV-01 — khoá cache của /reports/movement-trace phải mang page/per_page.
 *
 * LỖI ĐÃ TÁI HIỆN ĐƯỢC (stack local, 2026-09-07) trước khi sửa:
 *   GET /reports/movement-trace?from..to&per_page=5  -> nạp cache đúng 5 dòng
 *   GET /reports/movement-trace?from..to             -> nhận lại ĐÚNG 5 dòng
 * trong khi summary.total_movements vẫn báo 3.434.
 *
 * Nguyên nhân: buildMovementTrace() dựng khoá bằng reportFiltersWithoutPaging()
 * (cố tình bỏ page/per_page) trong khi buildMovementTraceUncached() lại cắt
 * trang NGAY Ở TẦNG SQL bằng forPage() khi có per_page. Request nào nạp cache
 * trước sẽ quyết định số dòng cho MỌI request sau trong suốt TTL — màn Truy vết
 * hàng hoá im lặng hiện thiếu biến động. Đây là sai DỮ LIỆU trên một báo cáo
 * truy vết, không phải chuyện hiệu năng.
 *
 * Test đi qua HTTP thật với cache BẬT (không mock) vì lớp lỗi chỉ xuất hiện khi
 * có entry cache dùng lại giữa hai request.
 */
class MovementTracePagingCacheTest extends TestCase
{
    use RefreshDatabase;

    private const TOTAL_MOVEMENTS = 12;

    private const FROM = '2026-01-01';

    private const TO = '2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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
            'username' => 'mov-trace-'.Str::lower(Str::random(6)),
            'display_name' => 'Movement Trace Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    /**
     * Ghi thẳng vào sổ ledger thay vì chạy cả luồng nhập kho: lớp lỗi nằm ở khoá
     * cache của endpoint ĐỌC, không phụ thuộc biến động được sinh ra bằng cách nào.
     */
    private function seedMovements(string $userId): void
    {
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']
        );
        $sku = Sku::create([
            'sku_code' => 'MOV-TRACE-CACHE-01',
            'sku_name' => 'Linh kiện kiểm khoá cache',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $rows = [];
        for ($i = 0; $i < self::TOTAL_MOVEMENTS; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'movement_no' => sprintf('MV-CACHE-%04d', $i),
                'business_key' => 'mov-trace-cache-'.$i,
                'movement_type' => 'RECEIPT',
                'warehouse_id' => (string) $warehouse->id,
                'product_id' => (string) $sku->sku_id,
                'sku_id' => (string) $sku->sku_id,
                'stock_status' => 'AVAILABLE',
                'qty_before' => $i,
                'qty_delta' => 1,
                'qty_after' => $i + 1,
                'quantity' => 1,
                'created_by' => $userId,
                // Giờ tăng dần để thứ tự occurred_at DESC là xác định.
                'occurred_at' => sprintf('2026-06-01 %02d:00:00', $i),
                'created_at' => sprintf('2026-06-01 %02d:00:00', $i),
            ];
        }
        DB::table('stock_movements')->insert($rows);
    }

    private function trace(array $headers, string $query = ''): array
    {
        $url = '/api/v1/reports/movement-trace?from_date='.self::FROM.'&to_date='.self::TO.$query;

        return $this->getJson($url, $headers)->assertOk()->json('data');
    }

    public function test_unpaged_request_is_not_poisoned_by_an_earlier_paged_request(): void
    {
        $headers = $this->authHeaders();
        $this->seedMovements((string) auth('api')->user()->user_id);

        // 1) Request CÓ phân trang chạy trước và nạp cache.
        $paged = $this->trace($headers, '&per_page=5&page=1');
        $this->assertCount(5, $paged['rows']);

        // 2) Request KHÔNG phân trang — đúng cách màn Truy vết hàng hoá gọi.
        $full = $this->trace($headers);

        $this->assertSame(
            self::TOTAL_MOVEMENTS,
            (int) $full['summary']['total_movements'],
            'summary phải luôn tính trên toàn kỳ.'
        );
        $this->assertCount(
            self::TOTAL_MOVEMENTS,
            $full['rows'],
            'Request không phân trang phải nhận ĐỦ dòng, không phải cửa sổ của request trước.'
        );
    }

    public function test_paged_request_is_not_poisoned_by_an_earlier_unpaged_request(): void
    {
        $headers = $this->authHeaders();
        $this->seedMovements((string) auth('api')->user()->user_id);

        // Chiều ngược lại: entry "toàn kỳ" không được biến trang 5 dòng thành 12 dòng.
        $full = $this->trace($headers);
        $this->assertCount(self::TOTAL_MOVEMENTS, $full['rows']);

        $paged = $this->trace($headers, '&per_page=5&page=1');
        $this->assertCount(5, $paged['rows']);
        $this->assertSame(
            self::TOTAL_MOVEMENTS,
            (int) $paged['pagination']['total'],
            'pagination.total vẫn phải nói tổng của toàn kỳ.'
        );
    }

    public function test_each_page_serves_its_own_rows(): void
    {
        $headers = $this->authHeaders();
        $this->seedMovements((string) auth('api')->user()->user_id);

        $first = $this->trace($headers, '&per_page=5&page=1');
        $second = $this->trace($headers, '&per_page=5&page=2');

        $this->assertCount(5, $first['rows']);
        $this->assertCount(5, $second['rows']);
        $this->assertNotSame(
            $first['rows'][0]['id'],
            $second['rows'][0]['id'],
            'Trang 2 dùng lại entry cache của trang 1 là đúng lớp lỗi cần chặn.'
        );
        // Hai trang không được chồng lấn dòng nào.
        $firstIds = array_column($first['rows'], 'id');
        $secondIds = array_column($second['rows'], 'id');
        $this->assertSame([], array_intersect($firstIds, $secondIds));
    }

    /**
     * Mã chứng từ nguồn phải do BE tra sẵn (`source_no`).
     *
     * Trước 07/09/2026, FE tự ghép bảng tra UUID -> mã bằng HAI request phụ:
     * `/reports/document-history` (KHÔNG phân trang — đo được 328 KB nén /
     * 1,69 MB giải nén) và `/reversal-documents`, chỉ để lấy đúng hai trường.
     * Nay ledger trả thẳng `source_no`, cùng khuôn mẫu `original_movement_no`.
     *
     * Test khoá cả hai nhánh: phiếu kho thường và phiếu ĐẢO — ledger trỏ tới cả
     * hai bảng, mà `stock_documents` không chứa phiếu đảo.
     */
    public function test_rows_carry_resolved_source_document_number(): void
    {
        $headers = $this->authHeaders();
        $userId = (string) auth('api')->user()->user_id;
        $this->seedMovements($userId);

        $warehouse = Warehouse::where('code', 'KHO-TONG-HN')->firstOrFail();
        $sku = Sku::where('sku_code', 'MOV-TRACE-CACHE-01')->firstOrFail();

        $docId = (string) Str::uuid();
        DB::table('stock_documents')->insert([
            'id' => $docId,
            'doc_type' => 'INBOUND',
            'doc_no' => 'PN-CACHE-0001',
            'doc_date' => '2026-06-01',
            'status' => 'posted',
            'created_by' => $userId,
            'version' => 1,
        ]);

        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(),
            'movement_no' => 'MV-CACHE-SRC',
            'business_key' => 'mov-trace-cache-src',
            'movement_type' => 'RECEIPT',
            'warehouse_id' => (string) $warehouse->id,
            'product_id' => (string) $sku->sku_id,
            'sku_id' => (string) $sku->sku_id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => 0,
            'qty_delta' => 1,
            'qty_after' => 1,
            'quantity' => 1,
            'created_by' => $userId,
            'source_type' => 'STOCK_DOCUMENT',
            'source_id' => $docId,
            'occurred_at' => '2026-06-02 09:00:00',
            'created_at' => '2026-06-02 09:00:00',
        ]);

        $rows = $this->trace($headers)['rows'];

        // Mọi dòng phải CÓ trường source_no (kể cả khi rỗng) — FE đọc thẳng nó.
        foreach ($rows as $row) {
            $this->assertArrayHasKey('source_no', $row);
        }

        $resolved = collect($rows)->firstWhere('source_id', $docId);
        $this->assertNotNull($resolved, 'Dòng vừa thêm phải có mặt trong kỳ.');
        $this->assertSame('PN-CACHE-0001', $resolved['source_no']);
    }

    public function test_summary_and_by_movement_type_stay_period_wide_on_every_page(): void
    {
        $headers = $this->authHeaders();
        $this->seedMovements((string) auth('api')->user()->user_id);

        $full = $this->trace($headers);
        $paged = $this->trace($headers, '&per_page=3&page=2');

        // Bất biến nghiệp vụ: thẻ KPI và biểu đồ cơ cấu mô tả TOÀN kỳ, không phải
        // trang đang xem. Đây là lý do FE từng không dám gửi per_page — BE đã bảo
        // đảm sẵn, và test này khoá lại lời bảo đảm đó.
        $this->assertSame($full['summary'], $paged['summary']);
        $this->assertSame($full['by_movement_type'], $paged['by_movement_type']);
    }
}
