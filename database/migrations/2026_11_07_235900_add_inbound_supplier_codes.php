<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE inbound_suppliers IN SHARE ROW EXCLUSIVE MODE');
            }
            if (! Schema::hasColumn('inbound_suppliers', 'code')) {
                Schema::table('inbound_suppliers', function (Blueprint $table): void {
                    $table->string('code', 32)->nullable();
                });
            }
            if (! Schema::hasIndex('inbound_suppliers', 'inbound_suppliers_code_unique')) {
                Schema::table('inbound_suppliers', function (Blueprint $table): void {
                    $table->unique('code', 'inbound_suppliers_code_unique');
                });
            }
            $codes = DB::table('inbound_suppliers')->whereNotNull('code')->pluck('code')->all();
            $last = 0;
            foreach ($codes as $code) {
                if (preg_match('/^NCC-([0-9]+)$/D', $code, $match) === 1) {
                    $number = filter_var(ltrim($match[1], '0') ?: '0', FILTER_VALIDATE_INT);
                    if ($number === false || $number === PHP_INT_MAX) {
                        throw new RuntimeException('SUPPLIER_CODE_NAMESPACE_EXHAUSTED');
                    }
                    $last = max($last, $number);
                }
            }
            $rows = DB::table('inbound_suppliers')->whereNull('code')->orderBy('name')->orderBy('id')->get(['id']);
            foreach ($rows as $row) {
                do {
                    if ($last === PHP_INT_MAX) {
                        throw new RuntimeException('SUPPLIER_CODE_NAMESPACE_EXHAUSTED');
                    }
                    $code = 'NCC-'.str_pad((string) ++$last, 3, '0', STR_PAD_LEFT);
                } while (in_array($code, $codes, true));
                // Initialize only the new identity field, not the old entity
                // version/timestamps or immutable RECORD snapshots.
                DB::table('inbound_suppliers')->where('id', $row->id)->whereNull('code')->update(['code' => $code]);
                $codes[] = $code;
            }
        });
    }

    public function down(): void
    {
        if (DB::table('inbound_suppliers')->whereNotNull('code')->exists()) {
            throw new RuntimeException('Assigned supplier identities must be preserved; roll back code only, not supplier data.');
        }
        Schema::table('inbound_suppliers', function (Blueprint $table): void {
            $table->dropUnique('inbound_suppliers_code_unique');
            $table->dropColumn('code');
        });
    }
};
