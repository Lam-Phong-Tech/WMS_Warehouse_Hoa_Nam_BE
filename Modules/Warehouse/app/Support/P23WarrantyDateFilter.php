<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * P23-01 source implementation of the existing P09 case list, not a new route.
 * A date field is always explicit. Nothing here infers an event or writes data.
 */
final class P23WarrantyDateFilter
{
    public const INPUT_FIELDS = ['date_mode', 'date_field', 'date', 'from', 'to'];

    public const SOURCE_FIELDS = ['created_at', 'received_at', 'returned_at'];

    /** Pure input parsing; missing dates are not replaced with today. */
    public static function parse(array $query, DateTimeZone $timezone): ?array
    {
        $input = array_intersect_key($query, array_flip(self::INPUT_FIELDS));
        if ($input === []) {
            return null;
        }
        foreach ($input as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException('WARRANTY_INVALID_DATE_FILTER');
            }
        }
        $mode = $input['date_mode'] ?? null;
        if (! in_array($mode, ['ALL', 'DATE', 'RANGE'], true)) {
            throw new InvalidArgumentException('WARRANTY_INVALID_DATE_FILTER');
        }
        if ($mode === 'ALL') {
            if (count($input) !== 1) {
                throw new InvalidArgumentException('WARRANTY_INVALID_DATE_FILTER');
            }

            return ['mode' => 'ALL', 'field' => null, 'from' => null, 'to' => null,
                'start' => null, 'end' => null, 'timezone' => $timezone->getName()];
        }
        if (! in_array($input['date_field'] ?? null, self::SOURCE_FIELDS, true)) {
            throw new InvalidArgumentException('WARRANTY_DATE_FIELD_REQUIRED');
        }
        $expected = $mode === 'DATE'
            ? ['date_mode', 'date_field', 'date']
            : ['date_mode', 'date_field', 'from', 'to'];
        if (array_diff($expected, array_keys($input)) !== []
            || array_diff(array_keys($input), $expected) !== []) {
            throw new InvalidArgumentException('WARRANTY_INVALID_DATE_FILTER');
        }
        $from = $mode === 'DATE' ? $input['date'] : $input['from'];
        $to = $mode === 'DATE' ? $input['date'] : $input['to'];
        $start = self::day($from, $timezone);
        $last = self::day($to, $timezone);
        if ($start > $last) {
            throw new InvalidArgumentException('WARRANTY_INVALID_DATE_RANGE');
        }
        // Calendar day, not a fixed 86400 seconds; retains timezone boundaries.
        $end = $last->modify('+1 day');
        if (strlen($end->format('Y')) !== 4) {
            throw new InvalidArgumentException('WARRANTY_DATE_BOUNDARY_OUT_OF_RANGE');
        }

