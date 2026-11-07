<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;

/** Mutations of the same global supplier directory exposed by P04. */
final class SupplierWriteController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $this->authorizeWriter($request);
            $input = $this->input($request, false);
            // Every API allocator uses this transaction lock before reading codes.
            // The existing unique index also protects against out-of-band writers.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261107, 1]);
            } elseif (DB::getDriverName() !== 'sqlite') {
                ApiError::abort(409, 'SUPPLIER_CODE_DRIVER_UNSUPPORTED', 'Cơ sở dữ liệu không hỗ trợ cấp mã nhà cung cấp.');
            }
            $warehouse = DB::table('warehouses')->where('code', 'KHO-TONG-HN')->where('status', 'ACTIVE')->lockForUpdate()->first();
            if ($warehouse === null) {
                ApiError::abort(409, 'WAREHOUSE_NOT_AVAILABLE', 'Kho tổng đang không khả dụng.');
            }
            $row = [
                'id' => (string) Str::uuid(), 'warehouse_id' => $warehouse->id,
                'code' => $this->nextCode(), 'name' => $input['name'],
                'status' => $input['status'] ?? 'ACTIVE', 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('inbound_suppliers')->insert($row);

            return $this->response(DB::table('inbound_suppliers')->where('id', $row['id'])->first(), 201);
        }, 3);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $this->authorizeWriter($request);
            if (! Str::isUuid($id)) {
                ApiError::abort(404, 'SUPPLIER_NOT_FOUND', 'Không tìm thấy nhà cung cấp.');
            }
            $input = $this->input($request, true);
            $current = DB::table('inbound_suppliers')->where('id', strtolower($id))->lockForUpdate()->first();
            if ($current === null) {
                ApiError::abort(404, 'SUPPLIER_NOT_FOUND', 'Không tìm thấy nhà cung cấp.');
            }
            $header = trim((string) $request->header('If-Match'));
            if ($header === '') {
                ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Cần gửi If-Match chứa phiên bản nhà cung cấp.');
            }
            if (preg_match('/^(?:"([1-9][0-9]*)"|([1-9][0-9]*))$/D', $header, $matches) !== 1) {
                ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là phiên bản số nguyên dương.');
            }
            $version = filter_var($matches[1] !== '' ? $matches[1] : $matches[2], FILTER_VALIDATE_INT);
            if ($version === false) {
                ApiError::abort(422, 'INVALID_VERSION', 'Phiên bản vượt giới hạn cho phép.');
            }
            if ($version !== (int) $current->version) {
                ApiError::abort(412, 'STALE_VERSION', 'Nhà cung cấp đã được cập nhật; tải lại trước khi lưu.', [
                    'expected_version' => $version, 'current_version' => (int) $current->version,
                ]);
            }
            if ($version >= 2147483647) {
                ApiError::abort(409, 'SUPPLIER_VERSION_EXHAUSTED', 'Phiên bản nhà cung cấp đã đạt giới hạn.');
            }
            DB::table('inbound_suppliers')->where('id', $current->id)->update($input + [
                'version' => $version + 1, 'updated_at' => now(),
            ]);

            return $this->response(DB::table('inbound_suppliers')->where('id', $current->id)->first());
        }, 3);
    }

    private function authorizeWriter(Request $request): void
    {
        $actor = User::whereKey($request->user()->user_id)->lockForUpdate()->first();
        if ($actor === null || $actor->status !== 'ACTIVE' || ! $actor->hasPermission('catalog.manage')) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Không có quyền quản lý nhà cung cấp.');
        }
        app(StableAuthSession::class)->requireId($actor);
    }

    private function input(Request $request, bool $update): array
    {
        if (! $request->isJson() || $request->query() !== []) {
            ApiError::abort(422, 'INVALID_SUPPLIER_REQUEST', 'Gửi JSON body, không gửi query cho thao tác này.');
        }
        if (strlen($request->getContent()) > 16 * 1024) {
            ApiError::abort(413, 'SUPPLIER_PAYLOAD_TOO_LARGE', 'Nội dung nhà cung cấp vượt giới hạn.');
        }
        $input = $request->json()->all();
        if (array_diff(array_keys($input), ['name', 'status']) !== []) {
            ApiError::abort(422, 'UNEXPECTED_FIELD', 'Chỉ được gửi tên và trạng thái nhà cung cấp.');
        }
        if ($update && $input === []) {
            ApiError::abort(422, 'EMPTY_UPDATE', 'Cần gửi tên hoặc trạng thái để cập nhật.');
        }
        if (isset($input['name']) && is_string($input['name'])) {
            $input['name'] = trim($input['name']);
        }

        return Validator::make($input, [
            'name' => $update ? ['sometimes', 'required', 'string', 'max:200'] : ['required', 'string', 'max:200'],
            'status' => ['sometimes', 'required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ])->validate();
    }

    private function nextCode(): string
    {
        $last = 0;
        foreach (DB::table('inbound_suppliers')->whereNotNull('code')->pluck('code') as $code) {
            if (preg_match('/^NCC-([0-9]+)$/D', $code, $matches) !== 1) {
                continue;
            }
            $number = filter_var(ltrim($matches[1], '0') ?: '0', FILTER_VALIDATE_INT);
            if ($number === false || $number === PHP_INT_MAX) {
                ApiError::abort(409, 'SUPPLIER_CODE_NAMESPACE_EXHAUSTED', 'Mã nhà cung cấp đã đạt giới hạn.');
            }
            $last = max($last, $number);
        }

        return 'NCC-'.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }

    private function response(object $supplier, int $status = 200): JsonResponse
    {
        $assigned = is_string($supplier->code) && $supplier->code !== '';
        $data = [
            'id' => $supplier->id, 'code' => $supplier->code, 'name' => $supplier->name,
            'display_name' => $assigned ? $supplier->code.' - '.$supplier->name : $supplier->name,
            'code_status' => $assigned ? 'ASSIGNED' : 'NOT_ASSIGNED', 'status' => $supplier->status,
            'version' => (int) $supplier->version, 'updated_at' => $supplier->updated_at,
        ];

        return response()->json(['success' => true, 'data' => $data, 'meta' => [
            'contract' => P04InboundController::CONTRACT, 'supplier_code_contract' => 'supplier-business-code-v1',
            'scope' => 'GLOBAL', 'source' => 'inbound_suppliers',
        ]], $status)->header('ETag', '"'.$supplier->version.'"')->header('Cache-Control', 'private, no-store');
    }
}
