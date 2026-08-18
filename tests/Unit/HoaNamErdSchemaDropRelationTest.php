<?php

namespace Tests\Unit;

use App\Support\HoaNamErdSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WWHN-300 — regression guard for the GoLive rollback bricking defect.
 *
 * The migration chain contains fail-forward steps that turn a name from a
 * table into a view (and back), so a down() that hardcodes DROP VIEW aborts
 * migrate:rollback with SQLSTATE 42809 and leaves a schema that can neither
 * serve traffic nor be re-migrated forward.
 */
class HoaNamErdSchemaDropRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_drop_relation_removes_a_physical_table(): void
    {
        DB::statement('CREATE TABLE wwhn300_probe_table (id integer)');
        $this->assertTrue(Schema::hasTable('wwhn300_probe_table'));

        HoaNamErdSchema::dropRelation('wwhn300_probe_table');

        $this->assertFalse(Schema::hasTable('wwhn300_probe_table'));
    }

    public function test_drop_relation_removes_a_view_sharing_the_same_call_site(): void
    {
        DB::statement('CREATE TABLE wwhn300_probe_source (id integer)');
        DB::statement('CREATE VIEW wwhn300_probe_view AS SELECT id FROM wwhn300_probe_source');

        // Same helper, opposite relation kind — this is exactly the case the
        // hardcoded DROP VIEW / DROP TABLE call sites got wrong.
        HoaNamErdSchema::dropRelation('wwhn300_probe_view');
        HoaNamErdSchema::dropRelation('wwhn300_probe_source');

        $this->assertFalse(Schema::hasTable('wwhn300_probe_source'));
    }

    public function test_drop_relation_is_a_no_op_for_an_absent_name(): void
    {
        HoaNamErdSchema::dropRelation('wwhn300_never_created');

        $this->assertFalse(Schema::hasTable('wwhn300_never_created'));
    }

    public function test_drop_index_if_exists_tolerates_an_index_a_later_migration_replaced(): void
    {
        DB::statement('CREATE TABLE wwhn300_probe_indexed (id integer)');
        DB::statement('CREATE INDEX wwhn300_probe_idx ON wwhn300_probe_indexed (id)');

        HoaNamErdSchema::dropIndexIfExists('wwhn300_probe_indexed', 'wwhn300_probe_idx');
        // Second call is the rollback-after-replacement case that raised 42704.
        HoaNamErdSchema::dropIndexIfExists('wwhn300_probe_indexed', 'wwhn300_probe_idx');

        HoaNamErdSchema::dropRelation('wwhn300_probe_indexed');

        $this->assertFalse(Schema::hasTable('wwhn300_probe_indexed'));
    }
}
