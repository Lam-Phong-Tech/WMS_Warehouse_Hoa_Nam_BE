<?php

namespace Tests\Feature;

use App\Support\Security\BlindIndex;
use App\Support\SqliteUnaccentFunction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\PublicQuoteRequest;
use Modules\Warehouse\Models\PublicQuoteRequestItem;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\PvRelations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Hợp đồng mặt storefront công khai `/api/v1/public/*` do đội App PV chốt.
 *
 * Bộ test này KHÔNG lặp lại Wwhn252PvContractTest (nhóm `/api/v1/pv/*`). Hai
 * mặt API cố ý khác nhau ở hai điểm mà bộ test kia đang khoá theo chiều ngược
 * lại, nên chính chúng là thứ phải được khoá ở đây:
 *
 *   1. Chỉ trả sản phẩm PUBLISHED; tồn 0 vẫn hiện với nhãn PREORDER.
 *   2. TUYỆT ĐỐI không lộ số tồn — nhóm pv/* thì trả available_qty.
 *
 * Ngoài ra khoá những hành vi mà kiểm thử tay bằng curl không chứng minh nổi:
 * thứ tự ưu tiên khớp chính xác, phân trang keyset khi dữ liệu thay đổi giữa
 * hai trang, và bộ lọc `spec.<code>` (tên tham số có dấu chấm bị PHP bóp méo).
 */
class PublicStorefrontApiTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/public';

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase dựng PDO mới cho mỗi test; UDF hn_unaccent gắn trên
        // PDO cũ không còn tồn tại nên cache đăng ký phải được xoá.
        SqliteUnaccentFunction::forget();

        config()->set('public_api.domains', [
            'POWER_TOOLS' => ['name' => 'Máy và thiết bị động lực', 'categories' => ['PUMP', 'ENGINE']],
            'HAND_TOOLS' => ['name' => 'Dụng cụ cầm tay', 'categories' => []],
            'ACCESSORIES' => ['name' => 'Phụ tùng và phụ kiện', 'categories' => ['SPARE_PART']],
        ]);
        config()->set('public_api.privacy.version', '1.0.0');
        config()->set('public_api.maintenance.enabled', false);
        config()->set('public_api.feature_flags.quote_request', true);
    }

    // ==================================================================
    // Envelope
    // ==================================================================

    /**
     * FE parse MỌI response bằng một hàm duy nhất, nên sáu khoá phải luôn có
     * mặt — kể cả khi giá trị là null. Envelope nội bộ của hệ thống thiếu hẳn
     * error_code/errors khi thành công và thiếu data khi lỗi; đó chính là lý do
     * nhóm này có envelope riêng.
     */
    public function test_success_envelope_has_exactly_the_six_contract_keys(): void
    {
        $body = $this->getJson(self::BASE.'/config')->assertOk()->json();

        $this->assertSame(
            ['success', 'message', 'data', 'meta', 'error_code', 'errors'],
            array_keys($body),
        );
        $this->assertTrue($body['success']);
        $this->assertNull($body['error_code']);
        $this->assertNull($body['errors']);
        $this->assertIsString($body['message']);
        $this->assertArrayHasKey('request_id', $body['meta']);
    }

    public function test_error_envelope_has_the_same_six_keys_with_data_null(): void
    {
        $body = $this->getJson(self::BASE.'/products/khong-ton-tai')->assertNotFound()->json();

        $this->assertSame(
            ['success', 'message', 'data', 'meta', 'error_code', 'errors'],
            array_keys($body),
        );
        $this->assertFalse($body['success']);
        $this->assertNull($body['data']);
        $this->assertSame('PRODUCT_NOT_FOUND', $body['error_code']);
        $this->assertArrayHasKey('request_id', $body['meta']);
    }

    /**
     * 405 và 404-không-khớp-route được ném ra TRONG LÚC khớp route, trước mọi
     * middleware của route group. Đây là lý do EnsurePublicApiEnvelope phải
     * đăng ký toàn cục; test này là thứ giữ nó ở đúng chỗ.
     */
    public function test_framework_level_errors_still_use_the_public_envelope(): void
    {
        foreach ([
            $this->postJson(self::BASE.'/config'),
            $this->getJson(self::BASE.'/duong-dan-khong-ton-tai'),
        ] as $response) {
            $body = $response->json();
            $this->assertSame(
                ['success', 'message', 'data', 'meta', 'error_code', 'errors'],
                array_keys($body),
                'Lỗi do framework sinh ra phải đi qua cùng một envelope.',
            );
            // Object `error` của envelope nội bộ không được lọt ra hợp đồng mới.
            $this->assertArrayNotHasKey('error', $body);
        }
    }

    public function test_request_id_echoes_the_client_supplied_header(): void
    {
        $body = $this->getJson(self::BASE.'/health/version', ['X-Request-ID' => 'trace-abc-123'])
            ->assertOk()
            ->json();

        $this->assertSame('trace-abc-123', $body['meta']['request_id']);
    }

    // ==================================================================
    // Hiển thị: PUBLISHED + IN_STOCK/PREORDER, và không lộ số tồn
    // ==================================================================

    public function test_listing_returns_published_products_and_marks_out_of_stock_as_preorder(): void
    {
        $warehouse = $this->warehouseFixture();

        $visible = $this->publishedSku('PUB-001', 'Máy bơm hiển thị');
        $this->seedAvailableBalance($warehouse->id, $visible->sku_id, 5);

        // Đã publish nhưng HẾT HÀNG vẫn phải hiện để App cho phép đặt trước.
        $outOfStock = $this->publishedSku('PUB-002', 'Máy bơm hết hàng');
        $this->seedAvailableBalance($warehouse->id, $outOfStock->sku_id, 0);

        // Còn hàng nhưng CHƯA publish -> cũng phải biến mất.
        $unpublished = $this->sku('PUB-003', 'Máy bơm chưa publish');
        $this->seedAvailableBalance($warehouse->id, $unpublished->sku_id, 9);

        $items = $this->getJson(self::BASE.'/products')->assertOk()->json('data.items');
        $availability = array_column($items, 'availability', 'primary_code');
        ksort($availability);
        $this->assertSame(['PUB-001' => 'IN_STOCK', 'PUB-002' => 'PREORDER'], $availability);
    }

    /**
     * §Rò rỉ dữ liệu: hợp đồng cấm trả giá, số tồn, nhà cung cấp, ghi chú nội
     * bộ, đường dẫn nguồn và cờ publish thô. Quét trên JSON THÔ chứ không phải
     * trên mảng đã parse, để bắt cả trường hợp khoá nằm sâu trong cấu trúc lồng.
     */
    public function test_public_payload_never_exposes_internal_or_stock_fields(): void
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->publishedSku('LEAK-001', 'Máy kiểm tra rò rỉ');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 42);

        $responses = [
            $this->getJson(self::BASE.'/products')->assertOk(),
            $this->getJson(self::BASE.'/products/leak-001')->assertOk(),
            $this->getJson(self::BASE.'/home')->assertOk(),
            $this->getJson(self::BASE.'/facets')->assertOk(),
        ];

        $forbidden = [
            'available_qty', 'on_hand_qty', 'reserved_qty', 'quantity', 'stock_status',
            'price', 'cost', 'supplier', 'internal_note', 'source_path',
            'published_to_app', 'is_published', 'min_stock', 'max_stock',
            'warehouse_id', 'item_id', 'container_id', 'serial_number', 'movement',
        ];

        foreach ($responses as $response) {
            $raw = $response->getContent();

            foreach ($forbidden as $key) {
                $this->assertStringNotContainsString(
                    '"'.$key.'"',
                    $raw,
                    "Mặt công khai đã lộ trường nội bộ [{$key}].",
                );
            }

            // Số tồn cũng không được rò qua một tên khoá KHÁC. Phải soi theo cấu
            // trúc chứ không phải tìm chuỗi con: "42" xuất hiện ngẫu nhiên trong
            // UUID và mốc thời gian, nên so chuỗi thô sẽ đỏ oan.
            $this->assertNoScalarEquals($response->json(), 42, 'Số lượng tồn kho lọt vào response công khai.');
        }
    }

    /**
     * Duyệt đệ quy mọi giá trị vô hướng của payload và khẳng định không giá trị
     * nào bằng $needle (so cả dạng số lẫn dạng chuỗi).
     *
     * @param  mixed  $payload
     */
    private function assertNoScalarEquals($payload, int $needle, string $message): void
    {
        if (is_array($payload)) {
            foreach ($payload as $value) {
                $this->assertNoScalarEquals($value, $needle, $message);
            }

            return;
        }

        if (is_int($payload) || is_string($payload)) {
            $this->assertNotSame((string) $needle, (string) $payload, $message);
        }
    }

    public function test_detail_masks_unpublished_product_as_404_not_403(): void
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->sku('HIDDEN-001', 'Sản phẩm chưa publish');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 3);

        // 403 sẽ xác nhận cho người ẩn danh rằng mã hàng này CÓ THẬT trong hệ thống.
        $this->getJson(self::BASE.'/products/hidden-001')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'PRODUCT_NOT_FOUND');
    }

    // ==================================================================
    // Tìm kiếm
    // ==================================================================

    /**
     * Hợp đồng: "trim, không phân biệt hoa/thường, không dấu".
     */
    #[DataProvider('searchVariants')]
    public function test_search_is_trimmed_case_and_accent_insensitive(string $query): void
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->publishedSku('SEARCH-001', 'Máy bơm nước ly tâm');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 4);

        $codes = collect($this->getJson(self::BASE.'/products?q='.rawurlencode($query))->assertOk()->json('data.items'))
            ->pluck('primary_code')->all();

        $this->assertSame(['SEARCH-001'], $codes, "Từ khoá [{$query}] phải tìm ra sản phẩm.");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function searchVariants(): array
    {
        return [
            'đúng nguyên văn' => ['Máy bơm nước'],
            'không dấu' => ['may bom nuoc'],
            'chữ hoa không dấu' => ['MAY BOM NUOC'],
            'thừa khoảng trắng hai đầu' => ['   may bom   '],
            'chữ hoa có dấu' => ['MÁY BƠM'],
        ];
    }

    /**
     * Hợp đồng: "ưu tiên khớp chính xác model/primary_code trước tên gần đúng".
     *
     * Fixture cố ý dựng để thứ tự chữ cái NGƯỢC với thứ tự đúng: sản phẩm khớp
     * CHÍNH XÁC mã lại có tên bắt đầu bằng "Zzz", còn sản phẩm chỉ khớp MỜ trong
     * tên thì bắt đầu bằng "Aaa". Chạy với sort=name_asc, nên nếu hạng khớp
     * không được áp dụng thì "Aaa" sẽ đứng trước và test đỏ. Test chỉ có giá trị
     * chứng minh nhờ đúng chỗ ngược nhau đó.
     */
    public function test_exact_code_match_outranks_fuzzy_name_match(): void
    {
        $warehouse = $this->warehouseFixture();

        $exact = $this->publishedSku('MAYBOM', 'Zzz thiet bi khop ma chinh xac');
        $this->seedAvailableBalance($warehouse->id, $exact->sku_id, 1);

        // Tên chứa "maybom" như một chuỗi con -> khớp mờ, hạng thấp hơn.
        $fuzzy = $this->publishedSku('AAA-001', 'Aaa maybom dan dung');
        $this->seedAvailableBalance($warehouse->id, $fuzzy->sku_id, 1);

        $codes = collect($this->getJson(self::BASE.'/products?q=maybom&sort=name_asc')->assertOk()->json('data.items'))
            ->pluck('primary_code')->all();

        $this->assertSame(
            ['MAYBOM', 'AAA-001'],
            $codes,
            'Khớp chính xác primary_code phải đứng trước khớp mờ theo tên, kể cả khi sort=name_asc nói ngược lại.',
        );
    }

    // ==================================================================
    // Sắp xếp + phân trang cursor
    // ==================================================================

    public function test_cursor_pagination_walks_every_product_exactly_once(): void
    {
        $warehouse = $this->warehouseFixture();
        for ($i = 1; $i <= 7; $i++) {
            $sku = $this->publishedSku(sprintf('PAGE-%03d', $i), 'Sản phẩm phân trang '.$i);
            $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        }

        $seen = [];
        $cursor = null;
        $guard = 0;

        do {
            $url = self::BASE.'/products?limit=3'.($cursor !== null ? '&cursor='.urlencode($cursor) : '');
            $page = $this->getJson($url)->assertOk()->json('data');

            foreach ($page['items'] as $item) {
                $seen[] = $item['primary_code'];
            }

            $cursor = $page['page_info']['next_cursor'];
            $this->assertLessThan(10, ++$guard, 'Phân trang cursor không kết thúc — nghi ngờ vòng lặp vô hạn.');
        } while ($cursor !== null);

        sort($seen);
        $this->assertCount(7, $seen);
        $this->assertSame($seen, array_unique($seen), 'Cursor trả trùng bản ghi giữa các trang.');
    }

    /**
     * Đây là lý do dùng keyset thay vì OFFSET: sản phẩm mới chèn vào GIỮA hai
     * lần gọi không được làm bản ghi khác bị nhảy cóc. Với OFFSET, một dòng chèn
     * lên đầu sẽ đẩy toàn bộ trang sau xuống một nấc và người dùng mất đúng một
     * sản phẩm — im lặng, không ai phát hiện.
     */
    public function test_cursor_pagination_does_not_skip_rows_when_data_shifts(): void
    {
        $warehouse = $this->warehouseFixture();
        foreach (['B', 'C', 'D', 'E'] as $letter) {
            $sku = $this->publishedSku('SHIFT-'.$letter, 'San pham '.$letter);
            $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        }

        $first = $this->getJson(self::BASE.'/products?limit=2&sort=name_asc')->assertOk()->json('data');
        $this->assertSame(['SHIFT-B', 'SHIFT-C'], collect($first['items'])->pluck('primary_code')->all());

        // Chèn một sản phẩm xếp TRƯỚC tất cả -> với OFFSET, trang 2 sẽ bỏ sót SHIFT-D.
        $inserted = $this->publishedSku('SHIFT-A', 'San pham A');
        $this->seedAvailableBalance($warehouse->id, $inserted->sku_id, 1);

        $second = $this->getJson(self::BASE.'/products?limit=2&sort=name_asc&cursor='.urlencode($first['page_info']['next_cursor']))
            ->assertOk()->json('data');

        $this->assertSame(
            ['SHIFT-D', 'SHIFT-E'],
            collect($second['items'])->pluck('primary_code')->all(),
            'Keyset phải tiếp tục đúng chỗ dừng dù dữ liệu đã đổi.',
        );
    }

    public function test_cursor_is_opaque_and_rejects_tampering_or_context_change(): void
    {
        $warehouse = $this->warehouseFixture();
        foreach (['T1', 'T2', 'T3'] as $code) {
            $sku = $this->publishedSku($code, 'San pham '.$code);
            $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        }

        $cursor = $this->getJson(self::BASE.'/products?limit=1')->assertOk()->json('data.page_info.next_cursor');
        $this->assertIsString($cursor);

        // Sửa một byte -> chữ ký hỏng.
        $this->getJson(self::BASE.'/products?limit=1&cursor='.urlencode($cursor).'X')
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_CURSOR');

        // Cùng cursor nhưng đổi sort: trang tiếp theo của một thứ tự khác là vô nghĩa.
        $this->getJson(self::BASE.'/products?limit=1&sort=name_asc&cursor='.urlencode($cursor))
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_CURSOR');

        // Cùng cursor nhưng đổi bộ lọc.
        $this->getJson(self::BASE.'/products?limit=1&q=abc&cursor='.urlencode($cursor))
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_CURSOR');
    }

    public function test_invalid_sort_and_limit_are_rejected_with_explicit_codes(): void
    {
        $this->getJson(self::BASE.'/products?sort=gia_tang_dan')
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_SORT');

        // Vượt trần phải là LỖI: cắt ngầm sẽ khiến client tưởng đã nhận đủ trang.
        $this->getJson(self::BASE.'/products?limit=999')
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_QUERY');

        $this->getJson(self::BASE.'/products?limit=0')
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_QUERY');
    }

    // ==================================================================
    // Tra theo id (màn "đã lưu" của App PV)
    // ==================================================================

    /**
     * App chỉ lưu product_id; mở lại màn "đã lưu" thì hỏi một lượt cho toàn bộ
     * id. Hai thứ app dựa vào: THỨ TỰ trả về đúng thứ tự gửi, và `missing_ids`
     * để phân biệt "đã hỏi và không còn" với "chưa tải xong".
     */
    public function test_ids_lookup_giu_dung_thu_tu_gui_len_va_bao_missing_ids(): void
    {
        $warehouse = $this->warehouseFixture();

        $a = $this->publishedSku('IDS-001', 'Máy khoan A');
        $b = $this->publishedSku('IDS-002', 'Máy khoan B');
        $c = $this->publishedSku('IDS-003', 'Máy khoan C');
        foreach ([$a, $b, $c] as $sku) {
            $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 5);
        }

        $goneId = '00000000-0000-4000-8000-000000000000';

        // Gửi ngược thứ tự để chứng minh không rơi về sort của catalog.
        $query = collect([$c->sku_id, $goneId, $a->sku_id, $b->sku_id])
            ->map(fn ($id): string => 'ids[]='.$id)->implode('&');

        $data = $this->getJson(self::BASE.'/products?'.$query)->assertOk()->json('data');

        $this->assertSame(
            [(string) $c->sku_id, (string) $a->sku_id, (string) $b->sku_id],
            array_column($data['items'], 'id')
        );
        $this->assertSame([$goneId], $data['missing_ids']);

        // Chế độ này không phân trang nên không có page_info.
        $this->assertArrayNotHasKey('page_info', $data);
    }

    /** Id trùng chỉ trả một lần, giữ vị trí xuất hiện đầu tiên. */
    public function test_ids_lookup_loai_trung_nhung_giu_vi_tri_dau_tien(): void
    {
        $warehouse = $this->warehouseFixture();
        $a = $this->publishedSku('IDS-DUP-1', 'Máy A');
        $b = $this->publishedSku('IDS-DUP-2', 'Máy B');
        $this->seedAvailableBalance($warehouse->id, $a->sku_id, 3);
        $this->seedAvailableBalance($warehouse->id, $b->sku_id, 3);

        $data = $this->getJson(self::BASE."/products?ids[]={$a->sku_id}&ids[]={$b->sku_id}&ids[]={$a->sku_id}")
            ->assertOk()->json('data');

        $this->assertSame([(string) $a->sku_id, (string) $b->sku_id], array_column($data['items'], 'id'));
    }

    /**
     * Điều kiện PUBLISH giữ nguyên khi hỏi đích danh: chưa publish vẫn nằm ở
     * missing_ids. Nhưng HẾT TỒN thì KHÁC — vẫn trả về, mang PREORDER.
     *
     * Màn "đã lưu" vẫn cần phân biệt rõ sản phẩm bị bỏ publish với hàng hết tồn:
     * sản phẩm published hết tồn là PREORDER, còn chưa publish mới thuộc missing_ids.
     */
    public function test_ids_lookup_giu_hang_het_ton_voi_nhan_preorder_nhung_van_an_hang_chua_publish(): void
    {
        $warehouse = $this->warehouseFixture();

        $visible = $this->publishedSku('IDS-VIS', 'Máy hiển thị');
        $this->seedAvailableBalance($warehouse->id, $visible->sku_id, 4);

        $outOfStock = $this->publishedSku('IDS-OOS', 'Máy hết hàng');
        $this->seedAvailableBalance($warehouse->id, $outOfStock->sku_id, 0);

        $unpublished = $this->sku('IDS-UNPUB', 'Máy chưa publish');
        $this->seedAvailableBalance($warehouse->id, $unpublished->sku_id, 9);

        $data = $this->getJson(self::BASE
            ."/products?ids[]={$visible->sku_id}&ids[]={$outOfStock->sku_id}&ids[]={$unpublished->sku_id}")
            ->assertOk()->json('data');

        $this->assertSame(
            [(string) $visible->sku_id, (string) $outOfStock->sku_id],
            array_column($data['items'], 'id')
        );
        $this->assertSame(['IN_STOCK', 'PREORDER'], array_column($data['items'], 'availability'));

        // Chưa publish là "không còn hiển thị công khai" -> vẫn missing.
        $this->assertSame([(string) $unpublished->sku_id], $data['missing_ids']);
    }

    /** Đường duyệt công khai giữ hàng hết tồn với nhãn PREORDER. */
    public function test_availability_o_duong_duyet_phan_biet_in_stock_va_preorder(): void
    {
        $warehouse = $this->warehouseFixture();
        $visible = $this->publishedSku('AVAIL-001', 'Máy còn hàng');
        $this->seedAvailableBalance($warehouse->id, $visible->sku_id, 6);

        $preorder = $this->publishedSku('AVAIL-002', 'Máy hết hàng');
        $this->seedAvailableBalance($warehouse->id, $preorder->sku_id, 0);

        $items = $this->getJson(self::BASE.'/products')->assertOk()->json('data.items');

        $this->assertSame(['AVAIL-001', 'AVAIL-002'], array_column($items, 'primary_code'));
        $this->assertSame(['IN_STOCK', 'PREORDER'], array_column($items, 'availability'));
    }

    /**
     * Trộn ids với bộ lọc/sắp xếp/phân trang phải 400, KHÔNG bỏ qua im lặng.
     * Trước bản vá, `ids[]` bị bỏ qua hoàn toàn và endpoint trả nguyên trang đầu
     * 20 sản phẩm — nhìn như đã chạy nên hỏng rất khó thấy.
     */
    public function test_ids_lookup_tu_choi_khi_tron_voi_tham_so_khac(): void
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->publishedSku('IDS-MIX', 'Máy trộn tham số');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);

        foreach (['q=khoan', 'sort=name_asc', 'limit=5', 'cursor=abc', 'domain=POWER_TOOLS', 'category=X', 'feature=A'] as $extra) {
            $this->getJson(self::BASE."/products?ids[]={$sku->sku_id}&{$extra}")
                ->assertStatus(400)
                ->assertJsonPath('error_code', 'INVALID_QUERY');
        }
    }

    /** UUID hỏng là lỗi client, không được gộp vào missing_ids (app sẽ xoá oan). */
    public function test_ids_lookup_tu_choi_uuid_sai_dinh_dang_va_vuot_tran(): void
    {
        $this->getJson(self::BASE.'/products?ids[]=khong-phai-uuid')
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_QUERY');

        $this->getJson(self::BASE.'/products?ids[]=')
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_QUERY');

        $tooMany = collect(range(1, 51))
            ->map(fn (int $n): string => 'ids[]='.sprintf('%08d-0000-4000-8000-000000000000', $n))
            ->implode('&');
        $this->getJson(self::BASE.'/products?'.$tooMany)
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_QUERY');
    }

    // ==================================================================
    // Bộ lọc
    // ==================================================================

    public function test_domain_filter_maps_to_configured_categories(): void
    {
        $warehouse = $this->warehouseFixture();
        $pumpCategory = $this->category('PUMP', 'Máy bơm nước');
        $partCategory = $this->category('SPARE_PART', 'Phụ tùng');

        $pump = $this->publishedSku('DOM-PUMP', 'May bom', $pumpCategory);
        $this->seedAvailableBalance($warehouse->id, $pump->sku_id, 1);

        $part = $this->publishedSku('DOM-PART', 'Phu tung', $partCategory);
        $this->seedAvailableBalance($warehouse->id, $part->sku_id, 1);

        $power = collect($this->getJson(self::BASE.'/products?domain=POWER_TOOLS')->assertOk()->json('data.items'))
            ->pluck('primary_code')->all();
        $this->assertSame(['DOM-PUMP'], $power);

        $accessories = collect($this->getJson(self::BASE.'/products?domain=ACCESSORIES')->assertOk()->json('data.items'))
            ->pluck('primary_code')->all();
        $this->assertSame(['DOM-PART'], $accessories);

        // Domain hợp lệ nhưng chưa ánh xạ category nào phải trả RỖNG, không được
        // âm thầm bỏ qua bộ lọc rồi trả về toàn bộ catalog.
        $this->getJson(self::BASE.'/products?domain=HAND_TOOLS')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->getJson(self::BASE.'/products?domain=KHONG_CO')
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_DOMAIN');
    }

    /**
     * `spec.<code>` là tham số dễ vỡ nhất của hợp đồng: PHP đổi dấu chấm trong
     * TÊN tham số thành gạch dưới khi dựng $_GET, nên nếu đọc qua
     * $request->query() thì bộ lọc này im lặng không có tác dụng — response vẫn
     * 200 và vẫn trả kết quả, chỉ là sai. Test khoá đường parse raw query string.
     */
    public function test_spec_filter_with_dotted_parameter_name_actually_filters(): void
    {
        $warehouse = $this->warehouseFixture();

        $strong = $this->publishedSku('SPEC-750', 'May cong suat lon');
        $this->setSpecification($strong->sku_id, ['power' => '750W']);
        $this->seedAvailableBalance($warehouse->id, $strong->sku_id, 1);

        $weak = $this->publishedSku('SPEC-250', 'May cong suat nho');
        $this->setSpecification($weak->sku_id, ['power' => '250W']);
        $this->seedAvailableBalance($warehouse->id, $weak->sku_id, 1);

        $codes = collect($this->getJson(self::BASE.'/products?spec.power=750W')->assertOk()->json('data.items'))
            ->pluck('primary_code')->all();

        $this->assertSame(['SPEC-750'], $codes, 'Bộ lọc spec.power không có tác dụng — nghi ngờ tên tham số bị PHP bóp méo.');

        // Khoá spec ngoài allowlist bị từ chối TƯỜNG MINH, không bỏ qua im lặng.
        $this->getJson(self::BASE.'/products?spec.khoa_la=1')
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_QUERY');
    }

    /**
     * `feature` lặp nhiều lần: PHP chỉ giữ giá trị CUỐI nếu đọc qua $_GET, nên
     * giá trị đầu bị mất và kết quả lọc sai mà không có lỗi nào được báo.
     */
    public function test_repeated_feature_parameter_keeps_every_value(): void
    {
        $warehouse = $this->warehouseFixture();
        $promo = $this->tag('PROMOTION', 'Khuyến mãi');
        $saving = $this->tag('ENERGY_SAVING', 'Tiết kiệm nhiên liệu');

        $a = $this->publishedSku('FEAT-A', 'San pham A');
        $this->seedAvailableBalance($warehouse->id, $a->sku_id, 1);
        $this->mapTag($this->profileIdOf($a->sku_id), $promo);

        $b = $this->publishedSku('FEAT-B', 'San pham B');
        $this->seedAvailableBalance($warehouse->id, $b->sku_id, 1);
        $this->mapTag($this->profileIdOf($b->sku_id), $saving);

        $c = $this->publishedSku('FEAT-C', 'San pham C');
        $this->seedAvailableBalance($warehouse->id, $c->sku_id, 1);

        $codes = collect(
            $this->getJson(self::BASE.'/products?feature=PROMOTION&feature=ENERGY_SAVING')->assertOk()->json('data.items')
        )->pluck('primary_code')->all();
        sort($codes);

        $this->assertSame(
            ['FEAT-A', 'FEAT-B'],
            $codes,
            'Giá trị feature đầu tiên bị mất — tham số lặp phải được gom đủ.',
        );
    }

    // ==================================================================
    // config / home / categories / facets / related
    // ==================================================================

    public function test_config_and_home_are_independent_and_both_serve_app_bootstrap(): void
    {
        $warehouse = $this->warehouseFixture();
        $category = $this->category('PUMP', 'Máy bơm nước');
        $sku = $this->publishedSku('HOME-001', 'May bom trang chu', $category);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);

        $config = $this->getJson(self::BASE.'/config')->assertOk()->json('data');
        $this->assertArrayHasKey('hotline', $config['contact']);
        $this->assertArrayHasKey('version', $config['privacy']);
        $this->assertFalse($config['maintenance']['enabled']);
        $this->assertIsBool($config['feature_flags']['quote_request']);

        $home = $this->getJson(self::BASE.'/home')->assertOk()->json('data');
        $this->assertCount(3, $home['domains'], 'Hợp đồng chốt đúng ba domain.');

        // "Các section Home CÓ DỮ LIỆU": section rỗng bị loại ở BE, không để FE
        // render một hàng trống.
        foreach ($home['sections'] as $section) {
            $this->assertNotEmpty($section['items'], "Section [{$section['code']}] rỗng nhưng vẫn được trả về.");
        }
    }

    public function test_categories_and_facets_count_only_visible_products(): void
    {
        $warehouse = $this->warehouseFixture();
        $category = $this->category('PUMP', 'Máy bơm nước');

        $visible = $this->publishedSku('CAT-001', 'May bom con hang', $category);
        $this->seedAvailableBalance($warehouse->id, $visible->sku_id, 3);

        $preorder = $this->publishedSku('CAT-002', 'May bom het hang', $category);
        $this->seedAvailableBalance($warehouse->id, $preorder->sku_id, 0);

        $categories = collect($this->getJson(self::BASE.'/categories')->assertOk()->json('data.items'));
        $pump = $categories->firstWhere('code', 'PUMP');

        $this->assertNotNull($pump);
        $this->assertSame(2, $pump['product_count'], 'Số đếm phải gồm sản phẩm PREORDER đã publish.');
        $this->assertSame('POWER_TOOLS', $pump['domain']);

        $facets = $this->getJson(self::BASE.'/facets')->assertOk()->json('data');
        $this->assertSame(2, collect($facets['category'])->firstWhere('code', 'PUMP')['count']);
    }

    public function test_related_excludes_the_product_itself(): void
    {
        $warehouse = $this->warehouseFixture();
        $category = $this->category('PUMP', 'Máy bơm nước');

        foreach (['REL-001', 'REL-002', 'REL-003'] as $code) {
            $sku = $this->publishedSku($code, 'May bom '.$code, $category);
            $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        }

        $codes = collect($this->getJson(self::BASE.'/products/rel-001/related')->assertOk()->json('data.items'))
            ->pluck('primary_code')->all();

        $this->assertNotContains('REL-001', $codes);
        sort($codes);
        $this->assertSame(['REL-002', 'REL-003'], $codes);
    }

    public function test_related_of_unknown_slug_is_404(): void
    {
        $this->getJson(self::BASE.'/products/khong-co/related')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'PRODUCT_NOT_FOUND');
    }

    public function test_health_version_reports_maintenance_without_leaking_build_details(): void
    {
        $body = $this->getJson(self::BASE.'/health/version')->assertOk()->json('data');
        $this->assertSame('OK', $body['status']);
        $this->assertFalse($body['maintenance']['enabled']);

        // Endpoint ẩn danh: commit SHA / version framework là thông tin trinh
        // sát miễn phí cho người tấn công, không được xuất hiện.
        foreach (['commit', 'sha', 'branch', 'php_version', 'laravel'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $body);
        }

        config()->set('public_api.maintenance.enabled', true);
        $this->getJson(self::BASE.'/health/version')
            ->assertOk()
            ->assertJsonPath('data.status', 'MAINTENANCE');
    }

    // ==================================================================
    // POST /quote-requests
    // ==================================================================

    public function test_quote_request_is_created_then_replayed_idempotently(): void
    {
        $sku = $this->visibleSku('QR-001', 'May bom bao gia');
        $payload = $this->quotePayload($sku->sku_id);
        $headers = ['Idempotency-Key' => 'quote-key-0123456789abcdef'];

        $first = $this->postJson(self::BASE.'/quote-requests', $payload, $headers)->assertStatus(201);

        // Hợp đồng: response thành công CHỈ có request_id và status.
        $this->assertSame(['request_id', 'status'], array_keys($first->json('data')));
        $this->assertSame('RECEIVED', $first->json('data.status'));

        // Gửi lại cùng khoá + cùng nội dung: trả lại đúng bản ghi cũ, không tạo mới.
        $replay = $this->postJson(self::BASE.'/quote-requests', $payload, $headers)->assertOk();
        $this->assertSame($first->json('data.request_id'), $replay->json('data.request_id'));
        $this->assertSame(1, PublicQuoteRequest::query()->count(), 'Retry đã tạo thêm bản ghi thứ hai.');
    }

    public function test_same_key_with_different_payload_is_a_conflict(): void
    {
        $sku = $this->visibleSku('QR-002', 'May bom xung dot');
        $headers = ['Idempotency-Key' => 'quote-key-conflict-000001'];

        $this->postJson(self::BASE.'/quote-requests', $this->quotePayload($sku->sku_id), $headers)->assertStatus(201);

        // Trả lại kết quả cũ ở đây sẽ giấu mất một lỗi thật của client.
        $this->postJson(
            self::BASE.'/quote-requests',
            ['full_name' => 'Người khác hẳn'] + $this->quotePayload($sku->sku_id),
            $headers,
        )->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame(1, PublicQuoteRequest::query()->count());
    }

    public function test_quote_request_rejects_missing_or_malformed_idempotency_key(): void
    {
        $sku = $this->visibleSku('QR-003', 'May bom thieu khoa');
        $payload = $this->quotePayload($sku->sku_id);

        $this->postJson(self::BASE.'/quote-requests', $payload)
            ->assertStatus(428)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REQUIRED');

        // Hợp đồng chốt 16-128 ký tự.
        $this->postJson(self::BASE.'/quote-requests', $payload, ['Idempotency-Key' => str_repeat('a', 15)])
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_INVALID');

        $this->postJson(self::BASE.'/quote-requests', $payload, ['Idempotency-Key' => str_repeat('a', 129)])
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_INVALID');

        $this->assertSame(0, PublicQuoteRequest::query()->count());
    }

    public function test_quote_request_enforces_consent_and_current_privacy_version(): void
    {
        $sku = $this->visibleSku('QR-004', 'May bom dong y');

        // Đồng ý chính sách là điều kiện PHÁP LÝ để lưu dữ liệu cá nhân, nên nó
        // có mã lỗi riêng để FE trỏ đúng vào ô checkbox.
        $this->postJson(
            self::BASE.'/quote-requests',
            ['consent' => false] + $this->quotePayload($sku->sku_id),
            ['Idempotency-Key' => 'quote-key-consent-000001'],
        )->assertStatus(422)->assertJsonPath('error_code', 'CONSENT_REQUIRED');

        // Đồng ý với một bản chính sách CŨ không phải là đồng ý với bản hiện hành.
        $this->postJson(
            self::BASE.'/quote-requests',
            ['privacy_version' => '0.9.0'] + $this->quotePayload($sku->sku_id),
            ['Idempotency-Key' => 'quote-key-privacy-000001'],
        )->assertStatus(422)->assertJsonPath('error_code', 'PRIVACY_VERSION_MISMATCH');

        $this->assertSame(0, PublicQuoteRequest::query()->count());
    }

    public function test_quote_request_validates_phone_and_name(): void
    {
        $sku = $this->visibleSku('QR-005', 'May bom validate');

        $response = $this->postJson(
            self::BASE.'/quote-requests',
            ['phone' => '123', 'full_name' => 'A'] + $this->quotePayload($sku->sku_id),
            ['Idempotency-Key' => 'quote-key-validate-00001'],
        )->assertStatus(422);

        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('phone', $response->json('errors'));
        $this->assertArrayHasKey('full_name', $response->json('errors'));
    }

    public function test_quote_request_normalizes_vietnamese_phone_numbers(): void
    {
        $sku = $this->visibleSku('QR-006', 'May bom sdt');

        $this->postJson(
            self::BASE.'/quote-requests',
            ['phone' => '+84 912 345 678'] + $this->quotePayload($sku->sku_id),
            ['Idempotency-Key' => 'quote-key-phone-0000001'],
        )->assertStatus(201);

        // WWHN-SEC-06: cột `phone_normalized` đã bị xoá — nó CHÍNH LÀ số điện
        // thoại, chỉ bỏ khoảng trắng, nên giữ lại sẽ vô hiệu hoá việc mã hoá
        // `phone`. Tính chất cần kiểm vẫn y nguyên và nay mạnh hơn: mọi cách gõ
        // đều quy về CÙNG MỘT vân tay, và ứng dụng vẫn đọc lại đúng số gốc.
        $this->assertSame('+84 912 345 678', PublicQuoteRequest::query()->firstOrFail()->phone);
        $this->assertSame(
            BlindIndex::phone('0912345678'),
            DB::table('public_quote_requests')->value('phone_lookup'),
            'Gõ +84/khoảng trắng phải ra cùng vân tay với 0912345678, nếu không người dùng không tìm lại được chính hồ sơ vừa gửi.',
        );
    }

    public function test_quote_request_rejects_product_that_is_not_publicly_visible(): void
    {
        $warehouse = $this->warehouseFixture();
        $hidden = $this->sku('QR-HIDDEN', 'San pham chua publish');
        $this->seedAvailableBalance($warehouse->id, $hidden->sku_id, 5);

        $this->postJson(
            self::BASE.'/quote-requests',
            $this->quotePayload($hidden->sku_id),
            ['Idempotency-Key' => 'quote-key-hidden-000001'],
        )->assertNotFound()->assertJsonPath('error_code', 'PRODUCT_NOT_FOUND');

        $this->assertSame(0, PublicQuoteRequest::query()->count());
    }

    public function test_quote_request_is_blocked_during_maintenance(): void
    {
        $sku = $this->visibleSku('QR-007', 'May bom bao tri');
        config()->set('public_api.maintenance.enabled', true);

        // Nhận yêu cầu lúc không ai xử lý là hứa với người dùng một điều không có thật.
        $this->postJson(
            self::BASE.'/quote-requests',
            $this->quotePayload($sku->sku_id),
            ['Idempotency-Key' => 'quote-key-maint-0000001'],
        )->assertStatus(503)->assertJsonPath('error_code', 'SERVICE_UNAVAILABLE');

        // Nhưng các endpoint ĐỌC vẫn phải sống để FE hiện được màn hình bảo trì.
        $this->getJson(self::BASE.'/config')->assertOk();
        $this->getJson(self::BASE.'/products')->assertOk();
    }

    /**
     * Hợp đồng cấm móc CRM/ERP/notification/queue/outbox vào luồng này, và cấm
     * mọi khái niệm giá/tồn/cart/order. Cách kiểm chứng cụ thể nhất: gửi yêu cầu
     * rồi chứng minh không job nào được đẩy vào hàng đợi và ledger tồn kho
     * không đổi.
     */
    public function test_quote_request_has_no_side_effects_beyond_its_own_row(): void
    {
        Queue::fake();
        Event::fake();

        $sku = $this->visibleSku('QR-008', 'May bom khong tac dung phu');
        $ledgerBefore = DB::table('inventory_balances')->orderBy('id')->get()->toJson();

        $this->postJson(
            self::BASE.'/quote-requests',
            $this->quotePayload($sku->sku_id),
            ['Idempotency-Key' => 'quote-key-sideeffect-01'],
        )->assertStatus(201);

        Queue::assertNothingPushed();
        $this->assertSame(
            $ledgerBefore,
            DB::table('inventory_balances')->orderBy('id')->get()->toJson(),
            'Gửi yêu cầu tư vấn đã làm đổi ledger tồn kho.',
        );
    }

    // ==================================================================
    // POST /quote-requests — nhiều sản phẩm (`items[]`)
    // ==================================================================

    /**
     * Điều khoản trung tâm của hợp đồng nhiều sản phẩm: MỘT yêu cầu, N dòng,
     * đúng thứ tự khách chọn, kèm ảnh chụp mã/tên để sáu tháng sau còn đọc lại
     * được. Khoá chặt ở đây vì cả ba đường vòng phía FE (gửi N request, lấy
     * sản phẩm đầu tiên, nhét vào `note`) đều "chạy được" khi nhìn từ màn hình
     * — chỉ dữ liệu là sai.
     */
    public function test_quote_request_records_every_selected_product_as_its_own_line(): void
    {
        $first = $this->visibleSku('QR-M01', 'May bom dong 1');
        $second = $this->visibleSku('QR-M02', 'May bom dong 2');
        $third = $this->visibleSku('QR-M03', 'May bom dong 3');

        $response = $this->postJson(
            self::BASE.'/quote-requests',
            $this->quoteItemsPayload([
                ['product_id' => $first->sku_id, 'quantity' => 2],
                ['product_id' => $second->sku_id],
                ['product_id' => $third->sku_id, 'quantity' => 10],
            ]),
            ['Idempotency-Key' => 'quote-key-multi-00000001'],
        )->assertStatus(201);

        // Hợp đồng response KHÔNG đổi theo: vẫn đúng hai khoá.
        $this->assertSame(['request_id', 'status'], array_keys($response->json('data')));

        $request = PublicQuoteRequest::query()->sole();
        $this->assertSame(3, $request->item_count);
        // Bảng cha giữ dòng 1 làm bản sao tương thích — KHÔNG phải "sản phẩm
        // duy nhất của yêu cầu".
        $this->assertSame($first->sku_id, $request->product_id);

        $items = PublicQuoteRequestItem::query()
            ->where('request_id', $request->id)
            ->orderBy('line_no')
            ->get();

        $this->assertSame([1, 2, 3], $items->pluck('line_no')->all());
        $this->assertSame(
            [$first->sku_id, $second->sku_id, $third->sku_id],
            $items->pluck('product_id')->all(),
            'Thứ tự khách chọn không được sắp lại.',
        );
        // null KHÁC 1: dòng 2 khách không nêu số lượng.
        $this->assertSame([2, null, 10], $items->pluck('quantity')->all());
        $this->assertSame(['QR-M01', 'QR-M02', 'QR-M03'], $items->pluck('sku_code_snapshot')->all());
        $this->assertSame('May bom dong 2', $items[1]->product_name_snapshot);
    }

    public function test_multi_item_request_replays_on_retry_and_conflicts_when_the_selection_changes(): void
    {
        $first = $this->visibleSku('QR-M04', 'May bom retry 1');
        $second = $this->visibleSku('QR-M05', 'May bom retry 2');

        $headers = ['Idempotency-Key' => 'quote-key-multi-00000002'];
        $payload = $this->quoteItemsPayload([
            ['product_id' => $first->sku_id],
            ['product_id' => $second->sku_id],
        ]);

        $created = $this->postJson(self::BASE.'/quote-requests', $payload, $headers)->assertStatus(201);

        $this->postJson(self::BASE.'/quote-requests', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.request_id', $created->json('data.request_id'));

        // Bỏ bớt một sản phẩm nhưng giữ nguyên khoá: nội dung đã khác, trả lại
        // kết quả cũ sẽ giấu mất một lỗi thật của client.
        $this->postJson(
            self::BASE.'/quote-requests',
            $this->quoteItemsPayload([['product_id' => $first->sku_id]]),
            $headers,
        )->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame(1, PublicQuoteRequest::query()->count());
        $this->assertSame(2, PublicQuoteRequestItem::query()->count());
    }

    public function test_single_product_body_still_works_and_becomes_one_line(): void
    {
        $sku = $this->visibleSku('QR-M06', 'May bom mot dong');

        $this->postJson(
            self::BASE.'/quote-requests',
            $this->quotePayload($sku->sku_id),
            ['Idempotency-Key' => 'quote-key-legacy-0000001'],
        )->assertStatus(201);

        $this->assertSame(1, PublicQuoteRequest::query()->sole()->item_count);

        $item = PublicQuoteRequestItem::query()->sole();
        $this->assertSame(1, $item->line_no);
        $this->assertSame($sku->sku_id, $item->product_id);
        $this->assertNull($item->quantity);
        $this->assertSame('QR-M06', $item->sku_code_snapshot);
    }

    /**
     * Body một-sản-phẩm phải băm ra ĐÚNG chuỗi như hợp đồng trước khi có
     * `items[]`. Không giữ được điều này thì mọi lần retry vắt qua thời điểm
     * deploy sẽ ăn 409 — lỗi chỉ xuất hiện trên production, sống đúng 24h, và
     * không tài nào tái hiện ở môi trường dev.
     */
    public function test_single_product_payload_hash_is_unchanged_by_the_items_contract(): void
    {
        $sku = $this->visibleSku('QR-M07', 'May bom bam');

        $this->postJson(
            self::BASE.'/quote-requests',
            $this->quotePayload($sku->sku_id),
            ['Idempotency-Key' => 'quote-key-hash-000000001'],
        )->assertStatus(201);

        // Công thức băm CŨ, chép nguyên văn vào đây làm mốc.
        $canonical = [
            'product_id' => $sku->sku_id,
            'variant_id' => null,
            'full_name' => 'Nguyễn Văn A',
            'phone' => '0912345678',
            'phone_normalized' => '0912345678',
            'province_code' => null,
            'note' => null,
            'privacy_version' => '1.0.0',
        ];
        ksort($canonical);

        $this->assertSame(
            hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE)),
            PublicQuoteRequest::query()->value('payload_hash'),
        );
    }

    public function test_items_shape_rejects_empty_duplicate_and_oversized_selection(): void
    {
        $sku = $this->visibleSku('QR-M08', 'May bom validate items');

        $this->postJson(
            self::BASE.'/quote-requests',
            $this->quoteItemsPayload([]),
            ['Idempotency-Key' => 'quote-key-items-empty-01'],
        )->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_FAILED');

        // Cùng một sản phẩm hai lần là lỗi client; gộp hộ là tự ý sửa nội dung
        // khách gửi, và khi đó danh sách ghi lại không còn khớp danh sách gửi đi.
        $duplicate = $this->postJson(
            self::BASE.'/quote-requests',
            $this->quoteItemsPayload([
                ['product_id' => $sku->sku_id],
                ['product_id' => $sku->sku_id],
            ]),
            ['Idempotency-Key' => 'quote-key-items-dup-0001'],
        )->assertStatus(422);
        $this->assertArrayHasKey('items.1.product_id', $duplicate->json('errors'));

        config()->set('public_api.quote_request.max_items', 2);
        $second = $this->visibleSku('QR-M09', 'May bom vuot tran 1');
        $third = $this->visibleSku('QR-M10', 'May bom vuot tran 2');

        $oversized = $this->postJson(
            self::BASE.'/quote-requests',
            $this->quoteItemsPayload([
                ['product_id' => $sku->sku_id],
                ['product_id' => $second->sku_id],
                ['product_id' => $third->sku_id],
            ]),
            ['Idempotency-Key' => 'quote-key-items-max-0001'],
        )->assertStatus(422);
        $this->assertArrayHasKey('items', $oversized->json('errors'));

        $this->assertSame(0, PublicQuoteRequest::query()->count());
    }

    public function test_unavailable_product_names_the_exact_line_and_writes_nothing(): void
    {
        $warehouse = $this->warehouseFixture();
        $visible = $this->visibleSku('QR-M11', 'May bom con hang');
        $hidden = $this->sku('QR-M12', 'May bom chua publish');
        $this->seedAvailableBalance($warehouse->id, $hidden->sku_id, 5);

        $response = $this->postJson(
            self::BASE.'/quote-requests',
            $this->quoteItemsPayload([
                ['product_id' => $visible->sku_id],
                ['product_id' => $hidden->sku_id],
            ]),
            ['Idempotency-Key' => 'quote-key-items-404-0001'],
        )->assertNotFound()->assertJsonPath('error_code', 'PRODUCT_NOT_FOUND');

        // FE phải bỏ được đúng dòng hỏng thay vì xoá sạch lựa chọn của khách.
        $this->assertSame(['items.1.product_id'], array_keys($response->json('errors')));

        // Hỏng một dòng thì KHÔNG được tạo yêu cầu nửa vời.
        $this->assertSame(0, PublicQuoteRequest::query()->count());
        $this->assertSame(0, PublicQuoteRequestItem::query()->count());
    }

    public function test_quantity_must_be_a_whole_number_within_the_configured_range(): void
    {
        $sku = $this->visibleSku('QR-M13', 'May bom so luong');

        foreach ([0, -1, 'hai', 1.5, 10000] as $index => $quantity) {
            $response = $this->postJson(
                self::BASE.'/quote-requests',
                $this->quoteItemsPayload([['product_id' => $sku->sku_id, 'quantity' => $quantity]]),
                ['Idempotency-Key' => 'quote-key-items-qty-000'.$index],
            )->assertStatus(422);

            $this->assertArrayHasKey('items.0.quantity', $response->json('errors'));
        }

        $this->assertSame(0, PublicQuoteRequest::query()->count());
    }

    /**
     * `variant_id` phải là thứ mà chính API danh mục đã đưa xuống.
     *
     * Trong danh mục này biến thể KHÔNG phải thực thể riêng — nó là một SKU anh
     * em cùng family, đúng những id nằm trong `variants[]` của
     * `GET /products/{slug}`. Luật cũ chỉ chấp nhận `variant_id === product_id`,
     * nên FE gửi lại chính id mà API vừa đưa cho lại bị 422; luật mới nhận cả
     * hai và vẫn chặn id của một sản phẩm không liên quan.
     */
    public function test_variant_id_must_be_the_product_itself_or_one_of_its_siblings(): void
    {
        $product = $this->visibleSku('QR-M16', 'May bom bien the goc');
        $sibling = $this->visibleSku('QR-M17', 'May bom bien the 220V');
        $stranger = $this->visibleSku('QR-M18', 'May bom khong lien quan');

        DB::table('sku')
            ->whereIn('sku_id', [$product->sku_id, $sibling->sku_id])
            ->update(['family_name' => 'HN-FAMILY-BIEN-THE']);

        // Cách FE cũ nói "không chọn biến thể nào": gửi lại chính product_id.
        $this->postJson(
            self::BASE.'/quote-requests',
            $this->quoteItemsPayload([
                ['product_id' => $product->sku_id, 'variant_id' => $product->sku_id],
            ]),
            ['Idempotency-Key' => 'quote-key-items-var-0001'],
        )->assertStatus(201);

        // Biến thể thật của cùng family.
        $this->postJson(
            self::BASE.'/quote-requests',
            $this->quoteItemsPayload([
                ['product_id' => $product->sku_id, 'variant_id' => $sibling->sku_id],
            ]),
            ['Idempotency-Key' => 'quote-key-items-var-0002'],
        )->assertStatus(201);

        // Một sản phẩm khác hẳn thì không phải biến thể của nó.
        $rejected = $this->postJson(
            self::BASE.'/quote-requests',
            $this->quoteItemsPayload([
                ['product_id' => $product->sku_id, 'variant_id' => $stranger->sku_id],
            ]),
            ['Idempotency-Key' => 'quote-key-items-var-0003'],
        )->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_FAILED');

        $this->assertArrayHasKey('items.0.variant_id', $rejected->json('errors'));
        $this->assertSame(2, PublicQuoteRequest::query()->count());
    }

    public function test_legacy_product_id_may_ride_along_only_when_it_matches_line_one(): void
    {
        $first = $this->visibleSku('QR-M14', 'May bom chuyen tiep 1');
        $second = $this->visibleSku('QR-M15', 'May bom chuyen tiep 2');

        // FE trong giai đoạn chuyển tiếp gửi kèm cả hai hình để chạy được với
        // bản BE cũ lẫn mới.
        $this->postJson(
            self::BASE.'/quote-requests',
            ['product_id' => $first->sku_id] + $this->quoteItemsPayload([
                ['product_id' => $first->sku_id],
                ['product_id' => $second->sku_id],
            ]),
            ['Idempotency-Key' => 'quote-key-items-mix-0001'],
        )->assertStatus(201);

        // Lệch nhau: hai chỗ trong FE đang nói hai chuyện khác nhau. Im lặng
        // chọn một bên chính là kiểu sai nghiệp vụ mà hợp đồng này sinh ra để
        // chặn.
        $mismatch = $this->postJson(
            self::BASE.'/quote-requests',
            ['product_id' => $second->sku_id] + $this->quoteItemsPayload([
                ['product_id' => $first->sku_id],
            ]),
            ['Idempotency-Key' => 'quote-key-items-mix-0002'],
        )->assertStatus(422);

        $this->assertArrayHasKey('product_id', $mismatch->json('errors'));
        $this->assertSame(1, PublicQuoteRequest::query()->count());
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    private function sku(string $code, string $name, ?string $categoryId = null): Sku
    {
        return Sku::create([
            'sku_code' => $code,
            'sku_name' => $name,
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
            'category_id' => $categoryId,
        ]);
    }

    private function publishedSku(string $code, string $name, ?string $categoryId = null): Sku
    {
        $sku = $this->sku($code, $name, $categoryId);

        $headers = $this->superAdminHeaders();
        unset($headers['__user_id']);

        $this->patchJson(
            "/api/v1/pv/products/{$sku->sku_id}/publish",
            ['published' => true],
            $headers + ['If-Match' => '0'],
        )->assertOk();

        return $sku;
    }

    /** SKU đã publish và còn hàng — dùng khi test cần xác nhận nhãn IN_STOCK. */
    private function visibleSku(string $code, string $name): Sku
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->publishedSku($code, $name);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 5);

        return $sku;
    }

    private function profileIdOf(string $skuId): string
    {
        $relation = PvRelations::profile();

        return (string) DB::table($relation['table'])->where($relation['sku'], $skuId)->value($relation['key']);
    }

    private function category(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('product_categories')->insert([
            'id' => $id,
            'category_code' => $code,
            'category_name' => $name,
            'status' => 'ACTIVE',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Trên baseline sqlite, sku.category_id trỏ product_masters.
        if (DB::getDriverName() !== 'pgsql') {
            DB::table('product_masters')->insert([
                'master_id' => $id,
                'master_type' => 'CATEGORY',
                'code' => $code,
                'name' => $name,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function tag(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table(PvRelations::READ_TAG)->insert([
            'tag_id' => $id,
            'tag_code' => $code,
            'tag_name' => $name,
            'status' => 'ACTIVE',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function mapTag(string $profileId, string $tagId): void
    {
        DB::table(PvRelations::productTagTable())->insert([
            'profile_id' => $profileId,
            'tag_id' => $tagId,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $specification
     */
    private function setSpecification(string $skuId, array $specification): void
    {
        DB::table('sku')->where('sku_id', $skuId)->update([
            'specification' => json_encode($specification, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function quotePayload(string $productId): array
    {
        return [
            'product_id' => $productId,
            'variant_id' => null,
            'full_name' => 'Nguyễn Văn A',
            'phone' => '0912345678',
            'province_code' => null,
            'note' => null,
            'consent' => true,
            'privacy_version' => '1.0.0',
        ];
    }

    /**
     * Cùng phần thông tin liên hệ như quotePayload(), nhưng phần sản phẩm đi
     * theo hình `items[]` của hợp đồng nhiều sản phẩm.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function quoteItemsPayload(array $items): array
    {
        return [
            'items' => $items,
            'full_name' => 'Nguyễn Văn A',
            'phone' => '0912345678',
            'province_code' => null,
            'note' => null,
            'consent' => true,
            'privacy_version' => '1.0.0',
        ];
    }
}
