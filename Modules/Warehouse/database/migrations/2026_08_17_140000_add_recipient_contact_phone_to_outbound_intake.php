<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS/FRD v1.12 §5.2 ("parse/nhập số toa-ngày-người nhận ... recipient_address/
 * recipient_contact_phone nullable") + Annex D SCR-OUT-01 (receiver_contact_phone
 * thuộc OutboundRequestImportPreview/ConfirmRequest) + wireframe OUT-REQ-01 ô
 * "SĐT liên hệ".
 *
 * ERD bảng 37/39 chưa có cột SĐT — SRS §15 "Data Dictionary precedence" thắng,
 * amendment ghi tại docs/decision-records/DR_HN_20260817_OUTBOUND_FLOW03_ALIGNMENT.md.
 * Cột nullable nên không phá parity ERD (test chỉ soi field đã tài liệu hoá).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outbound_requests') && ! Schema::hasColumn('outbound_requests', 'customer_phone')) {
            Schema::table('outbound_requests', function (Blueprint $table): void {
                $table->string('customer_phone', 50)->nullable();
            });
        }

        if (Schema::hasTable('stock_documents') && ! Schema::hasColumn('stock_documents', 'recipient_contact_phone')) {
            Schema::table('stock_documents', function (Blueprint $table): void {
                $table->string('recipient_contact_phone', 50)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('outbound_requests', 'customer_phone')) {
            Schema::table('outbound_requests', function (Blueprint $table): void {
                $table->dropColumn('customer_phone');
            });
        }
        if (Schema::hasColumn('stock_documents', 'recipient_contact_phone')) {
            Schema::table('stock_documents', function (Blueprint $table): void {
                $table->dropColumn('recipient_contact_phone');
            });
        }
    }
};
