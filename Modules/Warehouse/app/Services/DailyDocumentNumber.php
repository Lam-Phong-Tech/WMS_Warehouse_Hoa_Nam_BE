<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Shared daily sequence for document prefixes outside PN/PX. */
final class DailyDocumentNumber
{
    /**
     * @param  array<string,string>  $existingSources  table => number column
     */
    public function next(string $prefix, string $counterTable, array $existingSources): string
    {
        if (! Schema::hasTable($counterTable)) {
            ApiError::abort(503, 'DOCUMENT_NUMBERING_SCHEMA_UNAVAILABLE', 'Nguồn giữ số chứng từ chưa sẵn sàng.');
        }

        return DB::transaction(function () use ($prefix, $counterTable, $existingSources): string {
            $day = now('Asia/Ho_Chi_Minh');
            $date = $day->toDateString();
            $numberPrefix = $prefix.'-'.$day->format('Ymd').'-';

            DB::table($counterTable)->insertOrIgnore(['number_date' => $date, 'last_value' => 0]);
            $counter = DB::table($counterTable)->where('number_date', $date)->lockForUpdate()->first();
            if ($counter === null) {
                throw new RuntimeException('Document sequence lock unavailable.');
            }

            $highest = (int) $counter->last_value;
            foreach ($existingSources as $table => $column) {
                foreach (DB::table($table)->where($column, 'like', $numberPrefix.'%')->pluck($column) as $number) {
                    if (preg_match('/^'.preg_quote($numberPrefix, '/').'([0-9]+)$/D', (string) $number, $match)) {
                        if (strlen(ltrim($match[1], '0')) > 18) {
                            throw new RuntimeException('Document sequence exceeds the supported range.');
                        }
                        $highest = max($highest, (int) $match[1]);
                    }
                }
            }
            if ($highest >= PHP_INT_MAX - 1) {
                throw new RuntimeException('Document sequence exhausted.');
            }

            $next = $highest + 1;
            DB::table($counterTable)->where('number_date', $date)->update(['last_value' => $next]);

            return $numberPrefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        }, 3);
    }
}
