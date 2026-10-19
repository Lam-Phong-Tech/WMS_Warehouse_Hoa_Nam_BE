<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\P12DocumentPresentation;
use Modules\Warehouse\Support\P12StockOwnerProjection;
use Modules\Warehouse\Support\P13StockRecordProjection;
use Symfony\Component\HttpFoundation\Response;

/** Additive read metadata only, after existing owner authorization and serialization. */
final class P12DocumentReadMetadata
{
    public const READ_ROUTES = [
        'api/v1/mini-app/inbound-documents' => ['INBOUND', 'LIST'],
        'api/v1/mini-app/inbound-documents/{id}' => ['INBOUND', 'DETAIL'],
        'api/v1/mini-app/outbound-documents' => ['OUTBOUND', 'LIST'],
        'api/v1/mini-app/outbound-documents/{id}' => ['OUTBOUND', 'DETAIL'],
        'api/v1/mini-app/warranty-cases' => ['WARRANTY', 'LIST'],
        'api/v1/mini-app/warranty-cases/{id}' => ['WARRANTY', 'DETAIL'],
        'api/v1/mini-app/warranty-requests/{requestId}' => ['WARRANTY', 'RECEIPT'],
    ];

    public const DOWNLOAD_ROUTE = 'api/v1/mini-app/document-attachments/{attachmentId}/download';

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->uri();
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }
        if ($route === self::DOWNLOAD_ROUTE) {
            $this->requireStockAttachmentParent($request);

            return $next($request); // Keep the owner's binary, audit, and headers unchanged.
        }
        $response = $next($request);
        if (! isset(self::READ_ROUTES[$route]) || ! $response instanceof JsonResponse
            || ! $response->isSuccessful()) {
            return $response;
        }
        $body = $response->getData(true);
        if (($body['success'] ?? null) !== true || ! is_array($body['data'] ?? null)) {
            return $response;
        }
        [$source, $mode] = self::READ_ROUTES[$route];
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        if ($mode === 'RECEIPT') {
            $meta['p12'] = P12DocumentPresentation::receipt($body['data'], $meta);
        } else {
            $meta['p12'] = P12DocumentPresentation::metadata($source);
            if ($mode === 'LIST') {
                foreach ($body['data'] as &$row) {
                    if (is_array($row)) {
                        $row['p12'] = P12DocumentPresentation::record($row, $source, false);
                    }
                }
                unset($row);
            } else {
                $body['data']['p12'] = P12DocumentPresentation::record($body['data'], $source, true);
                if (in_array($source, ['INBOUND', 'OUTBOUND'], true)) {
                    $projection = P12StockOwnerProjection::read($request, $source);
                    $identity = $body['data']['p12']['identity'];
                    if ($identity['id'] !== $projection['document_id'] || $identity['warehouse_id'] !== $projection['warehouse_id']) {
                        ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Phiếu nguồn đã thay đổi; tải lại chi tiết.');
                    }
                    $body['data']['p12']['owner_projection'] = $projection;
                    $meta['p12']['owner_projection_contract'] = P12StockOwnerProjection::CONTRACT;
                }
            }
        }
        $body['meta'] = $meta;
        $body = P13StockRecordProjection::decorate($request, $body, $source, $mode);

        return $response->setData($body)->header('Cache-Control', 'private, no-store');
    }

    private function requireStockAttachmentParent(Request $request): void
    {
        $id = (string) $request->route('attachmentId');
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ đính kèm.');
        }
        $row = DB::table('document_attachments')->where('id', strtolower($id))
            ->first(['document_type', 'document_id', 'status']);
        if ($row === null || in_array($row->status, ['DELETED', 'QUARANTINED'], true)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ đính kèm.');
        }
        // P12 stock scope only. Other existing owners retain their original guards.
        $type = ['INBOUND' => ['receipt', 'dst_warehouse_id'], 'OUTBOUND' => ['issue', 'src_warehouse_id']][$row->document_type] ?? null;
        if ($type === null) {
            return;
        }
        [$docType, $warehouseField] = $type;
        $document = DB::table('stock_documents')->where('id', $row->document_id)
            ->where('doc_type', $docType)->whereNull('deleted_at')->first(['id', $warehouseField]);
        if ($document === null || ! Str::isUuid((string) $document->{$warehouseField})) {
            // A missing/mistyped parent must never turn a scoped download into a global one.
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nguồn của chứng từ.');
        }
        $actor = $request->user();
        if ($actor === null || ! method_exists($actor, 'canAccessWarehouse')
            || ! $actor->canAccessWarehouse((string) $document->{$warehouseField})) {
            ApiError::abort(403, 'DATA_SCOPE_DENIED', 'Chứng từ đính kèm nằm ngoài phạm vi kho.');
        }
    }
}
