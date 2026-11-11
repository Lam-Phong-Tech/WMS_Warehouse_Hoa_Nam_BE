<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class WarrantyCaseNumber
{
    public const FORMAT = 'BH-YYYYMMDDHHmmss';

    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public function ready(): bool
    {
        return Schema::hasTable('warranty_daily_numbers') && Schema::hasTable('warranty_case');
    }

    public function next(?Carbon $at = null): string
    {
        if (! $this->ready()) {
            ApiError::abort(503, 'DOCUMENT_NUMBERING_SCHEMA_UNAVAILABLE', 'Nguồn cấp số BH chưa sẵn sàng.');
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

            $dayPrefix = 'BH-'.$candidate->format('Ymd');
            foreach (DB::table('warranty_case')->where('warranty_case_code', 'like', $dayPrefix.'%')->pluck('warranty_case_code') as $existing) {
                if (preg_match('/^BH-([0-9]{14})$/D', (string) $existing, $m)) {
                    $existingCarbon = $this->fromStamp((int) $m[1]);
                    if ($existingCarbon !== null && $existingCarbon->greaterThanOrEqualTo($candidate)) {
                        $candidate = $existingCarbon->copy()->addSecond();
                    }
                }
            }

            while (DB::table('warranty_case')->where('warranty_case_code', 'BH-'.$candidate->format('YmdHis'))->exists()) {
                $candidate = $candidate->addSecond();
            }

            $stamp = (int) $candidate->format('YmdHis');
            DB::table('warranty_daily_numbers')->where('number_date', $day)->update(['last_value' => $stamp]);
            if ($candidate->toDateString() !== $day) {
                $rolledDay = $candidate->toDateString();
                $rolledCounter = $this->lockDay($rolledDay);
                if ((int) $rolledCounter->last_value < $stamp) {
                    DB::table('warranty_daily_numbers')->where('number_date', $rolledDay)->update(['last_value' => $stamp]);
                }
            }

            return 'BH-'.$candidate->format('YmdHis');
        }, 3);
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

    private function lockDay(string $day): object
    {
        DB::table('warranty_daily_numbers')->insertOrIgnore(['number_date' => $day, 'last_value' => 0]);
        $counter = DB::table('warranty_daily_numbers')->where('number_date', $day)->lockForUpdate()->first();
        if ($counter === null) {
            throw new RuntimeException('Không khóa được bộ cấp số BH.');
        }

        return $counter;
    }
}
