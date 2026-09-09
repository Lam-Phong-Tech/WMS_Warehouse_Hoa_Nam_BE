<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lấp các CỘT HIỂN THỊ còn trống 100% sau khi chạy toàn bộ chuỗi seeder
 * (rà soát 04/09/2026: truy vấn từng cột của mọi bảng, lọc ra cột nào có
 * `nulls + blanks = total`).
 *
 * Bộ "phủ kín" trước đó đã lo đủ TRẠNG THÁI và BỘ LỌC (chứng từ 4 trạng thái,
 * bảo hành 6, tồn kho 4, đại lý 4 loại người nhận…). Seeder này lo phần còn
 * lại: những ô trên màn luôn hiện "Chưa có" vì cột nguồn chưa từng được ghi.
 *
 *  1. container_items — CHỈ 5/132 SKU có tồn từng được gán vị trí, nên panel
 *     "Vị trí lưu kho" (InventoryBalanceController::show → containers) trống ở
 *     96% SKU.
 *  2. import_reports.error_file_path — 0/17 dòng có, nên nút "Tải tệp lỗi" ở
 *     màn RPT-04 KHÔNG BAO GIỜ hiện dù có lô 25 dòng lỗi.
 *  3. label_snapshots.sku_id / item_id — 88/88 NULL nên cột SKU ở RPT-05 trống,
 *     dù chính `label_payload` đã mang sẵn hai giá trị đó.
 *  4. stock_documents.reject_reason — 9/9 chứng từ CANCELLED không có lý do huỷ.
 *  5. Các cột `note` của dòng chứng từ — trống 100% ở 4 bảng.
 *
 * Idempotent: mỗi bước đều có điều kiện "chỉ ghi khi đang trống", chạy lại
 * không nhân bản và không đè dữ liệu thật do người dùng nhập.
 */
class DisplayFieldCoverageSeeder extends Seeder
{
    /** Khu vực dùng cho các khay tạo thêm — trùng bảng zone đang có. */
    private const ZONES = ['ZONE-A', 'ZONE-B', 'ZONE-C', 'ZONE-D'];

    /** Số khay tối thiểu để ~132 SKU rải ra không dồn một chỗ. */
    private const MIN_TRAYS = 10;

    public function run(): void
    {
        // Cùng guard driver với DatabaseSeeder: SQL dưới đây dùng cú pháp
        // Postgres (jsonb ->>, gen_random_uuid, FILTER). SQLite của test suite
        // bỏ qua.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $trays = $this->ensureStorageTrays();
        $this->placeSkusIntoTrays($trays);
        $this->backfillImportErrorFiles();
        $this->backfillCancelReasons();
        $this->backfillLineNotes();

        $this->reportCoverage();
    }

