<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mọi lần GHI vào stock_documents / stock_document_lines đang dựng lại TOÀN BỘ
 * bảng mirror ERD.
 *
 * 2026_08_10_000765 sinh mirror `hn_erd_mirror_*` ở mức FOR EACH STATEMENT với
 * thân hàm "quét cả projection": `INSERT ... SELECT * FROM <projection>
 * ON CONFLICT DO UPDATE`. Không mệnh đề nào thu hẹp theo dòng vừa đổi, nên một
 * `UPDATE stock_documents SET version` đúng 1 dòng vẫn phải đọc-ghi lại cả
 * `inbound_lines` lẫn `outbound_lines`.
 *
 * Đo trên DB dev (EXPLAIN ANALYZE, 555 phiếu / 5.4k dòng chứng từ):
 *   UPDATE stock_documents ... WHERE id = ?   -> 230 ms, trong đó
 *     trg_erd_mirror_inbound_lines_from_stock_documents      160 ms
 *     trg_erd_mirror_outbound_lines_from_stock_documents      53 ms
 *     trg_erd_mirror_inbound_documents_from_stock_documents   15 ms
 *
 * Chi phí này chia đều cho MỌI endpoint ghi phiếu, nhưng lộ rõ nhất ở
 * POST /api/v1/mini-app/inbound/record: mỗi mã quét sinh 3 câu ghi một dòng
 * (qty_planned, scanned_qty, version) nên phiếu 10 mã tốn ~17 s chỉ để chạy
 * trigger. Vì thân hàm quét cả bảng, thời gian còn tăng theo lượng phiếu lịch
 * sử — càng dùng lâu càng chậm.
 *
 * Bản vá thu hẹp PHẠM VI, KHÔNG đổi NỘI DUNG mirror:
 *  - trigger chuyển sang FOR EACH ROW để biết dòng nào vừa đổi;
 *  - ghi từ stock_document_lines -> đồng bộ đúng dòng đó (`id = <line id>`);
 *  - ghi từ stock_documents -> đồng bộ đúng các dòng của phiếu đó
 *    (`inbound_document_id = <doc id>`), vì projection lấy created_by/updated_by
 *    và bộ lọc doc_type từ phiếu.
 *
 * GIỮ NGUYÊN mệnh đề dọn rác theo đúng allowlist cũ: chỉ `inbound_lines` có
 * `DELETE ... NOT EXISTS` (thêm ở 2026_08_19_130000 để PATCH thay cấu trúc dòng
 * không đụng ux_erd_inbound_lines_f891eba6). `inbound_documents` và
 * `outbound_lines` vẫn upsert-only y như 2026_08_10_000765 — thêm DELETE vào
 * đây là đổi hành vi ngoài phạm vi bản vá hiệu năng, và với inbound_documents
 * còn vỡ luôn: trigger PostgreSQL chạy theo THỨ TỰ TÊN, mà
 * trg_erd_mirror_inbound_documents_* đứng trước trg_erd_mirror_inbound_lines_*,
 * nên xoá phiếu khỏi mirror trước khi dọn dòng con sẽ vi phạm
 * fk_erd_inbound_lines_inbound_document_id (đã dựng lại được khi đổi doc_type
 * receipt -> issue).
 *
 * Cùng khuôn mẫu đã áp cho outbound_documents ở
 * 2026_08_24_110000_scope_outbound_document_mirror_to_changed_issue_document.
 *
 * up() chạy một lần resync toàn bảng TRƯỚC khi đổi trigger (dòng con trước,
 * phiếu sau, để không vướng FK): sau bản vá này không còn cú ghi nào "quét cả
 * bảng" để tự chữa drift lịch sử nữa.
 */
