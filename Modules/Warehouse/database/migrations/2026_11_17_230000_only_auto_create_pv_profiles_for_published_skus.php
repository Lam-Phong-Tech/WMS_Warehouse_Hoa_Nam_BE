<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_GUARD = 'IF NEW.published_to_app IS NOT NULL THEN';

    private const NEW_GUARD = 'IF NEW.published_to_app IS NOT NULL AND (NEW.published_to_app OR EXISTS (SELECT 1 FROM pv_product_profiles WHERE sku_id = v_sku_id)) THEN';

    public function up(): void
    {
        $this->replaceProfileGuard(self::OLD_GUARD, self::NEW_GUARD);
    }

    public function down(): void
    {
        $this->replaceProfileGuard(self::NEW_GUARD, self::OLD_GUARD);
    }

    private function replaceProfileGuard(string $from, string $to): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite already creates profiles only for published SKU inserts.
            return;
        }

        // Patch both compatibility writers in place: preserve their current SKU
        // columns, stock thresholds and family/variant handling without dropping views.
        foreach (['hn_legacy_sku_write()', 'hn_legacy_products_write()'] as $function) {
            $definition = DB::selectOne('SELECT pg_get_functiondef(to_regprocedure(?)) AS definition', [$function])?->definition;
            if (! is_string($definition) || substr_count($definition, $from) !== 1) {
                throw new RuntimeException("Unexpected PV profile guard in {$function}; migration aborted.");
            }

            // false updates an existing profile but must not create a hidden one.
            DB::unprepared(str_replace($from, $to, $definition));
        }
    }
};
