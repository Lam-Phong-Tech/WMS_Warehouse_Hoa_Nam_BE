<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;

/**
 * WWHN-193 · DEV193-P1-05R / EXT-DEP-01R — fixture chuỗi "Posted Receipt -> BOX".
 *
 * Sau khi `POST /putaway-tasks` bắt buộc BOX phải neo vào một phiếu nhập đã POSTED, một
 * BOX được dựng tay ở trạng thái RECEIVED_PENDING_PUTAWAY không còn đủ điều kiện — và đó
 * chính là ý đồ: trạng thái không còn là bằng chứng duy nhất của việc đã nhập kho.
 *
 * Trait này dựng đúng ba bảng mà `latestPostedReceipt()` join qua
 * (`stock_documents` POSTED -> `stock_document_lines` -> `inbound_scan_entries.container_id`)
 * ở tầng DB, dùng cho các test đã có sẵn context riêng và chỉ cần điều kiện tiên quyết này.
 *
 * Bài chứng minh chuỗi phụ thuộc thật chạy qua HTTP (create -> boxes -> scan -> post-receipt)
 * nằm ở `Wwhn193PutawayDependencyChainTest`, không thay bằng fixture DB.
 */
trait PreparesPutawayFixtures
{
    private function activeWarehouseForPutaway(): Warehouse
    {
        // REQ-SRS-001 + uk_warehouses_single_active: chỉ một warehouse ACTIVE tồn tại.
        $existing = Warehouse::query()->where('status', 'ACTIVE')->first();
        if ($existing !== null) {
            return $existing;
        }

        return Warehouse::create([
            'code' => 'KHO-TONG-HN',
            'name' => 'Kho tổng Hòa Nam',
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Gắn BOX vào một phiếu nhập COMPONENT đã POSTED.
     *
     * @return array{document_id:string, line_id:string, scan_entry_id:string, warehouse_id:string, doc_no:string}
     */
    private function seedPostedReceiptForBox(string $boxId, string $skuId, int $quantity = 5, ?string $actorId = null): array
    {
        $warehouse = $this->activeWarehouseForPutaway();
        $actorId ??= $this->anyExistingUserId();

        $documentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $entryId = (string) Str::uuid();
        $docNo = 'PN-PTW-'.Str::upper(Str::random(10));
        $now = now();

        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => 'receipt',
            'doc_no' => $docNo,
            'doc_date' => $now->toDateString(),
            'status' => 'POSTED',
            'dst_warehouse_id' => $warehouse->id,
            'posted_by' => $actorId,
            'posted_at' => $now,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
            ...(Schema::hasColumn('stock_documents', 'version') ? ['version' => 3] : []),
        ]);

        DB::table('stock_document_lines')->insert([
            'id' => $lineId,
            'stock_document_id' => $documentId,
            'line_no' => 1,
            'product_id' => $skuId,
            'qty_planned' => $quantity,
            'qty_actual' => $quantity,
            'scanned_qty' => $quantity,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('inbound_scan_entries')->insert([
            'id' => $entryId,
            'inbound_line_id' => $lineId,
            'item_id' => null,
            'container_id' => $boxId,
            'quantity' => $quantity,
            'scan_source' => 'MANUAL',
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
            'version' => 1,
            ...(Schema::hasColumn('inbound_scan_entries', 'status') ? ['status' => 'ACTIVE'] : []),
        ]);

        return [
            'document_id' => $documentId,
            'line_id' => $lineId,
            'scan_entry_id' => $entryId,
            'warehouse_id' => (string) $warehouse->id,
            'doc_no' => $docNo,
        ];
    }

    /**
     * `stock_documents.created_by` là NOT NULL, nên fixture phải luôn có một actor — kể cả ở
     * các test ẩn danh (401/403) chạy trước khi bất kỳ user nào được tạo.
     */
    private function anyExistingUserId(): string
    {
        $id = auth('api')->id();
        if ($id !== null) {
            return (string) $id;
        }

        // Dùng model thay vì đoán tên bảng: trên PostgreSQL `app_users` là bảng vật lý với
        // hình dạng cột khác, còn `app_user` là view — model biết đúng table/khoá của cả hai
        // driver (xem migration retire_remaining_singular_legacy_tables).
        $value = User::query()->value('user_id');
        if ($value !== null) {
            return (string) $value;
        }

        $user = User::create([
            'username' => 'ptw-fixture-'.Str::lower(Str::random(8)),
            'display_name' => 'Putaway Fixture Actor',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        return (string) $user->user_id;
    }
}
