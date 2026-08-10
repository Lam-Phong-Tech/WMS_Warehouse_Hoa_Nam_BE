<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scan_event') || ! Schema::hasTable('physical_codes')) {
            return;
        }

        $this->migrateReferencedLegacyCodes();

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $this->dropForeignKeysForColumn('scan_event', 'physical_code_id');
        $this->assertNoOrphanScanCodes();

        Schema::table('scan_event', function (Blueprint $table): void {
            $table->foreign('physical_code_id', 'fk_scan_event_physical_code_canonical')
                ->references('physical_code_id')
                ->on('physical_codes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Canonical physical-code evidence is retained intentionally.
    }

    private function migrateReferencedLegacyCodes(): void
    {
        if (! Schema::hasTable('physical_code')) {
            return;
        }

        $referencedIds = DB::table('scan_event')
            ->whereNotNull('physical_code_id')
            ->distinct()
            ->pluck('physical_code_id');

        foreach ($referencedIds as $legacyId) {
            if (DB::table('physical_codes')->where('physical_code_id', $legacyId)->exists()) {
                continue;
            }

            $legacy = DB::table('physical_code')->where('physical_code_id', $legacyId)->first();
            if ($legacy === null) {
                DB::table('scan_event')->where('physical_code_id', $legacyId)->update(['physical_code_id' => null]);

                continue;
            }

            $canonicalByValue = DB::table('physical_codes')->where('code_value', $legacy->code_value)->first();
            if ($canonicalByValue !== null) {
                DB::table('scan_event')
                    ->where('physical_code_id', $legacyId)
                    ->update(['physical_code_id' => $canonicalByValue->physical_code_id]);

                continue;
            }

            $carrier = $this->canonicalCarrier((string) ($legacy->code_type ?? $legacy->carrier_type ?? 'MANUAL'));
            DB::table('physical_codes')->insert([
                'physical_code_id' => (string) $legacy->physical_code_id,
                'code_value' => strtoupper(trim((string) $legacy->code_value)),
                'code_type' => 'UNKNOWN',
                'carrier_type' => $carrier,
                'hardware_uid' => $carrier === 'RFID' ? ($legacy->hardware_uid ?? null) : null,
                'assigned_object_type' => null,
                'assigned_object_id' => null,
                'encoded_payload_version' => null,
                'status' => in_array(strtoupper((string) $legacy->status), ['ACTIVE', 'INACTIVE'], true)
                    ? strtoupper((string) $legacy->status)
                    : 'INACTIVE',
                'replaced_by_code_id' => null,
                'created_at' => $legacy->created_at ?? now(),
                'updated_at' => $legacy->updated_at ?? now(),
            ]);
        }
    }

    private function canonicalCarrier(string $legacy): string
    {
        $legacy = strtoupper($legacy);

        return match ($legacy) {
            'BARCODE' => 'BARCODE',
            'QR' => 'QR',
            'RFID' => 'RFID',
            'NFC' => 'NFC',
            default => 'MANUAL',
        };
    }

    private function assertNoOrphanScanCodes(): void
    {
        $orphan = DB::table('scan_event as s')
            ->leftJoin('physical_codes as p', 'p.physical_code_id', '=', 's.physical_code_id')
            ->whereNotNull('s.physical_code_id')
            ->whereNull('p.physical_code_id')
            ->exists();

        if ($orphan) {
            throw new \RuntimeException('scan_event contains physical_code_id values that cannot be reconciled to canonical physical_codes.');
        }
    }

    private function dropForeignKeysForColumn(string $table, string $column): void
    {
        try {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if (($foreign['columns'] ?? []) === [$column] && isset($foreign['name'])) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign['name']));
                }
            }
        } catch (\Throwable) {
            // Driver/version without FK introspection: the next add operation is fail-closed.
        }
    }
};