        return ['mode' => $mode, 'field' => $input['date_field'], 'from' => $from, 'to' => $to,
            'start' => $start, 'end' => $end, 'timezone' => $timezone->getName()];
    }

    private static function day(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)
            || substr($value, 0, 4) === '0000') {
            throw new InvalidArgumentException('WARRANTY_INVALID_DATE_FILTER');
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($day === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $day->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('WARRANTY_INVALID_DATE_FILTER');
        }

        return $day;
    }

    /** Bind strings, not DateTime instances that a driver could strip offsets from. */
    public static function bounds(array $window, string $storage): array
    {
        if (! ($window['start'] ?? null) instanceof DateTimeImmutable
            || ! ($window['end'] ?? null) instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('WARRANTY_DATE_WINDOW_REQUIRED');
        }

        return match ($storage) {
            'TIMESTAMP_WITH_TIME_ZONE' => [
                $window['start']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
                $window['end']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
            ],
            // Midnight without fractional digits includes SQLite rows stored with
            // either whole seconds or fractional seconds at the lower boundary.
            'APP_LOCAL_WALL_CLOCK' => [$window['start']->format('Y-m-d H:i:s'), $window['end']->format('Y-m-d H:i:s')],
            default => throw new InvalidArgumentException('WARRANTY_DATE_STORAGE_UNSUPPORTED'),
        };
    }

    /** Called only after Scope::resolve/cases, BEFORE count and pagination. */
    public static function apply(Request $request, Builder $query): array
    {
        if (array_intersect(array_keys($request->query()), self::INPUT_FIELDS) === []) {
            return []; // Preserve the existing P09 response and cursor scope.
        }
        $name = config('app.timezone');
        if (! is_string($name) || $name === '') {
            ApiError::abort(503, 'WARRANTY_TIMEZONE_UNAVAILABLE', 'Chưa xác nhận múi giờ của nguồn bảo hành.');
        }
        try {
            $timezone = new DateTimeZone($name);
        } catch (Throwable) {
            ApiError::abort(503, 'WARRANTY_TIMEZONE_UNAVAILABLE', 'Chưa xác nhận múi giờ của nguồn bảo hành.');
        }
        try {
            $window = self::parse($request->query(), $timezone);
        } catch (InvalidArgumentException $exception) {
            ApiError::abort(422, $exception->getMessage(), 'Chọn rõ trường ngày và ngày/khoảng ngày hợp lệ; không gửi lẫn DATE, RANGE và ALL.');
        }
        $meta = ['date_filter_contract' => 'p23-case-dates-v2', 'date_mode' => $window['mode'],
            'date_field' => $window['field'], 'date_from' => $window['from'], 'date_to' => $window['to'],
            'date_timezone' => $name];
        if ($window['mode'] === 'ALL') {
            return $meta + ['date_source' => null, 'date_null_policy' => 'INCLUDED_WITHOUT_DATE_FILTER'];
        }
        $field = $window['field'];
        if (! Schema::hasColumn('warranty_case', $field)) {
            ApiError::abort(503, 'WARRANTY_DATE_SOURCE_UNAVAILABLE', 'Nguồn chưa có trường thời gian được yêu cầu.');
        }
        $storage = self::storageFor('warranty_case', $field);
        [$start, $end] = self::bounds($window, $storage);
        // The field is exclusively from SOURCE_FIELDS; bounds are SQL bindings.
        // NULL dates stay unknown: no COALESCE to created_at, status or now().
        $query->where('c.'.$field, '>=', $start)->where('c.'.$field, '<', $end);

        return $meta + ['date_source' => 'warranty_case.'.$field, 'date_storage' => $storage,
            'date_from_inclusive' => $window['start']->format(DATE_ATOM),
            'date_to_exclusive' => $window['end']->format(DATE_ATOM),
            'date_null_policy' => 'EXCLUDED_WITHOUT_FALLBACK'];
    }

    /** Internal table/column identifiers only, never passed through from a client. */
    public static function storageFor(string $table, string $field): string
    {
        if (DB::getDriverName() === 'sqlite') {
            // Matches the canonical now() writer and existing P08 SQLite contract.
            return 'APP_LOCAL_WALL_CLOCK';
        }
        if (DB::getDriverName() === 'pgsql') {
            // Inspect the actual relation selected by search_path, including views.
            // Do not assume that every PostgreSQL timestamp includes a timezone.
            $column = DB::selectOne('SELECT pg_catalog.format_type(a.atttypid, a.atttypmod) AS data_type '
                .'FROM pg_catalog.pg_attribute AS a '
                .'WHERE a.attrelid = pg_catalog.to_regclass(?) AND a.attname = ? '
                .'AND a.attnum > 0 AND NOT a.attisdropped', [$table, $field]);
            $type = $column->data_type ?? '';
            if (preg_match('/^timestamp(?:\([0-9]+\))? with time zone$/D', $type)) {
                return 'TIMESTAMP_WITH_TIME_ZONE';
            }
            if (preg_match('/^timestamp(?:\([0-9]+\))? without time zone$/D', $type)) {
                return 'APP_LOCAL_WALL_CLOCK';
            }
        }
        ApiError::abort(503, 'WARRANTY_DATE_SOURCE_UNAVAILABLE', 'Kiểu lưu thời gian chưa có contract đọc được xác nhận.');
    }
}
