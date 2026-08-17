<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERD bảng 32 (reconciliation_incidents) mô tả divergence bằng các cột rời:
 * item_id, detected_at, ledger_qty, balance_qty, subledger_qty, difference_qty,
 * severity, resolution_type/resolution_note, created_by/updated_by/version.
 *
 * Trên PostgreSQL 2026_08_10_000750 đã materialize đủ cột từ chính Data Dictionary.
 * Trên sqlite bảng vật lý vẫn là `reconciliation_incident` (số ít) với hình dạng cũ và
 * view `reconciliation_incidents` chỉ chiếu 12 cột, nên §7.5 "Divergence → tạo
 * Reconciliation_Incident" chỉ lưu được số liệu trong JSON `detected_value`.
 *
 * Migration này đưa hai driver về cùng một tập cột đọc được, giữ `detected_value` để
 * không phá client cũ.
 */
return new class extends Migration
{
    /** @var array<string, string> ERD field => Blueprint method */
    private const SQLITE_COLUMNS = [
        'item_id' => 'uuid',
        'detected_at' => 'timestamp',
        'ledger_qty' => 'bigInteger',
        'balance_qty' => 'bigInteger',
        'subledger_qty' => 'bigInteger',
        'difference_qty' => 'bigInteger',
        'severity' => 'severity',
        'resolution_type' => 'resolutionType',
        'resolution_note' => 'text',
        'created_by' => 'uuid',
        'updated_by' => 'uuid',
        'updated_at' => 'timestamp',
        'version' => 'version',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        if (! Schema::hasTable('reconciliation_incident')) {
            return;
        }

        Schema::table('reconciliation_incident', function (Blueprint $table): void {
            foreach (self::SQLITE_COLUMNS as $column => $kind) {
                if (Schema::hasColumn('reconciliation_incident', $column)) {
                    continue;
                }

                match ($kind) {
                    'uuid' => $table->uuid($column)->nullable(),
                    'timestamp' => $table->timestamp($column)->nullable(),
                    'bigInteger' => $table->bigInteger($column)->nullable(),
                    'text' => $table->text($column)->nullable(),
                    'severity' => $table->string($column, 20)->nullable(),
                    'resolutionType' => $table->string($column, 50)->nullable(),
                    'version' => $table->integer($column)->default(0),
                };
            }
        });

        DB::statement("UPDATE reconciliation_incident SET detected_at = COALESCE(detected_at, created_at), severity = COALESCE(severity, 'ERROR'), resolution_type = COALESCE(resolution_type, resolution_ref), resolution_note = COALESCE(resolution_note, reason), updated_at = COALESCE(updated_at, created_at)");

        DB::statement('DROP VIEW IF EXISTS reconciliation_incidents');
        DB::statement(<<<'SQL'
            CREATE VIEW reconciliation_incidents AS
            SELECT incident_id AS id, incident_no, divergence_type,
                   warehouse_id, product_id AS sku_id, item_id,
                   detected_value, detected_at,
                   ledger_qty, balance_qty, subledger_qty, difference_qty,
                   severity, status,
                   resolution_ref, resolution_type, resolution_note, reason,
                   resolved_at, resolved_by,
                   created_at, created_by, updated_at, updated_by, version
              FROM reconciliation_incident
        SQL);
    }

    public function down(): void
    {
        // Fail-forward: chỉ mở rộng tập cột theo Data Dictionary đã ký.
    }
};
