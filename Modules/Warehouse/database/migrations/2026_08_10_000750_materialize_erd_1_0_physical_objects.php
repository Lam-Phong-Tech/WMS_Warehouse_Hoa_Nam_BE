<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $schema = HoaNamErdSchema::parse();

        foreach ($schema as $table => $definition) {
            $this->materialize($table);

            foreach ($definition['fields'] as $field => $spec) {
                if (Schema::hasColumn($table, $field)) {
                    continue;
                }

                DB::statement(sprintf(
                    'ALTER TABLE %s ADD COLUMN %s %s',
                    HoaNamErdSchema::quote($table),
                    HoaNamErdSchema::quote($field),
                    HoaNamErdSchema::postgresType($spec['type']),
                ));
            }
        }

        $nonPhysical = collect(array_keys($schema))
            ->reject(fn (string $table): bool => HoaNamErdSchema::relationKind($table) === 'r')
            ->values();

        if ($nonPhysical->isNotEmpty()) {
            throw new RuntimeException('ERD physical materialization incomplete: '.$nonPhysical->implode(', '));
        }
    }

    public function down(): void
    {
        // Fail-forward. Reintroducing canonical compatibility views would weaken the
        // physical contract defined by HN-WMS-ERD-DD-001.
    }

    private function materialize(string $table): void
    {
        $kind = HoaNamErdSchema::relationKind($table);
        if ($kind === 'r') {
            return;
        }

        if ($kind === null) {
            DB::statement('CREATE TABLE '.HoaNamErdSchema::quote($table).' ()');
            return;
        }

        if ($kind !== 'v') {
            throw new RuntimeException("Unsupported PostgreSQL relation kind {$kind} for ERD object {$table}.");
        }

        $projection = substr($table.'__legacy_projection', 0, 63);
        if (HoaNamErdSchema::relationKind($projection) !== null) {
            throw new RuntimeException("Cannot materialize {$table}: helper relation {$projection} already exists.");
        }

        DB::statement(
            'ALTER VIEW '.HoaNamErdSchema::quote($table).' RENAME TO '.HoaNamErdSchema::quote($projection)
        );

        // The current audit_log uses UUID ids while the signed ERD requires a bigserial
        // canonical audit_logs.id. It must not be materialized with CREATE TABLE AS and
        // then unsafely cast UUID -> bigint.
        if ($table === 'audit_logs') {
            DB::statement('CREATE TABLE audit_logs (id bigserial PRIMARY KEY)');
            return;
        }

        DB::statement(
            'CREATE TABLE '.HoaNamErdSchema::quote($table).' AS SELECT * FROM '.HoaNamErdSchema::quote($projection)
        );
    }
};
