<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026_08_10_000750_materialize_erd_1_0_physical_objects chỉ chạy trên PostgreSQL,
 * nên nhánh sqlite (bộ CI chạy full suite) dừng lại ở hình dạng bảng cũ trước
 * HN-WMS-ERD-DD-001: outbound_requests thiếu source_checksum, item_instances thiếu
 * item_code/status, printer_configs thiếu cấu hình khổ tem... Hệ quả là fixture và
 * code viết theo ERD chạy được trên DB tham chiếu nhưng nổ "no such column" trên CI.
 *
 * Migration này chạy đúng bước "thêm cột còn thiếu theo Data Dictionary" của 000750,
 * dùng chung một nguồn sự thật (docs/HoaNam_ERD_Moi_Nhat.md), chỉ khác ở chỗ:
 *
 * 1. Chỉ đụng vào bảng vật lý thật của sqlite. 27 bảng canonical (app_users,
 *    warranty_cases, reversal_documents...) trên sqlite vẫn là view/tên số ít vì
 *    2026_08_10_000791 cố ý chỉ đảo chiều trên PostgreSQL — đó là quyết định kiến
 *    trúc, không phải lỗ hổng, nên không đụng tới ở đây.
 * 2. Cột thêm vào để nullable. PostgreSQL giữ NOT NULL nhờ 000750 + trigger đồng bộ
 *    ở 000765; sqlite không có tầng trigger đó nên ép NOT NULL sẽ chặn chính những
 *    fixture mà migration này muốn cứu.
 * 3. Bỏ qua cột định danh `id` ở các bảng mà sqlite đang dùng khóa chính tên khác
 *    (agency_id, item_id...). Trên PostgreSQL các cột này được backfill và giữ đồng
 *    bộ bằng trigger; thêm một cột `id` luôn NULL trên sqlite sẽ nguy hiểm hơn là
 *    thiếu nó, vì code đọc `id` sẽ nhận NULL thay vì lỗi.
 */
return new class extends Migration
{
    /**
     * Cột định danh trùng khóa chính hiện có — xem lý do (3) ở docblock.
     *
     * @var array<string, array<int, string>>
     */
    private const SKIP_IDENTITY_ALIAS = [
        'agencies' => ['id'],
        'defect_catalogs' => ['id'],
        'import_batches' => ['id'],
        'item_instances' => ['id'],
        'physical_codes' => ['id'],
        'containers' => ['id'],
        'container_items' => ['id'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $physical = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table'"))
            ->pluck('name')
            ->all();

        foreach (HoaNamErdSchema::parse() as $table => $definition) {
            if (! in_array($table, $physical, true)) {
                continue;
            }

            foreach ($definition['fields'] as $field => $spec) {
                if (in_array($field, self::SKIP_IDENTITY_ALIAS[$table] ?? [], true)) {
                    continue;
                }
                if (Schema::hasColumn($table, $field)) {
                    continue;
                }

                DB::statement(sprintf(
                    'ALTER TABLE %s ADD COLUMN %s %s',
                    HoaNamErdSchema::quote($table),
                    HoaNamErdSchema::quote($field),
                    $this->sqliteType($spec['type']),
                ));
            }
        }
    }

    public function down(): void
    {
        // Fail-forward: gỡ cột đã vật chất hoá sẽ đưa sqlite quay lại đúng lỗ hổng
        // mà migration này vá.
    }

    /**
     * Ánh xạ kiểu trong Data Dictionary sang kiểu sqlite hiểu được. sqlite dùng type
     * affinity nên tên kiểu phải nằm trong bộ mà nó nhận diện đúng, thay vì bê
     * nguyên uuid/timestamptz/jsonb (đều rơi về affinity NUMERIC).
     */
    private function sqliteType(string $documentedType): string
    {
        $type = strtolower(str_replace(' ', '', $documentedType));

        return match (true) {
            $type === 'uuid' => 'varchar(36)',
            $type === 'text' => 'text',
            $type === 'integer' => 'integer',
            $type === 'bigint', $type === 'bigserial' => 'integer',
            $type === 'boolean' => 'tinyint(1)',
            $type === 'date' => 'date',
            $type === 'timestamptz' => 'datetime',
            $type === 'jsonb' => 'text',
            preg_match('/^(varchar|char)\(\d+\)$/', $type) === 1 => $type,
            preg_match('/^numeric\(\d+,\d+\)$/', $type) === 1 => $type,
            default => throw new RuntimeException("Unsupported ERD type for sqlite: {$documentedType}"),
        };
    }
};
