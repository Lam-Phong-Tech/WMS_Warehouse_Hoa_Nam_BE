<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Modules\Warehouse\Support\P12MixedDocumentPage as Page;
use Modules\Warehouse\Support\P12MixedDocumentReadModel as ReadModel;
use Modules\Warehouse\Support\P12MixedDocumentScope as Scope;

final class P12MixedDocumentsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        [$filters, $cursor] = ReadModel::input($request);
        try {
            return P06ReadSnapshot::run(function (array $snapshot) use ($request, $filters, $cursor): JsonResponse {
                $scope = Scope::resolve($request, $filters['warehouse_id']);
                if ($filters['source'] !== 'ALL' && ! $scope['allowed'][$filters['source']]) {
                    ApiError::abort(403, 'DOCUMENTS_SOURCE_DENIED', 'Không có quyền đọc nguồn đã chọn.');
                }
                $state = Page::state($cursor, Scope::fingerprint($scope, $filters));
                $union = ReadModel::union($scope, $filters, $state['upper_bound']);
                $facets = ReadModel::facets($union, $scope);
                $selected = clone $union;
                if ($filters['source'] !== 'ALL') {
                    $selected->where('source', $filters['source']);
                }
                $total = $filters['source'] === 'ALL'
                    ? array_sum(array_column($facets, 'total')) : $facets[$filters['source']]['total'];
                [$rows, $page] = Page::read($selected, $state, $filters['per_page'], $filters['sort']);

                return response()->json([
                    'success' => true,
                    'data' => $rows->map(ReadModel::record(...))->all(),
                    'meta' => [
                        'contract' => ReadModel::CONTRACT, 'warehouse_id' => $scope['warehouse_id'],
                        'source' => $filters['source'], 'total' => $total, 'count_unit' => 'DOCUMENT_OR_WARRANTY_CASE',
                        'per_page' => $filters['per_page'], ...$page,
                        'pagination_mode' => 'KEYSET', 'cursor_ttl_seconds' => 900,
                        'cursor_expires_at' => CarbonImmutable::createFromTimestampUTC($state['expires'])->toISOString(),
                        'upper_bound_created_at' => CarbonImmutable::parse($state['upper_bound'], config('app.timezone', 'UTC'))->utc()->toISOString(),
                        'snapshot' => $snapshot, 'cross_request_snapshot' => false,
                        'consistency' => 'LIVE_KEYSET_WITH_CREATED_AT_UPPER_BOUND',
                        'source_facets' => $facets,
                        'facet_scope' => 'AUTHORIZED_FILTERED_BEFORE_SOURCE_SELECTION_AND_CURSOR',
                        'coverage' => in_array(false, $scope['allowed'], true) ? 'AUTHORIZED_SOURCES_ONLY' : 'ALL_SOURCES',
                        'source_failure_policy' => 'FAIL_CLOSED_NO_PARTIAL_DATA_OR_TOTAL',
                        'date_field' => 'created_at', 'timezone' => $filters['timezone'],
                        'sort' => $filters['sort'], 'null_created_at' => 'LAST_UNLESS_EXCLUDED_BY_DATE_FILTER',
                        'search_fields' => [
                            'INBOUND' => ['doc_no', 'source_name'],
                            'OUTBOUND' => ['doc_no', 'recipient_name'],
                            'WARRANTY' => ['warranty_case_code', 'temporary_item_code', 'serial_number'],
                        ],
                        'warranty_customer_search' => 'NOT_SUPPORTED',
                        'quantity_semantics' => 'NOT_PROJECTED_USE_OWNER_DETAIL_AND_P12_SCAN_SUMMARY',
                        'status_filter_keys' => ReadModel::statusKeys(),
                        'write_operations' => [],
                    ],
                ]);
            });
        } catch (QueryException) {
            // Catch outside the transaction: a failed owner query cannot leak partial counts or SQL/PII.
            ApiError::abort(503, 'DOCUMENTS_SOURCE_UNAVAILABLE', 'Nguồn chứng từ tạm thời chưa khả dụng; không trả tổng một phần.');
        }
    }
}
