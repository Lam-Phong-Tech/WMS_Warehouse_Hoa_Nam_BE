<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Receipt numbers are reservations, never stock documents or stock movements. */
final class InboundDocumentNumber
{
    public function forDraft(string $draftId): ?string
    {
        return DB::table('inbound_draft_numbers')->where('draft_id', $draftId)->value('doc_no');
    }

    public function reserve(string $draftId): string
    {
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
            DB::table('inbound_draft_numbers')->insert([
                'draft_id' => $draftId, 'doc_no' => $number, 'assigned_at' => now(),
            ]);

            return $number;
        }, 3);
    }

    /** Caller retains the receipt transaction and this day lock until commit. */
    public function guardExplicit(string $number): void
    {
        if (! preg_match('/^PN-([0-9]{4})([0-9]{2})([0-9]{2})-[0-9]{4,}$/D', $number, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return;
        }
        $this->lockDay($parts[1].'-'.$parts[2].'-'.$parts[3]);
        if (DB::table('inbound_draft_numbers')->where('doc_no', $number)->exists()) {
            ApiError::abort(409, 'DOCUMENT_NUMBER_RESERVED', 'Số phiếu đã được giữ cho một nháp nhập. Tiếp tục đúng nháp hoặc dùng số khác.');
        }
    }

    public function next(): string
    {
        return DB::transaction(function (): string {
            $instant = now('Asia/Ho_Chi_Minh');
            $day = $instant->toDateString();
            $prefix = 'PN-'.$instant->format('Ymd').'-';
            $counter = $this->lockDay($day);
            $highest = (int) $counter->last_value;
            // COUNT+1 would reuse gaps. Include both Web records and earlier
            // reservations when recovering an older installed P04 extension.
            foreach (['stock_documents', 'inbound_draft_numbers'] as $table) {
                foreach (DB::table($table)->where('doc_no', 'like', $prefix.'%')->pluck('doc_no') as $number) {
                    if (preg_match('/^'.preg_quote($prefix, '/').'([0-9]+)$/D', (string) $number, $match)) {
                        if (strlen(ltrim($match[1], '0')) > 18) {
                            throw new RuntimeException('STT phiếu nhập vượt giới hạn bộ cấp số.');
                        }
                        $highest = max($highest, (int) $match[1]);
                    }
                }
            }
            if ($highest >= PHP_INT_MAX - 1) {
                throw new RuntimeException('Bộ cấp số phiếu nhập đã hết phạm vi.');
            }
            $next = $highest + 1;
            DB::table('inbound_daily_numbers')->where('number_date', $day)->update(['last_value' => $next]);

            return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        }, 3);
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
