<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Mirror ERD của phiếu/dòng phiếu phải bám theo DÒNG VỪA ĐỔI, không quét cả bảng.
 *
 * 2026_08_10_000765 dựng `hn_erd_mirror_*` ở mức FOR EACH STATEMENT với thân hàm
 * "INSERT ... SELECT * FROM <projection>", nên mỗi câu ghi một dòng vẫn phải
 * đọc-ghi lại toàn bộ inbound_lines/outbound_lines. Đó là nút thắt khiến
 * POST /api/v1/mini-app/inbound/record tốn ~1,9 s cho MỖI mã quét (phiếu 10 mã
 * ~17 s chỉ nằm trong trigger), và còn chậm dần theo lượng phiếu lịch sử.
 * 2026_09_08_090000 thu hẹp lại; test này chốt để không ai vô tình quay về.
 *
 * Không dùng RefreshDatabase: chỉ đọc + ghi trong transaction rồi rollback, nên
 * chạy được cả trên DB dev thật (xem guard ở TestCase::assertTestDatabaseIsDisposable).
 */
final class StockDocumentMirrorScopeTest extends TestCase
{
    use DatabaseTransactions;
    use PreparesOutboundFixtures;

    public function test_mirror_migration_is_row_scoped(): void
    {
        $migration = (string) file_get_contents(base_path(
            'Modules/Warehouse/database/migrations/2026_09_08_090000_scope_stock_document_erd_mirrors_to_changed_rows.php'
        ));

        $this->assertStringContainsString('FOR EACH ROW', $migration);
        // Hai phạm vi: theo dòng (ghi từ stock_document_lines) và theo phiếu
        // (ghi từ stock_documents, vì projection lấy created_by/updated_by + bộ
        // lọc doc_type từ phiếu).
        $this->assertStringContainsString('WHERE "id" = v_id', $migration);
        $this->assertStringContainsString('WHERE {$key} = v_id', $migration);
        $this->assertStringContainsString("TG_TABLE_NAME = 'stock_document_lines'", $migration);
    }

    public function test_installed_triggers_are_row_level(): void
    {
        $this->requirePostgres();

        $statementLevel = DB::select(<<<'SQL'
            SELECT c.relname AS table_name, t.tgname
            FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = current_schema()
              AND NOT t.tgisinternal
              AND c.relname IN ('stock_documents', 'stock_document_lines')
              AND t.tgname LIKE 'trg\_erd\_mirror\_%'
              AND (t.tgtype & 1) = 0
        SQL);

        $this->assertSame(
            [],
            $statementLevel,
            'Mirror ERD của stock_documents/stock_document_lines phải là FOR EACH ROW; '.
            'FOR EACH STATEMENT nghĩa là mỗi câu ghi lại quét cả bảng mirror.'
        );
    }

    /**
     * Bằng chứng "không quét cả bảng": ghi vào phiếu A không được chạm tới dòng
     * mirror của phiếu B. Dùng system column ctid — mọi lần UPDATE/INSERT lại
     * một dòng đều đổi ctid, nên ctid của B giữ nguyên là chứng cứ trực tiếp.
     */
    public function test_writing_one_document_does_not_rewrite_other_documents_mirror_rows(): void
    {
        $this->requirePostgres();

        $documentA = $this->makeReceiptWithLine('PN-MIRRORSCOPE-A');
        $documentB = $this->makeReceiptWithLine('PN-MIRRORSCOPE-B');

        $mirrorCtidBefore = $this->mirrorCtid($documentB['line_id']);
        $this->assertNotNull($mirrorCtidBefore, 'Dòng của phiếu B phải có mặt trong mirror inbound_lines.');

        DB::table('stock_documents')->where('id', $documentA['document_id'])->update([
            'version' => 2,
            'updated_at' => now(),
        ]);
        DB::table('stock_document_lines')->where('id', $documentA['line_id'])->update([
            'scanned_qty' => 1,
            'updated_at' => now(),
        ]);

        $this->assertSame(
            $mirrorCtidBefore,
            $this->mirrorCtid($documentB['line_id']),
            'Ghi vào phiếu A đã viết lại dòng mirror của phiếu B — mirror đang quét cả bảng.'
        );

        // Và phiếu A vẫn được đồng bộ đúng.
        $this->assertSame(
            1,
            (int) DB::table('inbound_lines')->where('id', $documentA['line_id'])->value('scanned_qty')
        );
    }

