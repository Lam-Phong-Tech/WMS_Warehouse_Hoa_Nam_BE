<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SRS v1.12 §8.1 Endpoint Catalog + Annex C: các path chuẩn phải tồn tại và
 * được gác đúng permission. Route hiện hành giữ nguyên để client cũ không vỡ.
 */
class AnnexCEndpointAliasTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> path Annex C => permission bắt buộc (dạng chuẩn) */
    private const ANNEX_C_ROUTES = [
        'post api/v1/inbound-documents/{id}/scans' => 'inbound.scan',
        'post api/v1/inbound-documents/{id}/reversals' => 'inventory.reversal.post',
        'post api/v1/outbound-documents/{id}/scans' => 'outbound.scan',
        'post api/v1/outbound-documents/{id}/reversals' => 'inventory.reversal.post',
        'get api/v1/putaway-tasks' => 'putaway.view',
        'post api/v1/putaway-tasks' => 'putaway.manage',
        'post api/v1/putaway-tasks/{id}/confirm' => 'putaway.manage',
        'post api/v1/putaway-tasks/{id}/cancel' => 'putaway.manage',
        'post api/v1/warranty-cases/{id}/status' => 'warranty.manage',
        'post api/v1/warranty-component-issues' => 'warranty.component_issue',
        'post api/v1/warranty-component-issues/{id}/scans' => 'warranty.component_issue',
        'post api/v1/warranty-component-issues/{id}/cancel' => 'warranty.component_issue',
        'post api/v1/warranty-component-issues/{id}/post' => 'warranty.component_issue',
        'post api/v1/outbound-request-imports/{id}/confirm' => 'outbound.request.confirm',
        'post api/v1/outbound-request-imports/manual' => 'outbound.request.manual_create',
        'get api/v1/inventory' => 'inventory.view',
        'get api/v1/inventory/trace/{code}' => 'inventory.trace',
        'post api/v1/scan-batches/classify' => 'scan.execute',
        'get api/v1/reports/inventory-ledger' => 'report.view',
        'get api/v1/reports/{report_code}' => 'report.view',
        'post api/v1/reports/export' => 'report.export',
        'put api/v1/outbound-documents/{id}/items/{itemId}/rfid' => 'physical_code.assign_rfid',
        'post api/v1/outbound-documents/{id}/packing-labels/{labelId}/preview' => 'packing_label.preview',
        'post api/v1/outbound-documents/{id}/packing-labels/{labelId}/ready' => 'packing_label.ready',
        'post api/v1/outbound-documents/{id}/packing-labels/{labelId}/print' => 'packing_label.print',
        'post api/v1/outbound-documents/{id}/packing-labels/{labelId}/reprint' => 'packing_label.reprint',
        'post api/v1/reversal-documents/{id}/confirm' => 'inventory.reversal.post',
        'post api/v1/reversal-documents/{id}/post' => 'inventory.reversal.post',
        'post api/v1/reversal-documents/{id}/cancel' => 'inventory.reversal.post',
        'get api/v1/scan/batches' => 'inventory.view',
        'get api/v1/scan/events' => 'inventory.view',
        // §8.1 Warehouse "GET/POST /warehouses" + Annex C createWarehouse.
        'post api/v1/warehouses' => 'warehouse.manage',
        // §8.1 Master "/defects; PATCH /defects/{id}" + Annex C list/create/updateDefect.
        'get api/v1/defects' => 'defect.view',
        'post api/v1/defects' => 'defect.manage',
        'patch api/v1/defects/{id}' => 'defect.manage',
        // §8.1 Master "/catalog-masters/{master_type}" + Annex C catalog master ops.
        'get api/v1/catalog-masters/{masterType}' => 'catalog.view',
        'post api/v1/catalog-masters/{masterType}' => 'catalog.manage',
        'patch api/v1/catalog-masters/{masterType}/{id}' => 'catalog.manage',
    ];

    public function test_every_annex_c_path_is_registered_with_required_permission(): void
    {
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $registered[strtolower($method).' '.$route->uri()] = $route->gatherMiddleware();
            }
        }

        $missing = [];
        $unguarded = [];
        foreach (self::ANNEX_C_ROUTES as $key => $permission) {
            if (! isset($registered[$key])) {
                $missing[] = $key;

                continue;
            }

            $guards = array_filter(
                $registered[$key],
                fn ($item): bool => is_string($item) && str_starts_with($item, 'permission:')
            );
            $accepts = false;
            foreach ($guards as $guard) {
                $candidates = explode('|', substr((string) $guard, strlen('permission:')));
                if (in_array($permission, array_map('trim', $candidates), true)) {
                    $accepts = true;
                    break;
                }
            }
            if (! $accepts) {
                $unguarded[] = $key.' (cần chấp nhận '.$permission.')';
            }
        }

        $this->assertSame([], $missing, 'Thiếu path Annex C: '.implode(', ', $missing));
        $this->assertSame([], $unguarded, 'Permission chưa khớp Annex C: '.implode(', ', $unguarded));
    }

    public function test_legacy_paths_remain_available_for_existing_clients(): void
    {
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $registered[] = strtolower($method).' '.$route->uri();
            }
        }

        foreach ([
            'post api/v1/inbound-documents/{id}/scan',
            'post api/v1/inbound-documents/{id}/reverse',
            'post api/v1/outbound-documents/{id}/scan',
            'post api/v1/outbound-documents/{id}/reverse',
            'get api/v1/putaway/tasks',
            'post api/v1/component-issue-documents/{id}/scan',
            'post api/v1/outbound-request-imports/{id}/consume',
            'get api/v1/inventory/balances',
            'post api/v1/scan/classify',
        ] as $legacy) {
            $this->assertContains($legacy, $registered, "Route legacy {$legacy} không được gỡ khi thêm alias Annex C.");
        }
    }
}
