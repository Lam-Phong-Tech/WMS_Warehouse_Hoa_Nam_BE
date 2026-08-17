<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SRS v1.12 §15 (Data Dictionary precedence): "Persisted Outbound_Document.receiver_name
 * is NOT NULL after Confirm/Post; receiver_address and receiver_contact_phone are
 * nullable." Migration 2026_08_16_125000 mới nới NOT NULL trên PostgreSQL nên driver
 * sqlite (test) vẫn 500 khi toa không có địa chỉ — nới nốt để hai driver cùng ngữ nghĩa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // Chỉ rebuild bảng toa: `stock_documents` có view phụ thuộc
        // (inbound_documents/outbound_documents) nên rebuild kiểu sqlite sẽ vỡ view;
        // cột recipient_address ở đó đã nullable từ đầu.
        foreach (['customer_address', 'recipient_address'] as $column) {
            if (Schema::hasTable('outbound_requests') && Schema::hasColumn('outbound_requests', $column)) {
                Schema::table('outbound_requests', function (Blueprint $table) use ($column): void {
                    $table->text($column)->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        // Fail-forward: requiredness theo SRS v1.12 §15.
    }
};