    /** Mirror phải khớp projection sau mỗi bước của luồng ghi phiếu nhập. */
    public function test_inbound_lines_mirror_tracks_projection_through_the_write_flow(): void
    {
        $this->requirePostgres();

        $document = $this->makeReceiptWithLine('PN-MIRRORSCOPE-FLOW');
        $this->assertInboundLinesMatchProjection('sau khi tạo phiếu + dòng');

        DB::table('stock_document_lines')->where('id', $document['line_id'])->update(['scanned_qty' => 2]);
        $this->assertInboundLinesMatchProjection('sau khi cập nhật dòng');

        // updated_by nằm trên PHIẾU nhưng chảy vào projection của DÒNG.
        DB::table('stock_documents')->where('id', $document['document_id'])->update([
            'updated_by' => $document['actor_id'],
            'version' => 3,
        ]);
        $this->assertInboundLinesMatchProjection('sau khi cập nhật phiếu');

        // PATCH cấu trúc dòng: xoá hết rồi chèn lại id mới, trùng (phiếu, line_no).
        DB::table('stock_document_lines')->where('stock_document_id', $document['document_id'])->delete();
        $this->assertInboundLinesMatchProjection('sau khi xoá hết dòng');

        DB::table('stock_document_lines')->insert([
            'id' => (string) Str::uuid(),
            'stock_document_id' => $document['document_id'],
            'line_no' => 1,
            'product_id' => $document['sku_id'],
            'qty_planned' => 4,
            'qty_actual' => 4,
            'scanned_qty' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertInboundLinesMatchProjection('sau khi chèn lại dòng trùng (phiếu, line_no)');
    }

    private function assertInboundLinesMatchProjection(string $step): void
    {
        $drift = DB::selectOne(<<<'SQL'
            SELECT count(*) AS drift FROM (
                SELECT id FROM inbound_lines
                EXCEPT SELECT id FROM inbound_lines__legacy_projection
                UNION ALL
                SELECT id FROM inbound_lines__legacy_projection
                EXCEPT SELECT id FROM inbound_lines
            ) x
        SQL);

        $this->assertSame(0, (int) $drift->drift, "inbound_lines lệch projection {$step}.");
    }

    private function mirrorCtid(string $lineId): ?string
    {
        $row = DB::selectOne('SELECT ctid::text AS ctid FROM inbound_lines WHERE id = ?', [$lineId]);

        return $row?->ctid;
    }

    /** @return array{document_id: string, line_id: string, sku_id: string, actor_id: string} */
    private function makeReceiptWithLine(string $docNo): array
    {
        // superAdminHeaders() dựng sẵn user SUPER_ADMIN nếu DB chưa seed, nên
        // test chạy được cả trên DB dev đầy dữ liệu lẫn DB vừa migrate:fresh.
        $this->superAdminHeaders();
        $warehouseId = (string) $this->warehouseFixture()->id;
        $skuId = (string) $this->productSkuFixture('SKU-MIRRORSCOPE-'.Str::upper(Str::random(8)))->sku_id;
        $actorId = DB::table('app_users')->orderBy('id')->value('id');

        if ($actorId === null) {
            $this->markTestSkipped('Cần ít nhất một app_user để gán created_by cho phiếu.');
        }

        $documentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();

        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => 'receipt',
            'doc_no' => $docNo.'-'.Str::upper(Str::random(6)),
            'doc_date' => now()->toDateString(),
            'status' => 'DRAFT',
            'dst_warehouse_id' => $warehouseId,
            'source_name' => 'mirror scope test',
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_document_lines')->insert([
            'id' => $lineId,
            'stock_document_id' => $documentId,
            'line_no' => 1,
            'product_id' => $skuId,
            'qty_planned' => 3,
            'qty_actual' => 3,
            'scanned_qty' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'document_id' => $documentId,
            'line_id' => $lineId,
            'sku_id' => (string) $skuId,
            'actor_id' => (string) $actorId,
        ];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Mirror ERD chỉ tồn tại trên PostgreSQL.');
        }
    }
}
