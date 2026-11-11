<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Receipt numbers are reservations, never stock documents or stock movements. */
final class InboundDocumentNumber
{
    public const TABLE = 'inbound_draft_numbers';

    public const FORMAT = 'PN-YYYYMMDDHHmmss';

    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public function ready(): bool
    {
        return Schema::hasTable(self::TABLE) && Schema::hasTable('inbound_daily_numbers');
    }

    public function forDraft(string $draftId): ?string
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        return DB::table(self::TABLE)->where('draft_id', $draftId)->value('doc_no');
    }

    public function reserve(string $draftId): string
    {
        if (! $this->ready()) {
            ApiError::abort(503, 'DOCUMENT_NUMBERING_SCHEMA_UNAVAILABLE', 'Nguồn giữ số PN chưa sẵn sàng.');
        }

        return DB::transaction(function () use ($draftId): string {
            $draft = DB::table('mini_app_drafts')->where('document_id', $draftId)->lockForUpdate()->first();
            if ($draft === null || $draft->operation !== 'INBOUND') {
                ApiError::abort(409, 'P04_NUMBER_IDENTITY_MISMATCH', 'Chưa xác minh đúng nháp nhập để cấp số.');
            }
            if ($number = $this->forDraft($draftId)) {
                return $number;
            }
            if ($draft->business_document_id !== null) {
                $number = DB::table('stock_documents')->where('id', $draft->business_document_id)->value('doc_no');
                if (is_string($number) && $number !== '') {
                    return $number; // Historical SAVE replay must not renumber a record.
                }
            }
            if ($draft->status !== 'DRAFT' || $draft->business_document_id !== null) {
                ApiError::abort(409, 'DRAFT_STATE_CONFLICT', 'Không cấp số mới cho nháp đã kết thúc.');
            }
            $number = $this->next();
            DB::table(self::TABLE)->insert([
                'draft_id' => $draftId, 'doc_no' => $number, 'assigned_at' => now(),
            ]);

            return $number;
        }, 3);
    }

    /** Caller retains the receipt transaction and this day lock until commit. */
    public function guardExplicit(string $number): void
    {
        $day = $this->extractNumberDate($number);
        if ($day !== null && Schema::hasTable('inbound_daily_numbers')) {
            $this->lockDay($day);
        }
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->where('doc_no', $number)->exists()) {
            ApiError::abort(409, 'DOCUMENT_NUMBER_RESERVED', 'Số phiếu đã được giữ cho một nháp nhập. Tiếp tục đúng nháp hoặc dùng số khác.');
        }
    }

    public function next(?Carbon $at = null): string
    {
        if (! $this->ready()) {
            ApiError::abort(503, 'DOCUMENT_NUMBERING_SCHEMA_UNAVAILABLE', 'Nguồn cấp số phiếu nhập chưa sẵn sàng.');
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

            $dayPrefix = 'PN-'.$candidate->format('Ymd');
            foreach (['stock_documents', self::TABLE] as $table) {
                foreach (DB::table($table)->where('doc_no', 'like', $dayPrefix.'%')->pluck('doc_no') as $existing) {
                    if (preg_match('/^PN-([0-9]{14})$/D', (string) $existing, $match)) {
                        $existingCarbon = $this->fromStamp((int) $match[1]);
                        if ($existingCarbon !== null && $existingCarbon->greaterThanOrEqualTo($candidate)) {
                            $candidate = $existingCarbon->copy()->addSecond();
                        }
                    }
                }
            }

            while ($this->codeExists('PN-'.$candidate->format('YmdHis'))) {
                $candidate = $candidate->addSecond();
            }

            $stamp = (int) $candidate->format('YmdHis');
            DB::table('inbound_daily_numbers')->where('number_date', $day)->update(['last_value' => $stamp]);
            if ($candidate->toDateString() !== $day) {
                $rolledDay = $candidate->toDateString();
                $rolledCounter = $this->lockDay($rolledDay);
                if ((int) $rolledCounter->last_value < $stamp) {
                    DB::table('inbound_daily_numbers')->where('number_date', $rolledDay)->update(['last_value' => $stamp]);
                }
            }

            return 'PN-'.$candidate->format('YmdHis');
        }, 3);
    }

    private function extractNumberDate(string $number): ?string
    {
        if (! preg_match('/^PN-([0-9]{4})([0-9]{2})([0-9]{2})(?:[0-9]{6}|-[0-9]{4,})$/D', $number, $parts)) {
            return null;
        }
        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $parts[1].'-'.$parts[2].'-'.$parts[3];
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
        DB::table('inbound_daily_numbers')->insertOrIgnore(['number_date' => $day, 'last_value' => 0]);
        $counter = DB::table('inbound_daily_numbers')->where('number_date', $day)->lockForUpdate()->first();
        if ($counter === null) {
            throw new RuntimeException('Không khóa được bộ cấp số phiếu nhập.');
        }

        return $counter;
    }
}
