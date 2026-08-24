<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fail-forward repair for UAT databases that already ran the 2026-08-23 packing
 * label runtime migration but still retain legacy structured NOT NULL columns.
 */
return new class extends Migration
{
    private const LEGACY_STRUCTURED_COLUMNS = [
        'label_code',
        'customer_name',
        'customer_address',
        'product_name',
        'sku_code',
        'item_code_value',
        'outbound_no',
        'template_version',
        'payload',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('label_snapshots')) {
            return;
        }

        $this->backfillLegacyStructuredColumns();
        $this->relaxLegacyStructuredNullability();
    }

    private function backfillLegacyStructuredColumns(): void
    {
        $columns = array_values(array_filter(
            self::LEGACY_STRUCTURED_COLUMNS,
            fn (string $column): bool => Schema::hasColumn('label_snapshots', $column)
        ));

        if ($columns === []) {
            return;
        }

        $assignments = [];
        foreach ($columns as $column) {
            $assignments[] = match ($column) {
                'label_code' => "label_code = COALESCE(label_code, label_payload::jsonb->>'label_code', 'PL-' || id::text)",
                'customer_name' => "customer_name = COALESCE(customer_name, label_payload::jsonb->>'customer_name', label_payload::jsonb->>'recipient_name', '')",
                'customer_address' => "customer_address = COALESCE(customer_address, label_payload::jsonb->>'customer_address', label_payload::jsonb->>'recipient_address', '')",
                'product_name' => "product_name = COALESCE(product_name, label_payload::jsonb #>> '{sku_summary,0,sku_name}', '')",
                'sku_code' => "sku_code = COALESCE(sku_code, label_payload::jsonb #>> '{sku_summary,0,sku_code}', '')",
                'item_code_value' => "item_code_value = COALESCE(item_code_value, label_payload::jsonb->>'item_code_value', '')",
                'outbound_no' => "outbound_no = COALESCE(outbound_no, label_payload::jsonb->>'outbound_no', '')",
                'template_version' => "template_version = COALESCE(template_version, label_payload::jsonb->>'template_version', 'v1')",
                'payload' => "payload = COALESCE(payload, label_payload::jsonb, '{}'::jsonb)",
            };
        }

        DB::statement('UPDATE label_snapshots SET '.implode(', ', $assignments));
    }

    private function relaxLegacyStructuredNullability(): void
    {
        foreach (self::LEGACY_STRUCTURED_COLUMNS as $column) {
            if (Schema::hasColumn('label_snapshots', $column)) {
                DB::statement("ALTER TABLE label_snapshots ALTER COLUMN {$column} DROP NOT NULL");
            }
        }
    }

    public function down(): void
    {
        // Fail-forward only. Re-applying legacy NOT NULL constraints would bring
        // back the final-scan 500 on document-level Packing Label creation.
    }
};
