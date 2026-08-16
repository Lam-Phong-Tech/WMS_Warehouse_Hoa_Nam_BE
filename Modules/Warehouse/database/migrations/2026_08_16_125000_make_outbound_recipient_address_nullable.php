<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SRS v1.12 §15 (Data Dictionary precedence) + REQ-SRS-014 + SRC-TOA-SAMPLE-2026:
 * receiver_name NOT NULL sau Confirm; receiver_address/receiver_contact_phone
 * NULLABLE — toa mẫu của khách hàng (SHA-256 0c0862cd...) không có địa chỉ/SĐT
 * và vẫn phải import/confirm được. Nới NOT NULL của address trên outbound_requests
 * (customer_address/recipient_address); recipient_name giữ NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['customer_address', 'recipient_address'] as $column) {
            if (Schema::hasColumn('outbound_requests', $column)) {
                DB::statement("ALTER TABLE outbound_requests ALTER COLUMN {$column} DROP NOT NULL");
            }
        }
        if (Schema::hasColumn('outbound_documents', 'recipient_address')) {
            DB::statement('ALTER TABLE outbound_documents ALTER COLUMN recipient_address DROP NOT NULL');
        }
    }

    public function down(): void
    {
        // Fail-forward: requiredness theo SRS v1.12 §15.
    }
};
