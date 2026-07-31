<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * State machine definition cho chứng từ kho — bảng cấu hình để tham khảo/tra cứu,
 * KHÔNG phải nguồn enforcement thực tế (transition thực tế vẫn được guard cứng trong
 * StockDocumentService, giữ nguyên như quyết định port ban đầu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_status_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('doc_type', 30)->comment('receipt | issue | transfer');
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->string('action', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['doc_type', 'from_status', 'to_status', 'action'], 'uk_doc_transition');
            $table->index(['doc_type', 'from_status'], 'idx_dst_doc_type_from_status');
        });

        $now = now();
        $rows = [
            ['receipt', 'draft', 'pending', 'submit'],
            ['receipt', 'pending', 'approved', 'approve'],
            ['receipt', 'approved', 'received', 'post_receipt'],
            ['receipt', 'draft', 'cancelled', 'cancel'],
            ['receipt', 'pending', 'cancelled', 'cancel'],
            ['receipt', 'approved', 'cancelled', 'cancel'],
            ['issue', 'draft', 'pending', 'submit'],
            ['issue', 'pending', 'approved', 'approve'],
            ['issue', 'approved', 'issued', 'post_issue'],
            ['issue', 'draft', 'cancelled', 'cancel'],
            ['issue', 'pending', 'cancelled', 'cancel'],
            ['issue', 'approved', 'cancelled', 'cancel'],
            ['transfer', 'draft', 'pending', 'submit'],
            ['transfer', 'pending', 'approved', 'approve'],
            ['transfer', 'approved', 'in_transit', 'dispatch_transfer'],
            ['transfer', 'in_transit', 'completed', 'receive_transfer'],
            ['transfer', 'in_transit', 'completed_with_loss', 'record_transfer_loss'],
            ['transfer', 'in_transit', 'return_in_transit', 'return_transfer'],
            ['transfer', 'return_in_transit', 'returned', 'confirm_return'],
            ['transfer', 'draft', 'cancelled', 'cancel'],
            ['transfer', 'pending', 'cancelled', 'cancel'],
            ['transfer', 'approved', 'cancelled', 'cancel'],
        ];

        DB::table('document_status_transitions')->insert(array_map(
            fn (array $row) => [
                'id' => (string) Str::uuid(),
                'doc_type' => $row[0],
                'from_status' => $row[1],
                'to_status' => $row[2],
                'action' => $row[3],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('document_status_transitions');
    }
};
