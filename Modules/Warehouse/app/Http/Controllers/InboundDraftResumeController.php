<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Modules\Warehouse\Support\P09WarrantyScope;

/** A canonical stock DRAFT is not a P03 draft, even when its number is PN. */
final class InboundDraftResumeController extends Controller
{
    public const CONTRACT = 'inbound-draft-resume-v1';

    public function show(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id) || array_diff(array_keys($request->query()), ['warehouse_id']) !== []) {
            ApiError::abort(422, 'INBOUND_RESUME_QUERY_INVALID', 'Cần ID phiếu và warehouse_id hợp lệ.');
        }
        $warehouse = $request->query('warehouse_id');
        if (! is_string($warehouse) || ! Str::isUuid($warehouse)) {
            ApiError::abort(422, 'INBOUND_RESUME_WAREHOUSE_REQUIRED', 'Cần warehouse_id UUID đã xác nhận.');
        }

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $id, $warehouse): JsonResponse {
            $presented = $request->user();
            $actor = $presented instanceof User ? User::whereKey($presented->user_id)->first() : null;
            if ($actor === null || $actor->status !== 'ACTIVE'
                || ! $actor->hasPermission('inbound.view') || ! $actor->hasPermission('inbound.create')) {
                ApiError::abort(403, 'INBOUND_RESUME_PERMISSION_DENIED', 'Không có quyền đọc và sửa phiếu nhập nháp.');
            }
            $family = app(StableAuthSession::class)->requireId($actor);
            $context = app(WarehouseContext::class)->payload($actor, $family, 'AUTH_SESSION_REQUIRED');
            $warehouse = strtolower($warehouse);
            if (($context['warehouse_context']['status'] ?? null) !== 'READY'
                || ($context['active_warehouse_id'] ?? null) !== $warehouse) {
                ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Cần xác nhận đúng kho làm việc.');
            }
            if (! $actor->canAccessWarehouse($warehouse)) {
                ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho không thuộc phạm vi tài khoản.');
            }
            $source = DB::table('stock_documents as d')->where('d.id', strtolower($id))->where('d.doc_type', 'receipt')
                ->where('d.dst_warehouse_id', $warehouse)->whereNull('d.deleted_at');
            P09WarrantyScope::isolate($source, 'stock_documents', 'd', ['dimensions' => $this->dimensions($actor, $warehouse)]);
            $document = $source->first('d.*');
            if ($document === null || $document->created_by !== $actor->user_id) {
                ApiError::abort(404, 'INBOUND_RESUME_OWNER_NOT_FOUND', 'Không tìm thấy phiếu của người tạo trong kho hiện tại.');
            }
            if ($document->status !== 'DRAFT') {
                ApiError::abort(409, 'INBOUND_RESUME_STATE_CONFLICT', 'Chỉ phiếu DRAFT do chính người tạo mới được tiếp tục sửa.');
            }
            $lines = DB::table('stock_document_lines as l')->where('l.stock_document_id', $document->id);
            P09WarrantyScope::isolate($lines, 'stock_document_lines', 'l', ['dimensions' => $this->dimensions($actor, $warehouse)]);
            $items = $lines->orderBy('l.line_no')->orderBy('l.id')->get(['l.id', 'l.line_no', 'l.product_id', 'l.qty_planned', 'l.scanned_qty', 'l.version'])
                ->map(fn (object $line): array => ['line_id' => $line->id, 'line_no' => (int) $line->line_no,
                    'sku_id' => $line->product_id, 'expected_qty' => (int) $line->qty_planned,
                    'scanned_qty' => (int) $line->scanned_qty, 'version' => (int) $line->version,
                    'raw_codes' => null, 'raw_codes_status' => 'NOT_IN_STOCK_DOCUMENT_LINE'])->all();
            $body = ['success' => true, 'data' => [
                'contract' => self::CONTRACT, 'owner_kind' => 'CANONICAL_STOCK_DOCUMENT',
                'can_resume' => false, 'reason' => 'CREATOR_GUARDED_WRITE_PROTOCOL_PENDING', 'stock_document_id' => $document->id,
                'p03_draft_id' => null, 'p03_draft_id_status' => 'NOT_LINKED_BEFORE_RECORD',
                'document_no' => $document->doc_no, 'warehouse_id' => $warehouse,
                'owner_user_id' => $document->created_by, 'status' => $document->status,
                'version' => (int) $document->version, 'scan_session_id' => null,
                'scan_session_status' => 'NOT_P03_SCAN_SESSION',
                'inbound_category' => null, 'inbound_category_status' => 'NOT_RECORDED',
                'supplier_id' => $document->supplier_id ?? null,
                'supplier_id_status' => $document->supplier_id === null ? 'NOT_RECORDED' : 'PERSISTED_STOCK_DOCUMENT_ID',
                'supplier_code' => null, 'supplier_code_status' => 'NO_PERSISTED_SNAPSHOT',
                'supplier_name' => null, 'supplier_name_status' => 'NO_PERSISTED_SNAPSHOT',
                'doc_date' => $document->doc_date, 'note' => $document->note,
                'items' => $items, 'write_target' => null,
                'write_target_status' => 'CREATOR_GUARDED_WRITE_PROTOCOL_PENDING',
                'stock_effect' => 'NONE', 'opened_without_insert' => true,
            ], 'meta' => ['contract' => self::CONTRACT, 'revision' => 'inbound-draft-resume-r1',
                'snapshot' => $snapshot, 'request_id' => $request->attributes->get('request_id')]];

            return response()->json($body)->header('ETag', '"'.$document->version.'"')
                ->header('Cache-Control', 'private, no-store');
        });
    }

    private function dimensions(User $actor, string $warehouse): array
    {
        $row = DB::table('warehouses')->where('id', $warehouse)->where('status', 'ACTIVE')->first();
        if ($row === null) {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho không còn hoạt động.');
        }
        $dimensions = [];
        foreach (['tenant_id', 'org_id', 'organization_id'] as $field) {
            $value = $actor->getAttribute($field);
            $owner = $row->{$field} ?? null;
            if ($value !== null || $owner !== null) {
                if (! is_string($value) || $value === '' || $value !== $owner) {
                    ApiError::abort(403, 'INBOUND_RESUME_TENANT_MISMATCH', 'Phiếu không thuộc tổ chức được cấp.');
                }
                $dimensions[$field] = $value;
            }
        }

        return $dimensions;
    }
}
