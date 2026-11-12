<?php

use App\Support\Cache\CacheVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AuditLog\Services\AuditLogger;

/**
 * DEF-902 is retired master data. The seeder has declared it INACTIVE since
 * 2026-09-04, but seeders are not a production data migration. This migration
 * applies the correction to every environment without touching historical
 * warranty_case.defect_id references.
 */
return new class extends Migration
{
    private const CODE = 'DEF-902';

    public function up(): void
    {
        if (! Schema::hasTable('defect_catalogs')) {
            return;
        }

        $columns = Schema::getColumnListing('defect_catalogs');
        $select = array_values(array_intersect(['defect_id', 'id', 'status', 'version'], $columns));
        if ($select === []) {
            return;
        }

        $defect = DB::table('defect_catalogs')->where('defect_code', self::CODE)->first($select);
        if ($defect === null || ($defect->status ?? null) === 'INACTIVE') {
            return;
        }

        $update = ['status' => 'INACTIVE'];
        if (in_array('updated_at', $columns, true)) {
            $update['updated_at'] = now();
        }
        if (in_array('version', $columns, true)) {
            $update['version'] = DB::raw('COALESCE(version, 0) + 1');
        }
        DB::table('defect_catalogs')->where('defect_code', self::CODE)->update($update);

        $objectId = (string) ($defect->defect_id ?? $defect->id ?? '');
        if ($objectId !== '') {
            $before = ['defect_code' => self::CODE, 'status' => (string) ($defect->status ?? 'ACTIVE')];
            $after = ['defect_code' => self::CODE, 'status' => 'INACTIVE'];
            if (in_array('version', $columns, true)) {
                $before['version'] = (int) ($defect->version ?? 0);
                $after['version'] = $before['version'] + 1;
            }

            AuditLogger::record(
                action: 'UPDATE',
                objectType: 'defect_catalog',
                objectId: $objectId,
                actorId: null,
                requestId: 'MIGRATION_2026_11_12_000300',
                beforeData: $before,
                afterData: $after,
                reason: 'Data migration: retire DEF-902 from the active defect dropdown.',
                result: 'SUCCESS',
                module: 'SYSTEM',
            );
        }

        CacheVersion::bump('warehouse.defect_catalog:list');
        CacheVersion::bump('stock:version');
    }

    public function down(): void
    {
        // Fail-forward: historical receipts and audit entries remain authoritative.
    }
};
