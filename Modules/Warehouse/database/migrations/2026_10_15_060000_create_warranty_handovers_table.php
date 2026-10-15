<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Support\WarrantyCasePii;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warranty_handovers')) {
            return;
        }

        $caseTable = WarrantyCasePii::baseTable();
        $caseKey = $caseTable === 'warranty_cases' ? 'id' : 'warranty_case_id';
        Schema::create('warranty_handovers', function (Blueprint $table) use ($caseTable, $caseKey): void {
            $table->uuid('warranty_case_id')->primary();
            $table->text('receiver_ciphertext');
            $table->date('handover_date');
            $table->boolean('handover_checked');
            $table->text('note_ciphertext');
            $table->uuid('recorded_by');
            $table->uuid('request_id');
            $table->timestamps();
            $table->foreign('warranty_case_id', 'fk_warranty_handover_case')
                ->references($caseKey)->on($caseTable)->cascadeOnDelete();
            $table->index('handover_date', 'idx_warranty_handovers_date');
        });
    }

    public function down(): void
    {
        // Handover proof is business evidence. Rollback must not erase it silently.
    }
};