    /**
     * Báo cáo ĐỘ PHỦ SAU KHI CHẠY, không báo "đã ghi thêm bao nhiêu dòng".
     *
     * Lý do: trong cùng tiến trình seeder, cả `DB::update()` (rowCount) lẫn một
     * `SELECT count(*)` đếm trước đều trả về số dòng ĐÃ CÓ ghi chú (800 cho
     * inbound_lines) thay vì 0, dù chạy y nguyên câu đó ngoài seeder — bằng psql
     * hay bằng script bootstrap Laravel riêng — đều trả 0. Chưa tìm ra nguyên
     * nhân, nên KHÔNG lấy con số đó làm log: nó khiến người đọc tưởng seeder ghi
     * đè mỗi lần chạy. Tính idempotent đã được kiểm bằng dữ liệu: md5 của toàn
     * bộ cột note không đổi sau hai lần chạy liên tiếp.
     */
    private function reportCoverage(): void
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
              (SELECT count(DISTINCT sku_id) FROM container_items
                WHERE sku_id IS NOT NULL AND status = 'ACTIVE')            AS sku_placed,
              (SELECT count(*) FROM import_reports
                WHERE error_rows > 0 AND error_file_path IS NOT NULL)      AS import_files,
              (SELECT count(*) FROM stock_documents
                WHERE status = 'CANCELLED' AND reject_reason IS NOT NULL)  AS cancel_reasons,
              (SELECT count(*) FROM inbound_lines WHERE note IS NOT NULL)
              + (SELECT count(*) FROM outbound_lines WHERE note IS NOT NULL)
              + (SELECT count(*) FROM component_issue_lines WHERE note IS NOT NULL)
              + (SELECT count(*) FROM outbound_request_lines WHERE note IS NOT NULL) AS line_notes
        SQL);

        $this->command?->info(sprintf(
            '[DisplayFieldCoverage] độ phủ hiện tại — SKU có vị trí: %d · lô import có tệp lỗi: %d · chứng từ huỷ có lý do: %d · dòng có ghi chú: %d',
            (int) $row->sku_placed,
            (int) $row->import_files,
            (int) $row->cancel_reasons,
            (int) $row->line_notes,
        ));
    }

    /**
     * Bảo đảm có đủ khay TRAY mang zone_code để chứa hàng.
     *
     * CỐ Ý dùng TRAY chứ không phải BOX/CARTON: trigger
     * `hn_erd_guard_container_item_primary_sku` bắt mapping-theo-số-lượng vào
     * BOX/CARTON phải khớp `containers.primary_sku_id`, tức mỗi thùng chỉ chứa
     * đúng một SKU. Khay kho thì chứa nhiều SKU — đúng nghiệp vụ hơn và chỉ cần
     * `primary_sku_id` để NULL.
     *
     * @return list<string> container_id của các khay dùng được
     */
    private function ensureStorageTrays(): array
    {
        $existing = DB::table('containers')
            ->where('container_type', 'TRAY')
            ->whereNull('primary_sku_id')
            ->whereNotNull('zone_code')
            ->orderBy('container_code')
            ->pluck('container_id')
            ->map(static fn ($id) => (string) $id)
            ->all();

        for ($index = count($existing); $index < self::MIN_TRAYS; $index++) {
            $code = sprintf('TRAY-COV-%02d', $index + 1);

            $already = DB::table('containers')->where('container_code', $code)->value('container_id');
            if ($already !== null) {
                $existing[] = (string) $already;

                continue;
            }

            $id = (string) Str::uuid();
            DB::table('containers')->insert([
                'container_id' => $id,
                'id' => $id,
                'container_code' => $code,
                'container_type' => 'TRAY',
                'primary_sku_id' => null,
                'zone_code' => self::ZONES[$index % count(self::ZONES)],
                'location_hint' => sprintf('%s · Kệ %02d', self::ZONES[$index % count(self::ZONES)], $index + 1),
                'status' => 'STORED',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $existing[] = $id;
        }

        return $existing;
    }

    /**
     * Gán mỗi SKU đang có tồn vào một khay, rải đều theo vòng tròn.
     *
     * Chỉ đụng SKU CHƯA có mapping ACTIVE nào — hàng do người dùng tự cất vẫn
     * giữ nguyên vị trí thật của nó.
     *
     * @param  list<string>  $trays
     */
    private function placeSkusIntoTrays(array $trays): int
    {
        if ($trays === []) {
            return 0;
        }

        // quantity phải > 0 (chk_erd_container_item_shape) nên chỉ lấy SKU có
        // tồn thực sự; SKU danh mục chưa nhập hàng thì không có vị trí là đúng.
        $rows = DB::select(<<<'SQL'
            SELECT b.product_id AS sku_id, SUM(b.quantity)::bigint AS qty
            FROM inventory_balances b
            WHERE b.product_id IS NOT NULL
            GROUP BY b.product_id
            HAVING SUM(b.quantity) > 0
               AND NOT EXISTS (
                   SELECT 1 FROM container_items ci
                   WHERE ci.sku_id = b.product_id AND ci.status = 'ACTIVE'
               )
            ORDER BY b.product_id
        SQL);

        $actor = DB::table('app_users')->orderBy('created_at')->value('id');
        $placed = 0;

        foreach ($rows as $index => $row) {
            DB::table('container_items')->insert([
                // `id` do trigger hn_erd_normalize_runtime_container_items suy ra
                // từ container_item_id — cùng lối các seeder khác đang dùng.
                'container_item_id' => (string) Str::uuid(),
                'container_id' => $trays[$index % count($trays)],
                'item_id' => null,
                'sku_id' => (string) $row->sku_id,
                'quantity' => (int) $row->qty,
                'status' => 'ACTIVE',
                'is_active' => true,
                'source_type' => 'SEED_COVERAGE',
                'placed_at' => now()->subDays(($index % 45) + 1),
                'placed_by' => $actor,
                'created_by' => $actor,
                'updated_by' => $actor,
                'version' => 1,
                'created_at' => now()->subDays(($index % 45) + 1),
                'updated_at' => now(),
            ]);
            $placed++;
        }

        return $placed;
    }

    /**
     * Lô import có dòng lỗi thì phải có tệp lỗi tải về — nếu không, nút "Tải tệp
     * lỗi" ở RPT-04 bị ẩn vĩnh viễn và không ai kiểm thử được luồng đó.
     */
    private function backfillImportErrorFiles(): int
    {
        // UAT TC_FUNC_05: bản trước chỉ UPDATE path "chay" ('imports/errors/…',
        // sai cả thư mục so với ImportErrorFileService::STORAGE_SUBDIR) mà không
        // sinh file nào — nút "Tải tệp lỗi" sáng nhưng bấm là RESOURCE_NOT_FOUND.
        // Sinh file THẬT qua đúng service (đúng disk, đúng schema cột §13.12);
        // hạn dùng lấy từ chính service để ba nơi không nói khác nhau.
        $service = app(ModulesWarehouseServicesImportErrorFileService::class);
        $reports = DB::table('import_reports')
            ->where('error_rows', '>', 0)
            ->whereNull('error_file_path')
            ->get(['import_report_id', 'file_name', 'row_errors']);

        $placed = 0;
        foreach ($reports as $report) {
            $rowErrors = json_decode((string) $report->row_errors, true);
            if (! is_array($rowErrors) || $rowErrors === []) {
                continue;
            }
            $generated = $service->generate(
                (string) $report->import_report_id,
                $rowErrors,
                (string) $report->file_name,
                null,
            );
            if ($generated === null) {
                continue;
            }
            DB::table('import_reports')
                ->where('import_report_id', $report->import_report_id)
                ->update([
                    'error_file_path' => $generated['path'],
                    'error_file_expires_at' => $generated['expires_at'],
                    'updated_at' => now(),
                ]);
            $placed++;
        }

        return $placed;
    }

    /*
     * KHÔNG có bước backfill cho label_snapshots.sku_id / item_id ở đây, dù hai
     * cột đó từng trống 88/88.
     *
     * Trigger `LABEL_SNAPSHOT_IMMUTABLE` chặn MỌI update lên label_payload /
     * outbound_document_id / outbound_item_match_id / sku_id / item_id sau khi
     * nhãn đã chốt — đúng thiết kế, vì nhãn in ra rồi thì bằng chứng không được
     * sửa. Vì vậy hai cột này phải được gán NGAY LÚC INSERT; đã sửa tại 5 chỗ
     * sinh nhãn (HoaNamBulkDemoSeeder, WarehouseBusinessDataSeeder,
     * WarehouseOperationsCoverageSeeder, OperationalReportRangeSeeder ×2).
     * Hệ quả: các dòng nhãn đã tồn tại trong DB cũ vẫn NULL, chỉ lần seed lại
     * từ đầu mới có dữ liệu.
     */

    /** Chứng từ đã huỷ mà không có lý do thì màn chi tiết chỉ hiện "Chưa có". */
    private function backfillCancelReasons(): int
    {
        return DB::update(<<<'SQL'
            UPDATE stock_documents
               SET reject_reason = CASE (abs(hashtext(doc_no)) % 4)
                     WHEN 0 THEN 'Khách báo hoãn giao, huỷ phiếu chờ lịch mới'
                     WHEN 1 THEN 'Sai SKU trên phiếu, lập lại chứng từ khác'
                     WHEN 2 THEN 'Trùng phiếu do thao tác lặp, giữ phiếu gốc'
                     ELSE 'Thiếu hàng thực tế so với phiếu, huỷ để kiểm lại tồn'
                   END,
                   rejected_at = COALESCE(rejected_at, updated_at),
                   updated_at = now()
             WHERE status = 'CANCELLED'
               AND reject_reason IS NULL
        SQL);
    }

    /**
     * Ghi chú dòng: điền cho khoảng 2/3 số dòng, CỐ Ý chừa lại phần còn trống —
     * cột ghi chú trống là chuyện bình thường ngoài đời, lấp 100% thì màn hình
     * trông giả và không ai thấy được trạng thái rỗng hiển thị ra sao.
     */
    private function backfillLineNotes(): int
    {
        $total = 0;

        $notes = [
            'inbound_lines' => [
                'Hàng nguyên đai nguyên kiện, đã đối chiếu phiếu giao',
                'Thùng ngoài móp nhẹ, hàng bên trong nguyên vẹn',
                'Giao thiếu so với đơn, đã ghi nhận phần còn lại',
            ],
            'outbound_lines' => [
                'Giao trong giờ hành chính, gọi trước 30 phút',
                'Khách yêu cầu đóng thêm lớp xốp chống va đập',
                'Xuất kèm phiếu bảo hành bản cứng',
            ],
            'component_issue_lines' => [
                'Linh kiện thay theo kết quả kiểm tra bảo hành',
                'Lấy từ hộp linh kiện đang mở, còn dư trả lại kho',
                'Thay cả cụm theo khuyến cáo hãng',
            ],
            'outbound_request_lines' => [
                'Ưu tiên giao trước cho đại lý khu vực',
                'Chờ xác nhận số lượng cuối từ khách',
                'Gộp chung chuyến với yêu cầu cùng tuyến',
            ],
        ];

        foreach ($notes as $table => $samples) {
            $primaryKey = $this->primaryKeyOf($table);
            if ($primaryKey === null) {
                continue;
            }

            $cases = [];
            foreach ($samples as $index => $text) {
                $cases[] = sprintf('WHEN %d THEN %s', $index, DB::getPdo()->quote($text));
            }
            $caseSql = implode(' ', $cases);

            // hashtext(pk) % 3 vừa quyết định câu ghi chú vừa quyết định dòng nào
            // được chừa trống — cùng một hàm nên chạy lại vẫn ra đúng tập cũ.
            $modulo = $this->modulo($samples);
            $filled = $this->sampleCount($samples);

            // Đếm TRƯỚC bằng SELECT rồi mới UPDATE, và báo cáo theo con số này.
            // `DB::update()` (PDOStatement::rowCount) trả về số phồng khi gọi
            // nhiều lệnh liên tiếp trong cùng một tiến trình seeder — đã kiểm:
            // dữ liệu sau hai lần chạy giống nhau từng byte (md5 của toàn bộ
            // cột note không đổi) nhưng rowCount vẫn báo 800. Lấy rowCount làm
            // log thì người đọc tưởng seeder ghi đè mỗi lần chạy.
            $pending = (int) (DB::selectOne(<<<SQL
                SELECT count(*) AS n FROM {$table}
                 WHERE note IS NULL
                   AND (abs(hashtext({$primaryKey}::text)) % {$modulo}) < {$filled}
            SQL)->n ?? 0);

            DB::update(<<<SQL
                UPDATE {$table}
                   SET note = CASE (abs(hashtext({$primaryKey}::text)) % {$this->modulo($samples)})
                                {$caseSql}
                                ELSE NULL
                              END
                 WHERE note IS NULL
                   AND (abs(hashtext({$primaryKey}::text)) % {$this->modulo($samples)}) <
                       {$this->sampleCount($samples)}
            SQL);
            $total += $pending;
        }

        return $total;
    }

    /** Mẫu + 1 nhánh "để trống" ⇒ ~1/4 số dòng không có ghi chú. */
    private function modulo(array $samples): int
    {
        return count($samples) + 1;
    }

    private function sampleCount(array $samples): int
    {
        return count($samples);
    }

    /** Tên khoá chính thật của bảng — mỗi bảng ERD đặt một kiểu. */
    private function primaryKeyOf(string $table): ?string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT a.attname AS col
            FROM pg_index i
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey)
            WHERE i.indrelid = ?::regclass AND i.indisprimary
            LIMIT 1
        SQL, [$table]);

        return $row?->col;
    }
}
