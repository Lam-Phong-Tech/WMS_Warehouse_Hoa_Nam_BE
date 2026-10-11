<?php

namespace Tests\Support;

final class P23WarrantyDateCases
{
    public static function valid(): array
    {
        $cases = [
            'legacy-no-filter' => [[], 'Asia/Ho_Chi_Minh', null],
            'unrelated-query-only' => [['search' => 'CASE-01', 'per_page' => '1'], 'Asia/Ho_Chi_Minh', null],
            'explicit-all' => [['date_mode' => 'ALL'], 'Asia/Ho_Chi_Minh', ['mode' => 'ALL', 'field' => null]],
        ];
        foreach (['created_at', 'received_at', 'returned_at'] as $field) {
            $cases['date-'.$field] = [
                ['date_mode' => 'DATE', 'date_field' => $field, 'date' => '2026-10-10'],
                'Asia/Ho_Chi_Minh', ['mode' => 'DATE', 'field' => $field,
                    'start' => '2026-10-10T00:00:00+07:00', 'end' => '2026-10-11T00:00:00+07:00'],
            ];
        }
        $cases['range-month-boundary'] = [
            ['date_mode' => 'RANGE', 'date_field' => 'received_at', 'from' => '2026-09-30', 'to' => '2026-10-01'],
            'Asia/Ho_Chi_Minh', ['mode' => 'RANGE', 'field' => 'received_at',
                'start' => '2026-09-30T00:00:00+07:00', 'end' => '2026-10-02T00:00:00+07:00'],
        ];
        $cases['same-day-range'] = [
            ['date_mode' => 'RANGE', 'date_field' => 'created_at', 'from' => '2026-10-10', 'to' => '2026-10-10'],
            'Asia/Ho_Chi_Minh', ['mode' => 'RANGE', 'field' => 'created_at',
                'start' => '2026-10-10T00:00:00+07:00', 'end' => '2026-10-11T00:00:00+07:00'],
        ];
        $cases['leap-day'] = [
            ['date_mode' => 'DATE', 'date_field' => 'returned_at', 'date' => '2024-02-29'],
            'UTC', ['mode' => 'DATE', 'field' => 'returned_at',
                'start' => '2024-02-29T00:00:00+00:00', 'end' => '2024-03-01T00:00:00+00:00'],
        ];
        $cases['year-boundary'] = [
            ['date_mode' => 'DATE', 'date_field' => 'created_at', 'date' => '2026-12-31'],
            'UTC', ['mode' => 'DATE', 'field' => 'created_at',
                'start' => '2026-12-31T00:00:00+00:00', 'end' => '2027-01-01T00:00:00+00:00'],
        ];
        $cases['daylight-saving-short-day'] = [
            ['date_mode' => 'DATE', 'date_field' => 'created_at', 'date' => '2026-03-08'],
            'America/New_York', ['mode' => 'DATE', 'field' => 'created_at',
                'start' => '2026-03-08T00:00:00-05:00', 'end' => '2026-03-09T00:00:00-04:00'],
        ];
        $cases['daylight-saving-long-day'] = [
            ['date_mode' => 'DATE', 'date_field' => 'created_at', 'date' => '2026-11-01'],
            'America/New_York', ['mode' => 'DATE', 'field' => 'created_at',
                'start' => '2026-11-01T00:00:00-04:00', 'end' => '2026-11-02T00:00:00-05:00'],
        ];

        return $cases;
    }

