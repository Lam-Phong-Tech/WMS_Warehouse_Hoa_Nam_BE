<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Snapshot tồn kho cuối kỳ — tối ưu tính tồn đầu kỳ cho báo cáo xuất-nhập-tồn. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_report_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('sku', 'sku_id')->restrictOnDelete();
            $table->date('snapshot_date');
            $table->string('period_type', 20)->default('daily')->comment('daily | weekly | monthly');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('opening_qty', 18, 3)->default(0);
            $table->decimal('receipt_qty', 18, 3)->default(0);
            $table->decimal('issue_qty', 18, 3)->default(0);
            $table->decimal('transfer_in_qty', 18, 3)->default(0);
            $table->decimal('transfer_out_qty', 18, 3)->default(0);
            $table->decimal('adjustment_in_qty', 18, 3)->default(0);
            $table->decimal('adjustment_out_qty', 18, 3)->default(0);
            $table->decimal('closing_qty', 18, 3)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['warehouse_id', 'product_id', 'period_type', 'period_start', 'period_end'], 'uk_irs_wh_product_period');
            $table->index('snapshot_date', 'idx_irs_snapshot_date');
            $table->index(['period_type', 'period_start', 'period_end'], 'idx_irs_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_report_snapshots');
    }
};
