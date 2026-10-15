<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Modules\Warehouse\Support\P08HistoryPolicy;
use Modules\Warehouse\Support\P09WarrantyPage;
use Modules\Warehouse\Support\P23ScanSessionPolicy;
use Modules\Warehouse\Support\P23ScanSessionReadModel;
use Modules\Warehouse\Support\P23WarrantyDateFilter;
use Throwable;

/** One new aggregate GET. Existing P09/P08 detail and event routes stay owners. */
final class P23ScanSessionController extends Controller
{
    public function __construct(private readonly P08HistoryController $history, private readonly P23ScanSessionReadModel $model) {}

    public function index(Request $request): JsonResponse
    {
        $allowed = ['warehouse_id', 'keyword', 'source_type', 'status', 'page', 'per_page', 'cursor', 'date_mode', 'date_field', 'date', 'from', 'to'];
        if (array_diff(array_keys($request->query()), $allowed) !== []) {
            ApiError::abort(422, 'P23_UNKNOWN_FILTER', 'Bộ lọc ngoài contract phiên quét; không dùng mine hoặc ID phiên đăng nhập để đổi phạm vi.');
        }
        $input = Validator::make($request->query(), ['warehouse_id' => ['required', 'uuid'],
            'keyword' => ['sometimes', 'string', 'max:200'], 'source_type' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', 'string', 'max:30']])->validate();
        $input['warehouse_id'] = strtolower($input['warehouse_id']);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input): JsonResponse {
            // Same fresh actor, permission, active warehouse and stable-auth checks as P08.
            $scope = $this->history->scope($request, $input);
            $name = config('app.timezone');
            try {
                if (! is_string($name) || $name === '') {
                    throw new InvalidArgumentException('Missing source timezone');
                }
                $timezone = new DateTimeZone($name);
            } catch (Throwable) {
                ApiError::abort(503, 'P23_TIMEZONE_UNAVAILABLE', 'Chưa xác nhận múi giờ của nguồn phiên quét.');
            }
            $dateInput = array_intersect_key($request->query(), array_flip(P23WarrantyDateFilter::INPUT_FIELDS));
            if (isset($dateInput['date_field']) && $dateInput['date_field'] !== 'created_at') {
                ApiError::abort(422, 'P23_DATE_FIELD_UNSUPPORTED', 'Phiên quét chỉ lọc theo created_at; không suy ra ngày bắt đầu/kết thúc.');
            }
            try {
                $window = P23WarrantyDateFilter::parse($dateInput, $timezone);
            } catch (InvalidArgumentException $exception) {
                ApiError::abort(422, $exception->getMessage(), 'Gửi ALL hoặc DATE/RANGE với date_field=created_at và ngày hợp lệ.');
            }
            [$q, $sources] = $this->model->build($scope);
            foreach (['source_type', 'status'] as $field) {
                if (! isset($input[$field]) || $input[$field] === 'ALL') {
                    continue;
                }
                foreach ($sources as $source) {
                    if ($source['status'] === 'INCLUDED' && ! ($source['fields'][$field] ?? false)) {
                        ApiError::abort(503, 'P23_FILTER_SOURCE_UNAVAILABLE', 'Nguồn chưa lưu trường được yêu cầu; không coi là không có kết quả.');
                    }
                }
                $q->where('s.'.$field, $input[$field]);
            }
            if (isset($input['keyword']) && trim($input['keyword']) !== '') {
                $pattern = P08HistoryPolicy::pattern($input['keyword']);
                $q->where(fn (Builder $search): Builder => $search
                    ->whereRaw("LOWER(s.session_id) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(s.batch_no) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(s.source_type) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
            }
            if ($window !== null && $window['mode'] !== 'ALL') {
                foreach ($sources as $source) {
                    if ($source['status'] === 'INCLUDED' && ! ($source['fields']['created_at'] ?? false)) {
                        ApiError::abort(503, 'P23_DATE_SOURCE_UNAVAILABLE', 'Nguồn chưa lưu ngày tạo phiên.');
                    }
                }
                $q->where('s.sort_time', '>=', P23ScanSessionReadModel::dateKey($window['start']))
                    ->where('s.sort_time', '<', P23ScanSessionReadModel::dateKey($window['end']));
            }
            $pageScope = $scope + ['p23Contract' => P23ScanSessionPolicy::CONTRACT, 'timezone' => $name, 'batchSources' => $sources];
            [$ids, $page] = P09WarrantyPage::read($request, clone $q, $pageScope, 's.session_id', 's.sort_time');
            $rows = (clone $q)->whereIn('s.session_id', $ids)->get()->keyBy('session_id');
            $asOf = DB::getDriverName() === 'pgsql' ? now()->utc()->format('Y-m-d\TH:i:s.uP') : now()->setTimezone($name)->format('Y-m-d H:i:s.u');
            $counts = $this->model->counts($scope, $ids, $asOf, $sources);
            $latest = $this->model->latestEvents($scope, $ids, $asOf, $sources);
            $data = array_map(fn (string $id): array => $this->model->serializeWithMetadata($rows[$id], $latest[$id] ?? []) + ['counts' => $counts[$id]], $ids);
            $batchComplete = ! in_array('UNAVAILABLE', array_column($sources, 'status'), true)
                && ! in_array('UNAVAILABLE', array_column($sources, 'eventStatus'), true);
            $page = array_replace($page, ['availableTotal' => $page['total'], 'total' => $batchComplete ? $page['total'] : null]);

            return response()->json(['success' => true, 'data' => $data, 'meta' => $page + [
                'contract' => P23ScanSessionPolicy::CONTRACT, 'countUnit' => 'SCAN_SESSION',
                'warehouseId' => $scope['warehouse_id'], 'batchSources' => $sources,
                'coverage' => $batchComplete ? 'SUPPORTED_PERSISTED_BATCHES' : 'PARTIAL_SOURCE',
                'scopePolicy' => 'PERSISTED_BATCH_WAREHOUSE_BEFORE_DEDUP_FILTER_TOTAL',
                'tenantIsolation' => $scope['dimensions'] === [] ? 'SINGLE_TENANT_DATABASE_REQUIRED' : 'TRUSTED_ACTOR_WAREHOUSE_AND_SOURCE_COLUMNS',
                'searchFields' => ['scan_batch_id', 'batch_no', 'source_type'],
                'dateMode' => $window['mode'] ?? 'ALL', 'dateField' => 'created_at', 'timezone' => $name,
                'dateNullPolicy' => ($window['mode'] ?? 'ALL') === 'ALL' ? 'INCLUDED' : 'EXCLUDED_WITHOUT_FALLBACK',
                'sort' => 'CREATED_AT_DESC_THEN_BATCH_UUID_DESC_NULL_TIME_LAST',
                'snapshot' => $snapshot, 'metricsAsOf' => $asOf, 'retentionStatus' => 'NOT_PUBLISHED',
                'releaseStatus' => 'SOURCE_IMPLEMENTED_PENDING_BA_AND_LIVE_TEST',
                'request_id' => $request->attributes->get('request_id'), 'timestamp' => now()->toIso8601String(),
            ]])->header('Cache-Control', 'private, no-store');
        });
    }
}