    public static function invalid(): array
    {
        $date = ['date_mode' => 'DATE', 'date_field' => 'created_at', 'date' => '2026-10-10'];
        $range = ['date_mode' => 'RANGE', 'date_field' => 'received_at', 'from' => '2026-10-10', 'to' => '2026-10-11'];
        return [
            'missing-mode' => [['date' => '2026-10-10'], 'WARRANTY_INVALID_DATE_FILTER'],
            'missing-field' => [['date_mode' => 'DATE', 'date' => '2026-10-10'], 'WARRANTY_DATE_FIELD_REQUIRED'],
            'unknown-field' => [array_replace($date, ['date_field' => 'closed_at']), 'WARRANTY_DATE_FIELD_REQUIRED'],
            'injected-field' => [array_replace($date, ['date_field' => 'created_at) OR 1=1 --']), 'WARRANTY_DATE_FIELD_REQUIRED'],
            'unknown-mode' => [array_replace($date, ['date_mode' => 'TODAY']), 'WARRANTY_INVALID_DATE_FILTER'],
            'date-plus-from' => [$date + ['from' => '2026-10-10'], 'WARRANTY_INVALID_DATE_FILTER'],
            'date-plus-to' => [$date + ['to' => '2026-10-10'], 'WARRANTY_INVALID_DATE_FILTER'],
            'range-plus-date' => [$range + ['date' => '2026-10-10'], 'WARRANTY_INVALID_DATE_FILTER'],
            'range-missing-to' => [['date_mode' => 'RANGE', 'date_field' => 'received_at', 'from' => '2026-10-10'], 'WARRANTY_INVALID_DATE_FILTER'],
            'date-missing-date' => [['date_mode' => 'DATE', 'date_field' => 'created_at'], 'WARRANTY_INVALID_DATE_FILTER'],
            'all-plus-date' => [['date_mode' => 'ALL', 'date' => '2026-10-10'], 'WARRANTY_INVALID_DATE_FILTER'],
            'all-plus-field' => [['date_mode' => 'ALL', 'date_field' => 'created_at'], 'WARRANTY_INVALID_DATE_FILTER'],
            'reversed-range' => [array_replace($range, ['from' => '2026-10-12']), 'WARRANTY_INVALID_DATE_RANGE'],
            'invalid-leap-day' => [array_replace($date, ['date' => '2026-02-29']), 'WARRANTY_INVALID_DATE_FILTER'],
            'invalid-month' => [array_replace($date, ['date' => '2026-13-01']), 'WARRANTY_INVALID_DATE_FILTER'],
            'invalid-month-day' => [array_replace($date, ['date' => '2026-04-31']), 'WARRANTY_INVALID_DATE_FILTER'],
            'year-zero' => [array_replace($date, ['date' => '0000-01-01']), 'WARRANTY_INVALID_DATE_FILTER'],
            'non-padded-date' => [array_replace($date, ['date' => '2026-1-1']), 'WARRANTY_INVALID_DATE_FILTER'],
            'timestamp-not-day' => [array_replace($date, ['date' => '2026-10-10T00:00:00Z']), 'WARRANTY_INVALID_DATE_FILTER'],
            'relative-date' => [array_replace($date, ['date' => 'today']), 'WARRANTY_INVALID_DATE_FILTER'],
            'empty-date' => [array_replace($date, ['date' => '']), 'WARRANTY_INVALID_DATE_FILTER'],
            'null-date' => [array_replace($date, ['date' => null]), 'WARRANTY_INVALID_DATE_FILTER'],
            'array-date' => [array_replace($date, ['date' => ['2026-10-10']]), 'WARRANTY_INVALID_DATE_FILTER'],
            'null-mode' => [['date_mode' => null], 'WARRANTY_INVALID_DATE_FILTER'],
            'integer-mode' => [['date_mode' => 1], 'WARRANTY_INVALID_DATE_FILTER'],
            'whitespace-date' => [array_replace($date, ['date' => ' 2026-10-10']), 'WARRANTY_INVALID_DATE_FILTER'],
            'null-byte-date' => [array_replace($date, ['date' => "2026-10-10\0"]), 'WARRANTY_INVALID_DATE_FILTER'],
            'unsupported-upper-bound' => [array_replace($date, ['date' => '9999-12-31']), 'WARRANTY_DATE_BOUNDARY_OUT_OF_RANGE'],
        ];
    }
}
