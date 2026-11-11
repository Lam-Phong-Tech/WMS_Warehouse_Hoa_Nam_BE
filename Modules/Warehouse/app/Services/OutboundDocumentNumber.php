<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Durable number reservations, not outbound documents or inventory reservations. */
final class OutboundDocumentNumber
{
    public const TABLE = 'outbound_draft_numbers';

    public const FORMAT = 'PX-YYYYMMDDHHmmss';

    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public function ready(): bool
    {
        return Schema::hasTable(self::TABLE) && Schema::hasTable('outbound_daily_numbers');
    }

    public function forDraft(string $id): ?string
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

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
        $day = $this->extractNumberDate($number);
        if ($day !== null && Schema::hasTable('outbound_daily_numbers')) {
            $this->lockDay($day);
        }
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->where('doc_no', $number)->exists()) {
            ApiError::abort(409, 'DOCUMENT_NUMBER_RESERVED', 'Số PX đã được giữ cho nháp xuất khác.');
        }
    }

    /** The enclosing business transaction retains the date lock until commit. */
    public function next(?Carbon $at = null): string
    {
        if (! $this->ready()) {
            ApiError::abort(503, 'P05_NUMBERING_SCHEMA_UNAVAILABLE', 'Nguồn giữ số PX chưa sẵn sàng.');
        }

        return DB::transaction(function () use ($at): string {
            $instant = ($at ?? now(self::TIMEZONE))->copy()->setTimezone(self::TIMEZONE)->startOfSecond();
            $day = $instant->toDateString();
            $counter = $this->lockDay($day);
            $candidate = $instant->copy();

            $lastCarbon = $this->fromStamp((int) $counter->last_value);
            if ($lastCarbon !== null && $lastCarbon->greaterThanOrEqualTo($candidate)) {
                $candidate = $lastCarbon->copy()->addSecond();
            }

            $dayPrefix = 'PX-'.$candidate->format('Ymd');
            foreach (['stock_documents', self::TABLE] as $table) {
                foreach (DB::table($table)->where('doc_no', 'like', $dayPrefix.'%')->pluck('doc_no') as $existing) {
                    if (preg_match('/^PX-([0-9]{14})$/D', (string) $existing, $m)) {
                        $existingCarbon = $this->fromStamp((int) $m[1]);
                        if ($existingCarbon !== null && $existingCarbon->greaterThanOrEqualTo($candidate)) {
                            $candidate = $existingCarbon->copy()->addSecond();
                        }
                    }
                }
            }

            while ($this->codeExists('PX-'.$candidate->format('YmdHis'))) {
                $candidate = $candidate->addSecond();
            }

            $stamp = (int) $candidate->format('YmdHis');
            DB::table('outbound_daily_numbers')->where('number_date', $day)->update(['last_value' => $stamp]);
            if ($candidate->toDateString() !== $day) {
                $rolledDay = $candidate->toDateString();
                $rolledCounter = $this->lockDay($rolledDay);
                if ((int) $rolledCounter->last_value < $stamp) {
                    DB::table('outbound_daily_numbers')->where('number_date', $rolledDay)->update(['last_value' => $stamp]);
                }
            }

            return 'PX-'.$candidate->format('YmdHis');
        }, 3);
    }

    private function extractNumberDate(string $number): ?string
    {
        if (! preg_match('/^PX-([0-9]{4})([0-9]{2})([0-9]{2})(?:[0-9]{6}|-[0-9]{4,})$/D', $number, $p)) {
            return null;
        }
        if (! checkdate((int) $p[2], (int) $p[3], (int) $p[1])) {
            return null;
        }

        return $p[1].'-'.$p[2].'-'.$p[3];
    }

    private function fromStamp(int $stamp): ?Carbon
    {
        $raw = (string) $stamp;
        if (strlen($raw) !== 14) {
            return null;
        }
        $year = (int) substr($raw, 0, 4);
        $month = (int) substr($raw, 4, 2);
        $day = (int) substr($raw, 6, 2);
        $hour = (int) substr($raw, 8, 2);
        $minute = (int) substr($raw, 10, 2);
        $second = (int) substr($raw, 12, 2);
        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return Carbon::create($year, $month, $day, $hour, $minute, $second, self::TIMEZONE);
    }

    private function codeExists(string $code): bool
    {
        return DB::table('stock_documents')->where('doc_no', $code)->exists()
            || DB::table(self::TABLE)->where('doc_no', $code)->exists();
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
