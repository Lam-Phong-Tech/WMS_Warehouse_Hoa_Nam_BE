<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Models\DefectCatalog;
use Tests\TestCase;

class DefectCatalogStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_def_902_is_deactivated_once_with_audit_and_cache_bump(): void
    {
        $defect = DefectCatalog::create([
            'defect_code' => 'DEF-902',
            'defect_name' => 'Lỗi bao bì (ngừng dùng)',
            'description' => 'Không còn áp dụng',
            'status' => 'ACTIVE',
        ]);
        $defectId = (string) $defect->defect_id;
        $columns = Schema::getColumnListing('defect_catalogs');
        $initialVersion = in_array('version', $columns, true)
            ? (int) DB::table('defect_catalogs')->where('defect_id', $defectId)->value('version')
            : null;

        $migration = require base_path('Modules/Warehouse/database/migrations/2026_11_12_000300_deactivate_def_902.php');
        $migration->up();

        $this->assertDatabaseHas('defect_catalogs', [
            'defect_code' => 'DEF-902',
            'status' => 'INACTIVE',
        ]);
        if ($initialVersion !== null) {
            $this->assertDatabaseHas('defect_catalogs', [
                'defect_code' => 'DEF-902',
                'version' => $initialVersion + 1,
            ]);
        }
        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'defect_catalog',
            'object_id' => $defectId,
            'action' => 'UPDATE',
            'result' => 'SUCCESS',
            'module' => 'SYSTEM',
        ]);
        $this->assertSame(1, DB::table('audit_log')->where('object_id', $defectId)->count());

        $migration->up();

        $this->assertDatabaseHas('defect_catalogs', [
            'defect_code' => 'DEF-902',
            'status' => 'INACTIVE',
        ]);
        if ($initialVersion !== null) {
            $this->assertDatabaseHas('defect_catalogs', [
                'defect_code' => 'DEF-902',
                'version' => $initialVersion + 1,
            ]);
        }
        $this->assertSame(1, DB::table('audit_log')->where('object_id', $defectId)->count());
    }
}
