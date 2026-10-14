<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** B12 query contract. No stock, warranty or receipt mutation. */
final class P12MixedDocumentQuery
{
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public static function input(Request $request): array
    {
        $known = ['warehouse_id', 'source', 'q', 'keyword', 'status', 'date_from', 'date_to', 'timezone', 'sort', 'per_page', 'cursor'];
        if (array_diff(array_keys($request->query()), $known) !== []) {
            ApiError::abort(422, 'DOCUMENTS_QUERY_UNSUPPORTED', 'Tham số không thuộc contract danh sách trộn.');
        }
        $input = Validator::make($request->query(), [
            'warehouse_id' => ['sometimes', 'required', 'uuid'],
            'source' => ['sometimes', Rule::in(['ALL', ...P12MixedDocumentReadModel::SOURCES])],
            'q' => ['sometimes', 'nullable', 'string', 'max:600'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:600'],
            'status' => ['sometimes', Rule::in(['ALL', ...P12MixedDocumentReadModel::statusKeys()])],
            'date_from' => ['required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'timezone' => ['sometimes', Rule::in([self::TIMEZONE])],
            'sort' => ['sometimes', Rule::in(['created_at_desc', 'created_at_asc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'required', 'string', 'max:4096'],
        ])->validate();
        $q = P12MixedDocumentSearch::normalize($input['q'] ?? $input['keyword'] ?? '');
        if (array_key_exists('q', $input) && array_key_exists('keyword', $input)
            && P12MixedDocumentSearch::normalize($input['q'] ?? '') !== P12MixedDocumentSearch::normalize($input['keyword'] ?? '')) {
            ApiError::abort(422, 'DOCUMENTS_SEARCH_CONFLICT', 'Không gửi q và keyword khác nhau.');
        }
        if (mb_strlen($q, 'UTF-8') > 200) {
            ApiError::abort(422, 'DOCUMENTS_SEARCH_TOO_LONG', 'Nội dung tìm kiếm tối đa 200 ký tự sau chuẩn hóa.');
        }
        $filters = [
            'warehouse_id' => isset($input['warehouse_id']) ? strtolower($input['warehouse_id']) : null,
            'source' => $input['source'] ?? 'ALL',
            // One normalized binding for q and the earlier candidate's keyword alias.
            'keyword' => $q,
            'status' => $input['status'] ?? 'ALL',
            'date_from' => $input['date_from'] ?? null,
            'date_to' => $input['date_to'] ?? null,
            'timezone' => self::TIMEZONE,
            'sort' => $input['sort'] ?? 'created_at_desc',
            'per_page' => (int) ($input['per_page'] ?? 20),
        ];
        if ($filters['status'] !== 'ALL' && $filters['source'] !== 'ALL'
            && ! str_starts_with($filters['status'], $filters['source'].':')) {
            ApiError::abort(422, 'DOCUMENTS_STATUS_SOURCE_MISMATCH', 'Trạng thái không thuộc nguồn đã chọn.');
        }

        return [$filters, $input['cursor'] ?? 'start'];
    }

    public static function dateWindow(int $issuedAt): array
    {
        $today = CarbonImmutable::createFromTimestampUTC($issuedAt)->setTimezone(self::TIMEZONE)->startOfDay();

        return [
            'min' => $today->subDays(90)->toDateString(), 'max' => $today->toDateString(),
            'timezone' => self::TIMEZONE, 'anchor' => 'CURSOR_FIRST_REQUEST_VIETNAM_DATE',
            'all_dates_unbounded_below' => true, 'end_bound' => 'NEXT_LOCAL_DAY_EXCLUSIVE',
        ];
    }

    public static function validateDateWindow(array $filters, array $state): void
    {
        if ($filters['date_from'] === null) {
            return;
        }
        $window = self::dateWindow($state['issued_at']);
        if ($filters['date_from'] < $window['min'] || $filters['date_to'] > $window['max']) {
            ApiError::abort(422, 'DOCUMENTS_DATE_OUT_OF_WINDOW', 'Khoảng chọn ngày phải nằm từ hôm nay trừ 90 ngày đến hôm nay theo giờ Việt Nam.');
        }
    }

    public static function statusOptions(array $allowed): array
    {
        $options = [['value' => 'ALL', 'label' => 'Tất cả', 'source' => null, 'source_status' => null,
            'label_availability' => 'APPROVED', 'display_label' => 'Tất cả']];
        foreach (P12MixedDocumentReadModel::statusKeys() as $key) {
            [$source, $status] = explode(':', $key, 2);
            if (! $allowed[$source]) {
                continue;
            }
            $options[] = [
                'value' => $key, 'label' => P12MixedDocumentStatus::canonicalLabel($source, $status),
                'source' => $source, 'source_status' => $status,
                'label_availability' => 'APPROVED',
                'display_label' => P12MixedDocumentStatus::canonicalLabel($source, $status),
                'status_source' => ($source === 'WARRANTY' ? 'warranty_case' : 'stock_documents').'.status',
            ];
        }

        return $options;
    }
}
