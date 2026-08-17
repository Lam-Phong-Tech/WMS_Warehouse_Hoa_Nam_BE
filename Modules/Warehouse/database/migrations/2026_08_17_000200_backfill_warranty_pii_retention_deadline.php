<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SRS v1.12 §13.8: PII warranty giữ 5 năm sau khi đóng case rồi anonymize/purge.
 *
 * `retention_until` trước đây không được chốt ở bất kỳ đường ghi nào, nên các case đã
 * RETURNED/CANCELLED trước bản vá này không có mốc để `warranty:purge-pii` dựa vào.
 * Migration backfill mốc đó từ closed_at (fallback returned_at/updated_at) và chỉ đụng
 * hàng đang NULL nên chạy lại nhiều lần vẫn an toàn.
 */
return new class extends Migration
{
    private const RETENTION_YEARS = 5;

    public function up(): void
    {
        if (! Schema::hasTable('warranty_case') && ! Schema::hasView('warranty_case')) {
            return;
        }

        $closed = DB::table('warranty_case')
            ->whereIn('status', ['RETURNED', 'CANCELLED'])
            ->whereNull('retention_until')
            ->get(['warranty_case_id', 'closed_at', 'returned_at', 'updated_at', 'created_at']);

        foreach ($closed as $case) {
            $anchor = $case->closed_at ?? $case->returned_at ?? $case->updated_at ?? $case->created_at;
            if ($anchor === null) {
                continue;
            }

            DB::table('warranty_case')
                ->where('warranty_case_id', $case->warranty_case_id)
                ->update([
                    'closed_at' => $case->closed_at ?? $anchor,
                    'retention_until' => \Illuminate\Support\Carbon::parse((string) $anchor)
                        ->addYears(self::RETENTION_YEARS),
                ]);
        }
    }

    public function down(): void
    {
        // Fail-forward: mốc retention là dữ liệu tuân thủ, không rollback về NULL.
    }
};
