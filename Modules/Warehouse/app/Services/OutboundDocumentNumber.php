<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Durable number reservations, not outbound documents or inventory reservations. */
final class OutboundDocumentNumber
{
    public const TABLE = 'outbound_draft_numbers';

    public function ready(): bool
    {
        return Schema::hasTable(self::TABLE) && Schema::hasTable('outbound_daily_numbers');
    }

    public function forDraft(string $id): ?string
    {
        return DB::table(self::TABLE)->where('draft_id', $id)->value('doc_no');
    }

    public function reserve(string $id): string
    {
        return DB::transaction(function () use ($id): string {
            $draft = DB::table(MiniAppDraftService::TABLE)->where('document_id', $id)->lockForUpdate()->first();
            if ($draft === null || $draft->operation !== 'OUTBOUND' || $draft->status !== 'DRAFT' || $draft->business_document_id !== null) {
                ApiError::abort(409, 'P05_NUMBER_IDENTITY_MISMATCH', 'Chỉ cấp số cho đúng nháp xuất chưa ghi nhận.');
            }
            if ($number = $this->forDraft($id)) {
                return $number;
            }
            $number = $this->next();
            DB::table(self::TABLE)->insert(['draft_id' => $id, 'doc_no' => $number,
                'assigned_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')]);

            return $number;
        }, 3);
    }

    public function assertForRecord(string $id, string $number, string $actor, string $warehouse): void
    {
        $row = DB::table(self::TABLE.' as n')->join(MiniAppDraftService::TABLE.' as d', 'd.document_id', '=', 'n.draft_id')
            ->where('n.draft_id', $id)->where('n.doc_no', $number)->where('d.actor_id', $actor)
            ->where('d.warehouse_id', $warehouse)->where('d.operation', 'OUTBOUND')->where('d.status', 'DRAFT')
            ->whereNull('d.business_document_id')->exists();
        if (! $row) {
            ApiError::abort(409, 'P05_NUMBER_IDENTITY_MISMATCH', 'Số PX không khớp nháp, owner hoặc kho đã xác nhận.');
        }
    }

    /** Must be called inside the canonical create/update transaction. */
    public function guardExplicit(string $number): void
    {
        if (! preg_match('/^PX-([0-9]{4})([0-9]{2})([0-9]{2})-[0-9]{4,}$/D', $number, $p)
            || ! checkdate((int) $p[2], (int) $p[3], (int) $p[1])) {
            return;
        }
        $this->lockDay($p[1].'-'.$p[2].'-'.$p[3]);
        if (DB::table(self::TABLE)->where('doc_no', $number)->exists()) {
            ApiError::abort(409, 'DOCUMENT_NUMBER_RESERVED', 'Số PX đã được giữ cho nháp xuất khác.');
        }
    }

    /** The enclosing business transaction retains the date lock until commit. */
    public function next(): string
    {
        if (! $this->ready()) {
            ApiError::abort(503, 'P05_NUMBERING_SCHEMA_UNAVAILABLE', 'Nguồn giữ số PX chưa sẵn sàng.');
        }

        return DB::transaction(function (): string {
            $day = now('Asia/Ho_Chi_Minh');
            $prefix = 'PX-'.$day->format('Ymd').'-';
            $counter = $this->lockDay($day->toDateString());
            $highest = (int) $counter->last_value;
            foreach (['stock_documents', self::TABLE] as $table) {
                foreach (DB::table($table)->where('doc_no', 'like', $prefix.'%')->pluck('doc_no') as $number) {
                    if (preg_match('/^'.preg_quote($prefix, '/').'([0-9]+)$/D', (string) $number, $m)) {
                        if (strlen(ltrim($m[1], '0')) > 18) {
                            throw new RuntimeException('Outbound sequence exceeds the supported range.');
                        }
                        $highest = max($highest, (int) $m[1]);
                    }
                }
            }
            if ($highest >= PHP_INT_MAX - 1) {
                throw new RuntimeException('Outbound sequence exhausted.');
            }
            $next = $highest + 1;
            DB::table('outbound_daily_numbers')->where('number_date', $day->toDateString())->update(['last_value' => $next]);

            return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        }, 3);
    }

    private function lockDay(string $day): object
    {
        DB::table('outbound_daily_numbers')->insertOrIgnore(['number_date' => $day, 'last_value' => 0]);
        $counter = DB::table('outbound_daily_numbers')->where('number_date', $day)->lockForUpdate()->first();
        if ($counter === null) {
            throw new RuntimeException('Outbound sequence lock unavailable.');
        }

        return $counter;
    }
}
