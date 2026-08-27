<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERD-AMD-009 (docs/HoaNam_ERD_Moi_Nhat.md §60 document_attachments): "Chứng từ
 * nhập/xuất/hoàn trả" — ảnh chụp/scan/PDF đính kèm phiếu nhập, phiếu xuất, hoàn
 * trả/điều chỉnh để phục vụ đối soát sau này.
 *
 * document_id là khoá polymorphic trỏ tới `stock_documents.id` (document_type
 * INBOUND/OUTBOUND, phân biệt qua doc_type=receipt/issue của chính dòng đó) hoặc
 * `reversal_document.reversal_document_id` (document_type REVERSAL). Không đặt FK
 * vật lý — cùng lý do warranty_attachments không FK warranty_case_id: bảng nguồn
 * cho REVERSAL là view trên sqlite / bảng thật trên pgsql tuỳ driver (xem
 * EnforceReversalWarehouseScope::reversalWarehouseId), và INBOUND/OUTBOUND cùng
 * chia sẻ một bảng vật lý `stock_documents` nên FK đơn không biểu diễn được union
 * hai loại. Toàn vẹn tham chiếu được kiểm ở tầng controller trước khi insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §60 document_attachments giờ đã có trong ERD doc (Data Dictionary) nhưng
        // migration Schema::create này lại timestamp SAU
        // 2026_08_10_000750_materialize_erd_1_0_physical_objects (chỉ chạy trên
        // pgsql, generic hoá mọi bảng có trong ERD doc chưa tồn tại thành bảng rỗng
        // rồi ALTER thêm từng cột theo Data Dictionary — không có PK/UNIQUE/index).
        // Trên một lần `migrate:fresh` pgsql, 000750 chạy trước nên sẽ tự stub-tạo
        // bảng này trước khi migration này tới lượt; Schema::create() thẳng sẽ vỡ vì
        // bảng đã tồn tại. Bảng stub đó chưa từng có dữ liệu (hai migration chạy nối
        // tiếp trong cùng 1 lần fresh) nên drop rồi tạo lại đúng định nghĩa đầy đủ
        // (PK/UNIQUE/index) là an toàn, không mất gì.
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('document_attachments')) {
            Schema::drop('document_attachments');
        }

        Schema::create('document_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_type', 20); // INBOUND | OUTBOUND | REVERSAL
            $table->uuid('document_id');
            $table->string('attachment_type', 30)->default('SOURCE_DOCUMENT'); // SOURCE_DOCUMENT | INVOICE | DELIVERY_NOTE | PHOTO | OTHER
            $table->string('file_name', 255);
            $table->string('content_type', 100);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('storage_disk', 30)->default('local');
            $table->string('storage_key', 500)->unique();
            $table->char('checksum', 64)->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | DELETED | QUARANTINED
            $table->string('note', 500)->nullable();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->integer('version')->default(0);

            $table->index(['document_type', 'document_id', 'status'], 'idx_doc_attachments_type_id_status');
            $table->index('checksum', 'idx_doc_attachments_checksum');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('document_attachments');
    }
};