return new class extends Migration
{
    /**
     * Mirror dòng cần thu hẹp: bảng canonical => cột khoá phiếu trên mirror đó.
     */
    private const LINE_MIRRORS = [
        'inbound_lines' => 'inbound_document_id',
        'outbound_lines' => 'outbound_document_id',
    ];

    /**
     * Mirror được phép dọn dòng không còn thuộc projection (allowlist của
     * 2026_08_10_000765 + bản vá 2026_08_19_130000). Mirror ngoài danh sách này
     * là upsert-only và bản vá hiệu năng không đổi điều đó.
     */
    private const CLEANS_UP = ['inbound_lines'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('stock_documents')
            || ! Schema::hasTable('stock_document_lines')) {
            return;
        }

        foreach (self::LINE_MIRRORS as $mirror => $documentKey) {
            $this->scopeLineMirror($mirror, $documentKey);
        }

        // Sau cùng: xoá rác ở inbound_documents chỉ an toàn khi inbound_lines
        // đã hết dòng con trỏ vào nó (fk_erd_inbound_lines_inbound_document_id).
        $this->scopeDocumentMirror();
    }

    /**
     * Rollback thật (WWHN-300 `migrations.reversible`, 10/09/2026). up() chỉ chạy trên
     * pgsql nên down() cũng vậy. Đưa ba mirror về đúng dạng ngay trước migration này:
     *
     *   - `inbound_documents`, `outbound_lines`: hàm upsert toàn projection do generator
     *     2026_08_10_000765 sinh (không mệnh đề dọn), trigger FOR EACH STATEMENT;
     *   - `inbound_lines`: thân hàm viết tay của 2026_08_19_130000 (dọn dòng rời projection
     *     + upsert toàn projection), trigger FOR EACH STATEMENT.
     *
     * Hệ quả vận hành: dựng lại đúng nút thắt "mỗi lần ghi quét cả bảng mirror" mà up() gỡ
     * (docblock đầu file). Không có dữ liệu nào phải hoàn tác: nội dung mirror là phép chiếu
     * của bảng canonical; up() chỉ đổi phạm vi đồng bộ (và resync một lần).
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('stock_documents')
            || ! Schema::hasTable('stock_document_lines')) {
            return;
        }

        if ($this->mirrorIsInstalled('inbound_lines')) {
            $this->restoreInboundLinesStatementMirror();
            $this->installStatementTrigger('trg_erd_mirror_inbound_lines_from_stock_documents', 'stock_documents', 'hn_erd_mirror_inbound_lines');
            $this->installStatementTrigger('trg_erd_mirror_inbound_lines_from_stock_document_lines', 'stock_document_lines', 'hn_erd_mirror_inbound_lines');
        }

        if ($this->mirrorIsInstalled('outbound_lines') && $this->restoreGeneratedStatementMirror('outbound_lines')) {
            $this->installStatementTrigger('trg_erd_mirror_outbound_lines_from_stock_documents', 'stock_documents', 'hn_erd_mirror_outbound_lines');
            $this->installStatementTrigger('trg_erd_mirror_outbound_lines_from_stock_document_lines', 'stock_document_lines', 'hn_erd_mirror_outbound_lines');
        }

        if ($this->mirrorIsInstalled('inbound_documents') && $this->restoreGeneratedStatementMirror('inbound_documents')) {
            $this->installStatementTrigger('trg_erd_mirror_inbound_documents_from_stock_documents', 'stock_documents', 'hn_erd_mirror_inbound_documents');
        }
    }

    private function mirrorIsInstalled(string $mirror): bool
    {
        return Schema::hasTable($mirror)
            && HoaNamErdSchema::relationKind($mirror.'__legacy_projection') === 'v';
    }

    /**
     * Dạng hàm của generator 2026_08_10_000765: một câu upsert toàn projection, cùng tập
     * cột (projection ∩ bảng mirror, theo ordinal_position) mà up() dùng.
     */
    private function restoreGeneratedStatementMirror(string $mirror): bool
    {
        $columns = $this->projectionColumns($mirror);
        if ($columns === [] || ! in_array('id', $columns, true)) {
            return false;
        }

        $quoted = $this->quotedList($columns);

        DB::statement('CREATE OR REPLACE FUNCTION '.HoaNamErdSchema::quote('hn_erd_mirror_'.$mirror).'() RETURNS trigger AS $$ BEGIN INSERT INTO '.HoaNamErdSchema::quote($mirror).' ('.$quoted.') SELECT '.$quoted.' FROM '.HoaNamErdSchema::quote($mirror.'__legacy_projection').' '.$this->upsertClause($columns).'; RETURN NULL; END; $$ LANGUAGE plpgsql');

        return true;
    }

    /** Thân hàm nguyên văn của 2026_08_19_130000_sync_inbound_lines_mirror_deletes. */
    private function restoreInboundLinesStatementMirror(): void
    {
        DB::statement('CREATE OR REPLACE FUNCTION hn_erd_mirror_inbound_lines() RETURNS trigger AS $$ BEGIN
            DELETE FROM inbound_lines c
             WHERE NOT EXISTS (
                SELECT 1 FROM inbound_lines__legacy_projection p WHERE p.id = c.id
             );
            INSERT INTO "inbound_lines" ("id", "inbound_document_id", "line_no", "sku_id", "import_batch_id", "expected_qty", "scanned_qty", "note", "created_at", "created_by", "updated_at", "updated_by", "version")
            SELECT "id", "inbound_document_id", "line_no", "sku_id", "import_batch_id", "expected_qty", "scanned_qty", "note", "created_at", "created_by", "updated_at", "updated_by", "version"
              FROM "inbound_lines__legacy_projection"
            ON CONFLICT ("id") DO UPDATE SET
                "inbound_document_id" = EXCLUDED."inbound_document_id",
                "line_no" = EXCLUDED."line_no",
                "sku_id" = EXCLUDED."sku_id",
                "import_batch_id" = EXCLUDED."import_batch_id",
                "expected_qty" = EXCLUDED."expected_qty",
                "scanned_qty" = EXCLUDED."scanned_qty",
                "note" = EXCLUDED."note",
                "created_at" = EXCLUDED."created_at",
                "created_by" = EXCLUDED."created_by",
                "updated_at" = EXCLUDED."updated_at",
                "updated_by" = EXCLUDED."updated_by",
                "version" = EXCLUDED."version";
            RETURN NULL;
        END; $$ LANGUAGE plpgsql');
    }

    private function installStatementTrigger(string $trigger, string $table, string $function): void
    {
        $quotedTrigger = HoaNamErdSchema::quote($trigger);
        $quotedTable = HoaNamErdSchema::quote($table);

        DB::statement("DROP TRIGGER IF EXISTS {$quotedTrigger} ON {$quotedTable}");
        DB::statement('CREATE TRIGGER '.$quotedTrigger.' AFTER INSERT OR UPDATE OR DELETE ON '.$quotedTable.' FOR EACH STATEMENT EXECUTE FUNCTION '.HoaNamErdSchema::quote($function).'()');
    }

    /**
     * inbound_documents: mirror 1-1 của stock_documents doc_type='receipt'.
     */
    private function scopeDocumentMirror(): void
    {
        if (! Schema::hasTable('inbound_documents')
            || HoaNamErdSchema::relationKind('inbound_documents__legacy_projection') !== 'v') {
            return;
        }

        $columns = $this->projectionColumns('inbound_documents');
        if ($columns === [] || ! in_array('id', $columns, true)) {
            return;
        }

        $this->resyncMirror('inbound_documents', $columns);

        $quoted = $this->quotedList($columns);
        $upsert = $this->upsertClause($columns);

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION "hn_erd_mirror_inbound_documents"()
            RETURNS trigger AS \$\$
            DECLARE
                v_document_id uuid;
            BEGIN
                v_document_id := CASE WHEN TG_OP = 'DELETE' THEN OLD."id" ELSE NEW."id" END;

                INSERT INTO "inbound_documents" ({$quoted})
                SELECT {$quoted}
                  FROM "inbound_documents__legacy_projection"
                 WHERE "id" = v_document_id
                {$upsert};

                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql
        SQL);

        $this->installRowTrigger(
            'trg_erd_mirror_inbound_documents_from_stock_documents',
            'stock_documents',
            'hn_erd_mirror_inbound_documents'
        );
    }

    /**
     * inbound_lines / outbound_lines: projection JOIN stock_document_lines với
     * stock_documents nên CẢ HAI bảng nguồn đều phải bắn mirror. Hàm phân biệt
     * nguồn bằng TG_TABLE_NAME để chọn đúng phạm vi.
     */
    private function scopeLineMirror(string $mirror, string $documentKey): void
    {
        if (! Schema::hasTable($mirror)
            || HoaNamErdSchema::relationKind($mirror.'__legacy_projection') !== 'v') {
            return;
        }

        $columns = $this->projectionColumns($mirror);
        if ($columns === [] || ! in_array('id', $columns, true) || ! in_array($documentKey, $columns, true)) {
            return;
        }

        $this->resyncMirror($mirror, $columns);

        $function = 'hn_erd_mirror_'.$mirror;
        $quoted = $this->quotedList($columns);
        $upsert = $this->upsertClause($columns);
        $key = HoaNamErdSchema::quote($documentKey);
        $cleanupByLine = $this->cleanupClause($mirror, 'c."id" = v_id');
        $cleanupByDocument = $this->cleanupClause($mirror, 'c.'.$key.' = v_id');

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION "{$function}"()
            RETURNS trigger AS \$\$
            DECLARE
                v_id uuid;
            BEGIN
                v_id := CASE WHEN TG_OP = 'DELETE' THEN OLD."id" ELSE NEW."id" END;

                IF TG_TABLE_NAME = 'stock_document_lines' THEN
                    -- Ghi trên MỘT dòng chứng từ: chỉ dòng đó vào/ra projection.
                    {$cleanupByLine}
                    INSERT INTO "{$mirror}" ({$quoted})
                    SELECT {$quoted}
                      FROM "{$mirror}__legacy_projection"
                     WHERE "id" = v_id
                    {$upsert};

                    RETURN NULL;
                END IF;

                -- Ghi trên PHIẾU: projection lấy created_by/updated_by và bộ lọc
                -- doc_type từ phiếu nên phải đồng bộ lại các dòng CỦA PHIẾU ĐÓ.
                {$cleanupByDocument}
                INSERT INTO "{$mirror}" ({$quoted})
                SELECT {$quoted}
                  FROM "{$mirror}__legacy_projection"
                 WHERE {$key} = v_id
                {$upsert};

                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql
        SQL);

        $this->installRowTrigger(
            'trg_erd_mirror_'.$mirror.'_from_stock_documents',
            'stock_documents',
            $function
        );
        $this->installRowTrigger(
            'trg_erd_mirror_'.$mirror.'_from_stock_document_lines',
            'stock_document_lines',
            $function
        );
    }

    /**
     * Mệnh đề dọn dòng đã rời projection, thu hẹp theo $scope. Mirror không nằm
     * trong allowlist giữ nguyên hành vi upsert-only.
     */
    private function cleanupClause(string $mirror, string $scope): string
    {
        if (! in_array($mirror, self::CLEANS_UP, true)) {
            return '';
        }

        return <<<SQL
        DELETE FROM "{$mirror}" c
                     WHERE {$scope}
                       AND NOT EXISTS (
                           SELECT 1 FROM "{$mirror}__legacy_projection" p WHERE p."id" = c."id"
                       );

        SQL;
    }

    private function installRowTrigger(string $trigger, string $table, string $function): void
    {
        $quotedTrigger = HoaNamErdSchema::quote($trigger);
        $quotedTable = HoaNamErdSchema::quote($table);
        $quotedFunction = HoaNamErdSchema::quote($function);

        DB::statement("DROP TRIGGER IF EXISTS {$quotedTrigger} ON {$quotedTable}");
        DB::statement(<<<SQL
            CREATE TRIGGER {$quotedTrigger}
            AFTER INSERT OR UPDATE OR DELETE ON {$quotedTable}
            FOR EACH ROW
            EXECUTE FUNCTION {$quotedFunction}()
        SQL);
    }

    /**
     * Một lần quét cả bảng TRƯỚC khi đổi trigger: sau bản vá không còn cú ghi
     * nào tự chữa drift lịch sử nữa nên phải chốt trạng thái sạch tại đây.
     * Dọn rác áp cho MỌI mirror ở đây (kể cả mirror upsert-only lúc chạy) vì
     * đây là thao tác một lần, có kiểm soát thứ tự dòng-con-trước-phiếu-sau.
     *
     * @param  list<string>  $columns
     */
    private function resyncMirror(string $mirror, array $columns): void
    {
        $quoted = $this->quotedList($columns);
        $upsert = $this->upsertClause($columns);

        DB::statement(<<<SQL
            DELETE FROM "{$mirror}" c
             WHERE NOT EXISTS (
                 SELECT 1 FROM "{$mirror}__legacy_projection" p WHERE p."id" = c."id"
             )
        SQL);

        DB::statement(<<<SQL
            INSERT INTO "{$mirror}" ({$quoted})
            SELECT {$quoted} FROM "{$mirror}__legacy_projection"
            {$upsert}
        SQL);
    }

    /** @return list<string> */
    private function projectionColumns(string $mirror): array
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', $mirror.'__legacy_projection')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->filter(fn (string $column): bool => Schema::hasColumn($mirror, $column))
            ->values()
            ->all();
    }

    /** @param list<string> $columns */
    private function quotedList(array $columns): string
    {
        return implode(', ', array_map(HoaNamErdSchema::quote(...), $columns));
    }

    /** @param list<string> $columns */
    private function upsertClause(array $columns): string
    {
        $updates = collect($columns)
            ->reject(fn (string $column): bool => $column === 'id')
            ->map(fn (string $column): string => HoaNamErdSchema::quote($column).' = EXCLUDED.'.HoaNamErdSchema::quote($column))
            ->implode(', ');

        return $updates === ''
            ? 'ON CONFLICT ("id") DO NOTHING'
            : 'ON CONFLICT ("id") DO UPDATE SET '.$updates;
    }
};
