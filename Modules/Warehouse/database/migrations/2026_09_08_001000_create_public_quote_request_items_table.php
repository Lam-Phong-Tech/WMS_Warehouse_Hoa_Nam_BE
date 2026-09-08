<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * `public_quote_request_items` — DANH SÁCH sản phẩm của một yêu cầu tư vấn
 * (POST /api/v1/public/quote-requests).
 *
 * ------------------------------------------------------------------------
 * VÌ SAO PHẢI CÓ BẢNG NÀY
 * ------------------------------------------------------------------------
 *
 * App PV cho người dùng chọn NHIỀU sản phẩm rồi gửi một yêu cầu tư vấn. Trước
 * bảng này hợp đồng chỉ có `product_id` đơn lẻ, nên FE chỉ còn ba đường vòng —
 * và cả ba đều sai nghiệp vụ:
 *
 *   1. Gửi N request: một khách hàng biến thành N lead rời rạc. Người trực máy
 *      gọi lại N lần, và không có gì nối chúng lại với nhau.
 *   2. Lấy sản phẩm đầu tiên: N-1 sản phẩm khách quan tâm biến mất KHÔNG DẤU
 *      VẾT — không log, không cột, không cách nào biết là đã mất.
 *   3. Nhét danh sách vào `note`: dữ liệu nghiệp vụ chui vào ô văn bản tự do,
 *      không truy vấn được, và `note` là cột PII đã mã hoá (WWHN-SEC-06) nên
 *      còn không đọc được bằng SQL nữa.
 *
 * Một yêu cầu = một dòng cha + N dòng con. Đó là hình dạng DUY NHẤT giữ được cả
 * "một khách hàng, một lần liên hệ" lẫn "khách quan tâm đúng những sản phẩm
 * này".
 *
 * ------------------------------------------------------------------------
 * PHẠM VI VẪN ĐÓNG NHƯ CŨ
 * ------------------------------------------------------------------------
 *
 * Bảng này KHÔNG biến yêu cầu tư vấn thành giỏ hàng/đơn hàng. Không giá, không
 * giữ chỗ tồn kho, không trạng thái dòng, không đụng ledger. `quantity` là con
 * số khách hàng NÊU RA để người tư vấn gọi lại cho đúng — nó không trừ tồn và
 * không ràng buộc gì với `inventory_balances`.
 *
 * KHÔNG đặt khoá ngoại tới sản phẩm, cùng lý do với bảng cha: bảng SKU vật lý
 * mang tên khác nhau giữa hai driver (xem Modules\Warehouse\Support\PvRelations).
 * Khoá ngoại tới bảng cha thì đặt được, và có: một dòng item mồ côi là rác
 * không ai đọc nổi.
 */
return new class extends Migration
{
    private const PARENT = 'public_quote_requests';

    private const TABLE = 'public_quote_request_items';

    public function up(): void
    {
        if (! Schema::hasTable(self::PARENT)) {
            return;
        }

        // Cột đếm nằm ở bảng CHA vì nó trả lời câu hỏi mà người đọc bảng cha sẽ
        // hỏi đầu tiên: "dòng này còn sản phẩm nào khác không?". Không có nó,
        // một truy vấn vận hành đọc `product_id` rồi dừng lại sẽ im lặng bỏ sót
        // N-1 sản phẩm — đúng cái bẫy mà bảng con sinh ra để chống.
        if (! Schema::hasColumn(self::PARENT, 'item_count')) {
            Schema::table(self::PARENT, function (Blueprint $table): void {
                $table->unsignedSmallInteger('item_count')->default(1);
            });
        }

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('request_id');

                // Thứ tự người dùng chọn trên màn hình. Giữ lại vì người gọi
                // điện đọc danh sách theo đúng thứ tự khách nhìn thấy; sắp lại
                // theo tên hay theo mã là kể lại một câu chuyện khác.
                $table->unsignedSmallInteger('line_no');

                $table->uuid('product_id');
                $table->uuid('variant_id')->nullable();

                // NULL = khách không nêu số lượng. Khác hẳn 1: "chưa nói" không
                // phải "một cái".
                $table->unsignedInteger('quantity')->nullable();

                // Ảnh chụp tại thời điểm gửi. Danh mục thật đổi liên tục (đổi
                // tên, gộp mã, gỡ publish), nên tra ngược `product_id` sáu tháng
                // sau sẽ ra cái tên HÔM NAY chứ không phải cái tên khách đã thấy.
                // Đây chính là phần "truy vết" của yêu cầu này.
                $table->string('sku_code_snapshot', 100)->nullable();
                $table->string('product_name_snapshot', 255)->nullable();

                $table->timestampTz('created_at')->useCurrent();

                $table->foreign('request_id')
                    ->references('id')->on(self::PARENT)
                    ->cascadeOnDelete();

                // Hai ràng buộc, hai lỗi khác nhau mà chúng chặn:
                //  - (request_id, line_no): hai dòng cùng số thứ tự thì thứ tự
                //    hiển thị trở nên không xác định.
                //  - (request_id, product_id): cùng một sản phẩm hai lần trong
                //    một yêu cầu là lỗi client (bấm hai lần), không phải ý định.
                $table->unique(['request_id', 'line_no'], 'uq_public_quote_request_items_line');
                $table->unique(['request_id', 'product_id'], 'uq_public_quote_request_items_product');

                // "Sản phẩm nào đang được hỏi nhiều nhất" — truy vấn vận hành
                // duy nhất có thật ở phạm vi này.
                $table->index(['product_id'], 'idx_public_quote_request_items_product');
            });
        }

        $this->backfillLegacyRequests();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);

        if (Schema::hasColumn(self::PARENT, 'item_count')) {
            Schema::table(self::PARENT, function (Blueprint $table): void {
                $table->dropColumn('item_count');
            });
        }
    }

    /**
     * Mỗi yêu cầu CŨ cũng phải có đúng một dòng item.
     *
     * Không backfill thì bảng con chỉ đúng cho dữ liệu mới, và mọi truy vấn
     * "sản phẩm nào được hỏi" phải viết thành UNION giữa bảng cha và bảng con —
     * tức là cái bẫy cũ vẫn còn nguyên, chỉ phức tạp hơn.
     *
     * `sku_code_snapshot` / `product_name_snapshot` để NULL cho các dòng cũ:
     * tên sản phẩm HÔM NAY không phải tên khách đã nhìn thấy lúc đó. Điền vào
     * là bịa ra một bằng chứng không có thật.
     */
    private function backfillLegacyRequests(): void
    {
        DB::table(self::PARENT)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from(self::TABLE)
                    ->whereColumn(self::TABLE.'.request_id', self::PARENT.'.id');
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                $now = now();
                $items = [];

                foreach ($rows as $row) {
                    $items[] = [
                        'id' => (string) Str::uuid(),
                        'request_id' => $row->id,
                        'line_no' => 1,
                        'product_id' => $row->product_id,
                        'variant_id' => $row->variant_id,
                        'quantity' => null,
                        'sku_code_snapshot' => null,
                        'product_name_snapshot' => null,
                        'created_at' => $row->created_at ?? $now,
                    ];
                }

                if ($items !== []) {
                    DB::table(self::TABLE)->insert($items);
                }
            }, 'id');

        DB::table(self::PARENT)->update(['item_count' => DB::raw(
            '(SELECT COUNT(*) FROM '.self::TABLE.' i WHERE i.request_id = '.self::PARENT.'.id)'
        )]);
    }
};
