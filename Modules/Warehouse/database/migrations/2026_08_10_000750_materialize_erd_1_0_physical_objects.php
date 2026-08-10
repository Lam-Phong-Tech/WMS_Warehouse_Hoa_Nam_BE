<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            throw new \RuntimeException('ERD physical materialization incomplete: '.$nonPhysical->implode(', '));
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
            throw new \RuntimeException("Unsupported PostgreSQL relation kind {$kind} for ERD object {$table}.");
        }

        $projection = substr($table.'__legacy_projection', 0, 63);
        if (HoaNamErdSchema::relationKind($projection) !== null) {
            throw new \RuntimeException("Cannot materialize {$table}: helper relation {$projection} already exists.");
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

        if ($table === 'idempotency_records') {
            DB::statement('CREATE TABLE idempotency_records AS SELECT '.
                $this->legacyUuidExpression('id').' AS id, '.
                "COALESCE(operation, 'LEGACY') AS scope, ".
                'idempotency_key::varchar(200) AS idempotency_key, '.
                "CASE WHEN request_hash::text ~ '^v2:[a-f0-9]{64}' THEN substring(request_hash::text from 4 for 64) ELSE substring(request_hash::text from 1 for 64) END::char(64) AS request_hash, ".
                'response_code::integer AS response_status, '.
                'response_body::jsonb AS response_body, '.
                'operation::varchar(80) AS resource_type, '.
                'NULL::uuid AS resource_id, '.
                'locked_until AS expires_at, '.
                'created_at, '.
                'user_id, operation, business_key, status, response_code, locked_until, completed_at, updated_at '.
                'FROM idempotency_records__legacy_projection');

            return;
        }

        DB::statement(
            'CREATE TABLE '.HoaNamErdSchema::quote($table).' AS SELECT * FROM '.HoaNamErdSchema::quote($projection)
        );
    }

    private function legacyUuidExpression(string $sqlExpression): string
    {
        $hash = "md5('hoa-nam-legacy-id:' || {$sqlExpression}::text)";

        return "((substr({$hash}, 1, 8) || '-' || substr({$hash}, 9, 4) || '-' || substr({$hash}, 13, 4) || '-' || substr({$hash}, 17, 4) || '-' || substr({$hash}, 21, 12))::uuid)";
    }
};
