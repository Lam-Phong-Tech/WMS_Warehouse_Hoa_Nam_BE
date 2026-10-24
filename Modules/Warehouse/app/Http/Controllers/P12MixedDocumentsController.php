<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Modules\Warehouse\Support\P12MixedDocumentMetadata as Metadata;
use Modules\Warehouse\Support\P12MixedDocumentPage as Page;
use Modules\Warehouse\Support\P12MixedDocumentQuery as Query;
use Modules\Warehouse\Support\P12MixedDocumentReadModel as ReadModel;
use Modules\Warehouse\Support\P12MixedDocumentScope as Scope;
use Modules\Warehouse\Support\P13WebPostHandoff;

final class P12MixedDocumentsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        [$filters, $cursor] = ReadModel::input($request);
        try {
            return P06ReadSnapshot::run(function (array $snapshot) use ($request, $filters, $cursor): JsonResponse {
                $scope = Scope::resolve($request, $filters['warehouse_id']);
                $filters['warehouse_id'] = $scope['warehouse_id'];
                if ($filters['source'] !== 'ALL' && ! $scope['allowed'][$filters['source']]) {
                    ApiError::abort(403, 'DOCUMENTS_SOURCE_DENIED', 'Không có quyền đọc nguồn đã chọn.');
                }
                if ($filters['status'] !== 'ALL') {
                    [$statusSource] = explode(':', $filters['status'], 2);
                    if (! $scope['allowed'][$statusSource]) {
                        ApiError::abort(403, 'DOCUMENTS_SOURCE_DENIED', 'Không có quyền đọc nguồn của trạng thái đã chọn.');
                    }
                }
                $state = Page::state($cursor, Scope::fingerprint($scope, $filters));
                Query::validateDateWindow($filters, $state);
                $union = ReadModel::union($scope, $filters, $state['upper_bound']);
                $facets = ReadModel::facets($union, $scope);
                $selected = clone $union;
                if ($filters['source'] !== 'ALL') {
                    $selected->where('source', $filters['source']);
                }
                [$rows, $page] = Page::read($selected, $state, $filters['per_page'], $filters['sort']);
                $readiness = ReadModel::readiness($rows);
                $handoffs = [];
                $handoffAvailable = Schema::hasTable(P13WebPostHandoff::TABLE);
                if ($handoffAvailable) {
                    foreach (['INBOUND', 'OUTBOUND'] as $source) {
                        $ids = $rows->where('source', $source)->pluck('warehouse_id', 'id')->all();
                        if ($ids !== []) {
                            foreach (P13WebPostHandoff::project($ids, $source) as $id => $handoff) {
                                $handoffs[$source.':'.$id] = $handoff;
                            }
                        }
                    }
                }

                return response()->json([
                    'success' => true,
                    'data' => $rows->map(fn (object $row): array => ReadModel::record(
                        $row, $readiness[$row->source.':'.$row->id] ?? [], $handoffs[$row->source.':'.$row->id] ?? null,
                        $handoffAvailable ? 'NOT_ESTABLISHED' : 'SOURCE_UNAVAILABLE'
                    ))->all(),
                    'meta' => Metadata::make($scope, $filters, $state, $facets, $page, $snapshot),
                ]);
            });
        } catch (QueryException) {
            ApiError::abort(503, 'DOCUMENTS_SOURCE_UNAVAILABLE', 'Nguồn chứng từ tạm thời chưa khả dụng; không trả tổng một phần.');
        }
    }
}
