<?php

namespace Modules\Warehouse\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Symfony\Component\HttpFoundation\Response;

/**
 * One parent lock for existing Web, legacy Mini App and receipt-mode uploads.
 * Owner authorization, quota values, scanning and response contracts stay owned
 * by their existing handlers. In Mini App this must run INSIDE receipt handling,
 * so an uncertain operation cannot roll back its separately committed intent.
 */
final class AttachmentQuotaFence
{
    public const UPLOAD_ROUTES = [
        'api/v1/inbound-documents/{id}/attachments' => 'INBOUND',
        'api/v1/outbound-documents/{id}/attachments' => 'OUTBOUND',
        'api/v1/warranty-cases/{id}/attachments' => 'WARRANTY',
        'api/v1/mini-app/inbound-documents/{id}/attachments' => 'INBOUND',
        'api/v1/mini-app/outbound-documents/{id}/attachments' => 'OUTBOUND',
        'api/v1/mini-app/warranty-cases/{id}/attachments' => 'WARRANTY',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $owner = self::UPLOAD_ROUTES[$request->route()?->uri() ?? ''] ?? null;
        $actor = $request->user();
        $id = (string) $request->route('id');
        if ($owner === null || ! $request->isMethod('POST') || ! $actor instanceof User || ! Str::isUuid($id)) {
            return $next($request);
        }

        // The pre-existing route middleware runs first. Restrict this additional
        // lock to the actor's persisted warehouse scope as well; it grants no ACL.
        $warehouses = $actor->warehouseScopeIds();
        if ($owner === 'WARRANTY') {
            $parent = DB::table('warranty_case')->where('warranty_case_id', strtolower($id));
            if ($warehouses !== null) {
                $parent->whereExists(function ($query) use ($warehouses): void {
                    $query->selectRaw('1')->from(WarrantyWarehouseScope::TABLE.' as quota_owner')
                        ->whereColumn('quota_owner.warranty_case_id', 'warranty_case.warranty_case_id')
                        ->whereIn('quota_owner.warehouse_id', $warehouses);
                });
            }
        } else {
            $inbound = $owner === 'INBOUND';
            $parent = DB::table('stock_documents')->where('id', strtolower($id))
                ->where('doc_type', $inbound ? 'receipt' : 'issue');
            if ($warehouses !== null) {
                $parent->whereIn($inbound ? 'dst_warehouse_id' : 'src_warehouse_id', $warehouses);
            }
        }

        // No transaction retries: storage/scanner side effects must never replay.
        return DB::transaction(function () use ($parent, $request, $next): Response {
            if ($parent->lockForUpdate()->first() === null) {
                // Preserve the owner's exact missing/foreign-parent response.
                return $next($request);
            }
            try {
                $response = $next($request);
            } catch (HttpResponseException $exception) {
                $response = $exception->getResponse();
            }
            if ($response->getStatusCode() >= 500) {
                throw new HttpResponseException($response);
            }

            // A known 4xx may include a QUARANTINED audit row. Commit that evidence
            // exactly as the owner did before this fence; never treat it as ACTIVE.
            return $response;
        }, 1);
    }
}
