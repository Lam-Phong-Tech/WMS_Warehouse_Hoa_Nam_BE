<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Support\P09WarrantyScope as Scope;

/** Read projection over P03/P19, not a second draft store or a mutation owner. */
final class P21ComponentResume
{
    public const HEADER = 'X-Component-Resume-Contract';

    public const CONTRACT = 'p21-component-resume-v1';

    public function index(Request $request): JsonResponse
    {
        $input = $this->input($request, [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['DRAFT', 'RECORDED', 'CANCELLED'])],
            'warranty_case_id' => ['sometimes', 'uuid'],
        ]);

        return DB::transaction(function () use ($request, $input): JsonResponse {
            $scope = $this->scope($request);
            $query = $this->linked($scope);
            if (isset($input['warranty_case_id'])) {
                $case = Scope::requireCase($scope, $input['warranty_case_id']);
                $query->where('pc.warranty_case_id', $case->warranty_case_id);
            }
            $counts = array_fill_keys(['DRAFT', 'RECORDED', 'CANCELLED'], 0);
            foreach ((clone $query)->selectRaw('d.status, COUNT(*) AS aggregate')->groupBy('d.status')->get() as $count) {
                if (array_key_exists($count->status, $counts)) {
                    $counts[$count->status] = (int) $count->aggregate;
                }
            }
            // A lost RECORD response must not hide the task. Do not merge these
            // staging tasks with canonical business DRAFT/SCANNING documents.
            $statuses = isset($input['status']) ? [$input['status']] : ['DRAFT', 'RECORDED'];
            $query->whereIn('d.status', $statuses);
            $total = (clone $query)->count();
            $page = (int) ($input['page'] ?? 1);
            $size = (int) ($input['per_page'] ?? 20);
            $rows = $query->select('d.*', 'pc.status as case_status')->orderByDesc('d.updated_at')
                ->orderByDesc('d.document_id')->forPage($page, $size)->get();

            return $this->response($scope, $rows->map(fn (object $row): array => $this->document($row, $scope))->all(), [
                'page' => $page, 'per_page' => $size, 'total' => $total,
                'last_page' => max(1, (int) ceil($total / $size)), 'has_more' => $page * $size < $total,
                'status_totals' => $counts, 'included_statuses' => $statuses,
                'sort' => ['updated_at:desc', 'document_id:desc'],
            ]);
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->uuid($id);
        $input = $this->input($request, ['scan_session_id' => ['required', 'uuid']]);

        return DB::transaction(function () use ($request, $input, $id): JsonResponse {
            $scope = $this->scope($request);
            $row = $this->find($scope, $id, $input['scan_session_id']);

            return $this->response($scope, $this->document($row, $scope))->header('ETag', '"'.$row->version.'"');
        });
    }

    public function reconcile(Request $request, string $id, string $key): JsonResponse
    {
        $this->uuid($id);
        $this->uuid($key);
        $input = $this->input($request, [
            'scan_session_id' => ['required', 'uuid'],
            'command' => ['required', Rule::in(['SAVE', 'RECORD', 'CANCEL'])],
        ]);

        return DB::transaction(function () use ($request, $input, $id, $key): JsonResponse {
            $scope = $this->scope($request);
            $base = $this->owned($scope)->where('d.document_id', strtolower($id))->first('d.*');
            $row = $base === null ? null : $this->find($scope, $id, $input['scan_session_id']);
            $permittedReceipt = Scope::isolate(DB::table(MiniAppDraftService::RECEIPTS.' as r'), MiniAppDraftService::RECEIPTS, 'r', $scope)
                ->where('r.actor_id', $scope['actor_id'])->where('r.auth_session_id', $scope['auth_session_id'])
                ->where('r.warehouse_id', $scope['warehouse_id'])->where('r.request_id', strtolower($key))->exists();
            // P03 owns command/key/scan identity and historical receipt semantics.
            $response = app(MiniAppDraftService::class)->reconcile($request, strtolower($id), strtolower($key));
            $data = $response->getData(true)['data'];
            if ($data['outcome'] === 'SUCCEEDED') {
                if (! $permittedReceipt) {
                    ApiError::abort(404, 'DRAFT_NOT_FOUND', 'Không đọc được biên nhận trong phạm vi tổ chức hiện tại.');
                }
                if ($row === null) {
                    ApiError::abort(503, 'P21_CHECKPOINT_UNAVAILABLE', 'Có biên nhận nhưng không đọc được checkpoint cùng phạm vi.');
                }
                if ($input['command'] === 'RECORD') {
                    $result = data_get($data, 'response.data.p19_result');
                    if (! is_array($result) || ($result['contract'] ?? null) !== P19LinkedComponentIssue::VERSION
                        || ($result['draft_id'] ?? null) !== $row->document_id
                        || ($result['scan_session_id'] ?? null) !== $row->scan_session_id
                        || ($result['actor_id'] ?? null) !== $scope['actor_id']
                        || ($result['auth_session_id'] ?? null) !== $scope['auth_session_id']
                        || ($result['warehouse_id'] ?? null) !== $scope['warehouse_id']
                        || ($result['business_document_id'] ?? null) !== $row->business_document_id) {
                        ApiError::abort(409, 'P21_MODE_UNSUPPORTED', 'Biên nhận không thuộc lượt P19 linked đã xác nhận.');
                    }
                    Scope::requireCase($scope, (string) ($result['warranty_case_id'] ?? ''));
                } else {
                    $form = data_get($data, 'response.data.document.form.p19');
                    if (! is_array($form) || ($form['contract'] ?? null) !== P19LinkedComponentIssue::VERSION) {
                        ApiError::abort(409, 'P21_MODE_UNSUPPORTED', 'Biên nhận không thuộc snapshot P19 linked.');
                    }
                    // Historical SAVE and current snapshot can name different cases.
                    Scope::requireCase($scope, (string) ($form['warranty_case_id'] ?? ''));
                }
            }
            $data['document'] = $row === null ? null : $this->document($row, $scope);
            $data['cancel_allowed'] = false;
            $data['p21_recovery_policy'] = 'CHECK_ONLY';
            $data['may_create_new_request'] = false;

            return $this->response($scope, $data);
        });
    }

    private function input(Request $request, array $extra): array
    {
        $rules = ['warehouse_id' => ['required', 'uuid'], 'operation' => ['required', Rule::in(['COMPONENT_ISSUE'])]] + $extra;
        if ($request->getContent() !== '' || $request->request->all() !== []
            || array_diff(array_keys($request->query()), array_keys($rules)) !== []) {
            ApiError::abort(422, 'P21_UNEXPECTED_INPUT', 'P21 chỉ đọc query đã khai báo, không nhận body hoặc bộ lọc khác.');
        }
        if ($request->hasHeader('If-Match') || $request->hasHeader('Idempotency-Key')) {
            ApiError::abort(422, 'P21_READ_HEADER_INVALID', 'GET P21 không nhận header mutation; key đối chiếu nằm ở đường dẫn.');
        }

        return Validator::make($request->query(), $rules)->validate();
    }

    private function scope(Request $request): array
    {
        $scope = Scope::resolve($request);
        if (! $scope['component']) {
            ApiError::abort(403, 'P21_COMPONENT_PERMISSION_DENIED', 'Không có quyền đọc công việc xuất linh kiện.');
        }

        return $scope;
    }

    private function owned(array $scope): Builder
    {
        return Scope::isolate(DB::table(MiniAppDraftService::TABLE.' as d')
            ->where('d.actor_id', $scope['actor_id'])->where('d.auth_session_id', $scope['auth_session_id'])
            ->where('d.warehouse_id', $scope['warehouse_id'])->where('d.operation', 'COMPONENT_ISSUE'), MiniAppDraftService::TABLE, 'd', $scope);
    }

    private function linked(array $scope): Builder
    {
        $cases = Scope::cases($scope)->select('c.warranty_case_id', 'c.status');
        // P03 persists JSON in TEXT, not a PostgreSQL jsonb column. Explicitly
        // cast the fixed selector; never interpolate a caller-provided SQL path.
        [$contract, $case] = match (DB::getDriverName()) {
            'pgsql' => ["CAST(d.form AS JSONB) #>> '{p19,contract}'", "LOWER(CAST(d.form AS JSONB) #>> '{p19,warranty_case_id}')"],
            'sqlite' => ["json_extract(d.form, '$.p19.contract')", "LOWER(json_extract(d.form, '$.p19.warranty_case_id'))"],
            default => ApiError::abort(503, 'P21_SOURCE_UNSUPPORTED', 'Chưa nghiệm thu driver của nguồn checkpoint.'),
        };

        return $this->owned($scope)->whereRaw($contract.' = ?', [P19LinkedComponentIssue::VERSION])
            ->joinSub($cases, 'pc', function (JoinClause $join) use ($case): void {
                $join->on(DB::raw('CAST(pc.warranty_case_id AS VARCHAR(36))'), '=', DB::raw($case));
            });
    }

    private function find(array $scope, string $id, string $scan): object
    {
        $row = $this->linked($scope)->where('d.document_id', strtolower($id))
            ->where('d.scan_session_id', strtolower($scan))->first(['d.*', 'pc.status as case_status']);
        if ($row === null) {
            ApiError::abort(404, 'DRAFT_NOT_FOUND', 'Không tìm thấy checkpoint P19 trong actor, phiên, kho và hồ sơ hiện tại.');
        }

        return $row;
    }

    private function document(object $row, array $scope): array
    {
        $form = json_decode($row->form, true, 512, JSON_THROW_ON_ERROR)['p19'];
        $codes = json_decode($row->codes, true, 512, JSON_THROW_ON_ERROR);
        $valid = is_array($codes) && array_is_list($codes);
        $quantity = 0;
        foreach ($codes as $code) {
            $ok = is_array($code) && is_string($code['raw_code'] ?? null) && $code['raw_code'] !== ''
                && is_int($code['quantity'] ?? null) && $code['quantity'] >= 1 && $code['quantity'] <= 100000
                && in_array($code['scan_source'] ?? null, ['CAMERA', 'SCANNER', 'HARDWARE', 'MANUAL'], true);
            $valid = $valid && $ok;
            $quantity += $ok ? $code['quantity'] : 0;
        }
        $business = null;
        if ($row->business_document_id !== null && $scope['documents']) {
            $query = DB::table('component_issue_documents as b')->where('b.id', $row->business_document_id)
                ->where('b.warehouse_id', $scope['warehouse_id'])->where('b.warranty_case_id', $form['warranty_case_id']);
            $business = Scope::isolate($query, 'component_issue_documents', 'b', $scope)
                ->first(['b.id', 'b.component_issue_no', 'b.status', 'b.version', 'b.posted_at']);
        }
        $caseAllows = in_array($row->case_status, ['CHECKING', 'REPAIRING'], true);
        $policy = match (true) {
            $row->status === 'RECORDED' => $business === null ? 'RECORDED_RESULT_UNAVAILABLE' : 'OPEN_EXISTING_RESULT',
            $row->status === 'CANCELLED' => 'READ_ONLY_CANCELLED',
            ! $valid => 'READ_ONLY_INCOMPLETE_SNAPSHOT',
            ! $caseAllows => 'READ_ONLY_CASE_STATE',
            $row->business_document_id !== null => 'READ_ONLY_LINK_CONFLICT',
            default => 'OWNER_PREFLIGHT_REQUIRED',
        };
        $last = Scope::isolate(DB::table(MiniAppDraftService::RECEIPTS.' as r'), MiniAppDraftService::RECEIPTS, 'r', $scope)
            ->where('r.actor_id', $scope['actor_id'])->where('r.auth_session_id', $scope['auth_session_id'])
            ->where('r.warehouse_id', $scope['warehouse_id'])->where('r.document_id', $row->document_id)
            ->where('r.scan_session_id', $row->scan_session_id)->where('r.request_id', $row->last_request_id)
            ->where('r.operation', 'COMPONENT_ISSUE')->first(['r.request_id', 'r.command', 'r.version']);

        return ['document_id' => $row->document_id, 'document_kind' => 'STAGING_DRAFT',
            'actor_id' => $scope['actor_id'], 'auth_session_id' => $row->auth_session_id,
            'scan_session_id' => $row->scan_session_id, 'warehouse_id' => $row->warehouse_id,
            'operation' => 'COMPONENT_ISSUE', 'version' => (int) $row->version, 'status' => $row->status,
            'warranty_case_id' => $form['warranty_case_id'], 'case_status' => $row->case_status,
            // Own private input, not canonical customer data or a stock fact.
            'form' => ['p19' => array_intersect_key($form, array_flip(['contract', 'warranty_case_id', 'document_date', 'note']))],
            'codes' => $codes, 'codes_validated' => false, 'scan_evidence_persisted' => false,
            'snapshot_shape_valid' => $valid, 'summary' => ['code_count' => count($codes),
                'quantity_total' => $valid ? $quantity : null, 'basis' => 'UNVALIDATED_PERSONAL_SNAPSHOT'],
            'business_document_id' => $business?->id, 'business_link_present' => $row->business_document_id !== null,
            'business_status' => $business?->status, 'business_version' => $business === null ? null : (int) $business->version,
            'document_no' => $business?->component_issue_no, 'posted_at' => $this->instant($business?->posted_at),
            'can_open_business' => $business !== null,
            'business_detail_path' => $business === null ? null : '/api/v1/mini-app/warranty-component-issues/'.$business->id,
            'last_request_id' => $row->last_request_id, 'last_command' => $last?->command,
            'last_receipt_version' => $last === null ? null : (int) $last->version,
            'resume_policy' => $policy, 'case_allows_issue' => $caseAllows,
            'owner_revalidation_required' => true, 'mutation_exposed' => false,
            'created_at' => $this->instant($row->created_at), 'updated_at' => $this->instant($row->updated_at),
            'stock_effect' => 'NONE', 'read_stock_effect' => 'NONE'];
    }

    private function response(array $scope, mixed $data, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => [
            'contract' => MiniAppDraftService::CONTRACT, 'resume_contract' => self::CONTRACT,
            'request_id' => request()->attributes->get('request_id'),
            'actor_id' => $scope['actor_id'], 'auth_session_id' => $scope['auth_session_id'], 'warehouse_id' => $scope['warehouse_id'],
            'mode' => 'P03_P19_LINKED_ONLY', 'count_unit' => 'STAGING_TASK',
            'scope_policy' => 'ACTOR_SESSION_WAREHOUSE_TENANT_PERSISTED_CASE_BEFORE_TOTAL',
            'tenant_isolation' => Scope::metadata($scope)['tenant_isolation'],
            'consistency' => 'LIVE_READ_NOT_FROZEN_CROSS_REQUEST', 'mutation_exposed' => false, ...$meta,
        ]])->header('Cache-Control', 'private, no-store')->header('Vary', self::HEADER);
    }

    private function uuid(string $id): void
    {
        if (! Str::isUuid($id)) {
            ApiError::abort(422, 'P21_ID_INVALID', 'Định danh phải là UUID thực, không phải số phiếu.');
        }
    }

    private function instant(?string $value): ?string
    {
        // P03 stores explicit UTC offsets; business owner timestamps can be
        // unqualified local SQL values. Match the P19 owner interpretation,
        // then normalize to UTC without replacing a missing time with created_at.
        return $value === null ? null : Carbon::parse($value, (string) config('app.timezone', 'UTC'))->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
