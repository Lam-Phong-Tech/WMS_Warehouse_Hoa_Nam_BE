<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentationBaselineContractTest extends TestCase
{
    use RefreshDatabase;

    private array $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->contract = json_decode(
            (string) file_get_contents(base_path('docs/contracts/HoaNam_Current_Baseline_Contract.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function test_source_documents_and_manifest_are_pinned(): void
    {
        foreach ($this->contract['sources'] as $source) {
            $content = (string) file_get_contents(base_path($source['path']));
            $this->assertStringContainsString($source['document_id'], $content, $source['path']);
        }
        $alignment = (string) file_get_contents(base_path('docs/DR_HN_20260815_PACKING_LABEL_RFID_FLOW06.md'));
        $this->assertStringContainsString('exactly 01 Packing Label', $alignment);
        $this->assertStringContainsString('RFID is optional', $alignment);
    }

    public function test_api_surface_matches_current_baseline_and_forbidden_scope_is_absent(): void
    {
        $operations = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[strtoupper($method).' /'.$route->uri()] = true;
                }
            }
        }

        $this->assertCount($this->expectedOperationCount(), $operations);

        // Mỗi path Annex C khai trong contract phải thực sự tồn tại — chống việc
        // chỉ tăng số đếm mà không đăng ký route.
        foreach ($this->contract['api']['annex_c_alignment']['operations'] as $operation) {
            $this->assertArrayHasKey($operation, $operations, "Annex C operation [{$operation}] chưa được đăng ký route.");
        }

        // Operation do ERD yêu cầu nhưng Annex C chưa khai: cũng phải tồn tại và
        // phải có rationale, để surface không phình ra mà không ai review được.
        foreach ($this->contract['api']['erd_alignment']['operations'] as $operation) {
            $this->assertArrayHasKey($operation, $operations, "ERD-aligned operation [{$operation}] chưa được đăng ký route.");
            $this->assertArrayHasKey(
                $operation,
                $this->contract['api']['erd_alignment']['rationale'],
                "ERD-aligned operation [{$operation}] thiếu rationale trong contract."
            );
        }

        // Operation có mặt ở runtime sau khi contract đóng băng (81782ec): mỗi operation
        // phải được liệt kê tường minh trong một nhóm có commit đưa vào, lý do, chủ sở hữu
        // và trạng thái duyệt — số đếm không được tăng trần.
        $explicit = array_flip(array_merge(
            $this->contract['api']['annex_c_alignment']['operations'],
            $this->contract['api']['erd_alignment']['operations'],
        ));
        $amended = [];
        foreach ($this->contract['api']['post_baseline_amendments']['groups'] as $group) {
            foreach (['id', 'introduced_in', 'introduced_at', 'title', 'rationale', 'owner', 'approval'] as $field) {
                $this->assertNotEmpty(
                    $group[$field] ?? null,
                    'Amendment '.($group['id'] ?? '?')." thiếu [{$field}] — không chấp nhận tăng số đếm trần."
                );
            }
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{7,40}$/',
                (string) $group['introduced_in'],
                "Amendment {$group['id']}: introduced_in phải là commit SHA."
            );
            $this->assertNotEmpty($group['operations'] ?? [], "Amendment {$group['id']} không liệt kê operation nào.");

            foreach ($group['operations'] as $operation) {
                $this->assertArrayHasKey($operation, $operations, "Amendment operation [{$operation}] chưa được đăng ký route.");
                $this->assertArrayNotHasKey($operation, $explicit, "Operation [{$operation}] đã được đếm ở Annex C/ERD alignment — không khai hai lần.");
                $this->assertArrayNotHasKey($operation, $amended, "Operation [{$operation}] khai hai lần trong post_baseline_amendments.");
                $amended[$operation] = true;
            }
        }

        // Operation đã gỡ có chủ ý phải VẮNG MẶT — một lần merge nhầm làm nó quay lại thì đỏ.
        foreach ($this->contract['api']['retired_operations'] as $retired) {
            $this->assertArrayNotHasKey($retired['operation'], $operations, "Operation đã gỡ [{$retired['operation']}] quay lại API surface.");
            $this->assertNotEmpty($retired['removed_in'] ?? null, "Operation đã gỡ [{$retired['operation']}] thiếu removed_in.");
            $this->assertNotEmpty($retired['decision'] ?? null, "Operation đã gỡ [{$retired['operation']}] thiếu decision.");
        }

        $joined = strtolower(implode("\n", array_keys($operations)));
        foreach ($this->contract['api']['forbidden_path_fragments'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $joined, "Forbidden API scope [{$fragment}] is exposed.");
        }
    }

    /**
     * Năm route dưới đây chạy handler BaM1* từ quyết định GĐ1 (25/08/2026):
     * Flow06AlignmentServiceProvider đăng ký lại chúng lúc boot — nhập kho scan-driven chỉ
     * tạo header rồi quét (BaM1InboundDocumentController), Post Issue là giao dịch kho độc
     * lập với nhãn (BaM1OutboundFlowController, REQ-012), bảo hành multi-defect với
     * estimated_completion_date canonical (BaM1WarrantyController, REQ-009/010). Commit:
     * f93446b "activate M1 outbound and warranty handlers", 4a4fce8, 5c64d42. Repo chưa có
     * decision record GĐ1: sign-off BA + Tech Lead còn PENDING (ghi ở contract,
     * post_baseline_amendments và retired_operations).
     */
    public function test_current_business_routes_use_final_baseline_handlers(): void
    {
        $expected = [
            'POST api/v1/skus' => 'CurrentBaselineSkuController@store',
            'PATCH api/v1/skus/{id}' => 'CurrentBaselineSkuController@update',
            'POST api/v1/skus/{id}/physical-codes' => 'CurrentBaselineItemIdentityController@assign',
            'DELETE api/v1/skus/{id}/physical-codes/{physicalCodeId}' => 'CurrentBaselineItemIdentityController@revoke',
            'POST api/v1/inbound-documents' => 'BaM1InboundDocumentController@store',
            'PATCH api/v1/inbound-documents/{id}' => 'BaM1InboundDocumentController@update',
            'POST api/v1/inbound-documents/{id}/cancel' => 'CurrentBaselineDocumentController@cancelInbound',
            'POST api/v1/inbound-documents/{id}/scan' => 'CurrentBaselineStockFlowController@inboundScan',
            'POST api/v1/inbound-documents/{id}/post-receipt' => 'CurrentBaselineStockFlowController@postReceipt',
            'POST api/v1/inbound-documents/{id}/reverse' => 'CurrentBaselineStockFlowController@reverseInbound',
            // §8.1: "Confirm creates exactly one outbound DRAFT; no direct POST
            // /outbound-documents create operation" — POST tạo trực tiếp vẫn bị cấm.
            // PATCH header phiếu DRAFT được mở có kiểm soát (AMD-API-052).
            'POST api/v1/outbound-documents/{id}/cancel' => 'CurrentBaselineDocumentController@cancelOutbound',
            'PATCH api/v1/outbound-documents/{id}' => 'CurrentBaselineDocumentController@updateOutbound',
            'POST api/v1/outbound-documents/{id}/scan' => 'AlignedOutboundFlowController@outboundScan',
            'POST api/v1/outbound-documents/{id}/post-issue' => 'BaM1OutboundFlowController@postIssue',
            'POST api/v1/outbound-documents/{id}/reverse' => 'CurrentBaselineStockFlowController@reverseOutbound',
            'POST api/v1/containers' => 'CurrentBaselineContainerFlowController@store',
            'PATCH api/v1/containers/{id}' => 'CurrentBaselineContainerFlowController@update',
            'POST api/v1/containers/{id}/items' => 'CurrentBaselineContainerFlowController@addItem',
            'DELETE api/v1/containers/{id}/items/{containerItemId}' => 'CurrentBaselineContainerFlowController@removeItem',
            'POST api/v1/physical-codes' => 'CurrentBaselinePhysicalCodeController@store',
            'PATCH api/v1/physical-codes/{id}' => 'CurrentBaselinePhysicalCodeController@update',
            'POST api/v1/outbound-request-imports' => 'CurrentBaselineImportController@storeOutboundRequestImport',
            'POST api/v1/outbound-request-imports/{id}/consume' => 'CurrentBaselineImportController@consumeOutboundRequestImport',
            'POST api/v1/component-issue-documents' => 'CurrentBaselineComponentFlowController@store',
            'POST api/v1/component-issue-documents/{id}/scan' => 'CurrentBaselineComponentFlowController@scan',
            'POST api/v1/component-issue-documents/{id}/cancel' => 'CurrentBaselineComponentFlowController@cancel',
            'POST api/v1/component-issue-documents/{id}/post' => 'CurrentBaselineComponentFlowController@post',
            'POST api/v1/warranty-cases' => 'BaM1WarrantyController@storeWarrantyCase',
            'PATCH api/v1/warranty-cases/{id}' => 'BaM1WarrantyController@updateWarrantyCase',
            'POST api/v1/label-snapshots' => 'AlignedOutboundFlowController@storePackingLabel',
            'POST api/v1/label-snapshots/{id}/print' => 'CurrentBaselineOperationsController@printLabelSnapshot',
            'POST api/v1/label-snapshots/{id}/reprint' => 'CurrentBaselineOperationsController@reprintLabelSnapshot',
            'GET api/v1/pv/catalog' => 'CurrentBaselineOperationsController@pvCatalog',
            'GET api/v1/pv/recommendations' => 'CurrentBaselineOperationsController@pvRecommendations',
        ];

        foreach ($expected as $key => $actionSuffix) {
            [$method, $uri] = explode(' ', $key, 2);
            $route = $this->findRoute($method, $uri);
            $this->assertNotNull($route, "Missing route [{$key}].");
            $this->assertStringEndsWith($actionSuffix, $route->getActionName(), "Route [{$key}] is not using final baseline handler.");
        }

        $this->assertNull(
            $this->findRoute('POST', 'api/v1/label-snapshots/{id}/apply'),
            'GĐ1 đã gỡ trạng thái APPLY của Packing Label (contract retired_operations) — route không được quay lại.'
        );
    }

    /**
     * SRS §8.1 (Outbound request / Excel intake): "Confirm creates exactly one outbound
     * DRAFT; no direct POST /outbound-documents create operation." PATCH header phiếu
     * DRAFT được mở lại có kiểm soát (AMD-API-052); POST create vẫn phải vắng mặt.
     */
    public function test_direct_outbound_create_is_absent_and_draft_update_is_present(): void
    {
        $this->assertNull($this->findRoute('POST', 'api/v1/outbound-documents'));
        $patch = $this->findRoute('PATCH', 'api/v1/outbound-documents/{id}');
        $this->assertNotNull($patch, 'PATCH /api/v1/outbound-documents/{id} must exist for DRAFT header edits.');
        $this->assertStringEndsWith(
            'CurrentBaselineDocumentController@updateOutbound',
            $patch->getActionName()
        );
    }

    public function test_canonical_route_permissions_cover_core_mutations(): void
    {
        $expected = [
            'GET api/v1/users' => 'permission:iam.user.view',
            'POST api/v1/users' => 'permission:iam.user.manage',
            'GET api/v1/skus' => 'permission:sku.view',
            'POST api/v1/skus' => 'permission:sku.manage',
            'POST api/v1/skus/{id}/physical-codes' => 'permission:physical_code.assign_rfid',
            'POST api/v1/inbound-documents/{id}/scan' => 'permission:inbound.scan',
            'POST api/v1/inbound-documents/{id}/post-receipt' => 'permission:inbound.post',
            'POST api/v1/outbound-documents/{id}/scan' => 'permission:outbound.scan',
            'POST api/v1/outbound-documents/{id}/post-issue' => 'permission:outbound.post',
            'PATCH api/v1/outbound-documents/{id}' => 'permission:outbound.request.manual_create|outbound.import',
            'POST api/v1/inbound-documents/{id}/reverse' => 'permission:inventory.reversal.post',
            'POST api/v1/outbound-documents/{id}/reverse' => 'permission:inventory.reversal.post',
            // Annex C importOutboundRequestExcel: outbound.request.import (giữ
            // outbound.import legacy để không thu hồi quyền đang cấp).
            'POST api/v1/outbound-request-imports' => 'permission:outbound.request.import|outbound.import',
            'POST api/v1/component-issue-documents/{id}/scan' => 'permission:warranty.component_issue',
            'POST api/v1/component-issue-documents/{id}/post' => 'permission:warranty.component_issue',
            'PATCH api/v1/warranty-cases/{id}' => 'permission:warranty.manage',
            'POST api/v1/label-snapshots' => 'permission:label.print',
            'POST api/v1/label-snapshots/{id}/print' => 'permission:label.print',
            'POST api/v1/label-snapshots/{id}/reprint' => 'permission:label.reprint',
        ];

        foreach ($expected as $key => $permission) {
            [$method, $uri] = explode(' ', $key, 2);
            $route = $this->findRoute($method, $uri);
            $this->assertNotNull($route, "Missing route [{$key}].");
            $this->assertContains($permission, $route->gatherMiddleware(), "Route [{$key}] must use [{$permission}].");
        }

        $this->assertNull(
            $this->findRoute('POST', 'api/v1/label-snapshots/{id}/apply'),
            'GĐ1 đã gỡ trạng thái APPLY của Packing Label (contract retired_operations) — route không được quay lại.'
        );
    }

    public function test_required_objects_columns_and_excluded_scope_match_manifest(): void
    {
        $objects = $this->databaseObjects();
        foreach ($this->contract['required_objects'] as $object) {
            $this->assertContains($object, $objects, "Missing baseline DB object [{$object}].");
        }
        foreach ($this->contract['excluded_tables'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Superseded DB table [{$table}] must not exist.");
        }
        foreach ($this->contract['critical_columns'] as $object => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($object, $column), "{$object} missing documented column [{$column}].");
            }
        }
        // WWHN-SEC-06: cột PII plaintext đã bỏ không được quay lại dưới bất kỳ quan hệ nào.
        foreach ($this->contract['forbidden_columns']['objects'] as $object => $columns) {
            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn($object, $column), "{$object} must not expose removed column [{$column}] (WWHN-SEC-06).");
            }
        }
        foreach (['completion_type', 'shortage_reason', 'remaining_qty', 'cancelled_remainder_qty'] as $column) {
            $this->assertFalse(Schema::hasColumn('stock_document_lines', $column), "Superseded partial field [{$column}] must be absent.");
        }
        foreach (['outbound_document_id', 'applied_at', 'applied_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('label_snapshots', $column), "label_snapshots missing Flow 06 column [{$column}].");
        }
    }

    public function test_mutable_operational_storage_is_physical(): void
    {
        // ERD bảng 33/34: bảng canonical là reversal_documents/reversal_lines (số nhiều);
        // reversal_document/reversal_line (số ít) là compatibility view sau 000791.
        $requiredPhysical = [
            'inventory_balances', 'stock_movements', 'outbound_request_imports', 'outbound_request_import_rows',
            'outbound_requests', 'outbound_request_lines', 'inbound_scan_entries', 'outbound_item_matches',
            'label_snapshots', 'printer_configs', 'label_print_events', 'component_issue_documents',
            'component_issue_lines', 'component_issue_items', 'putaway_events',
        ];

        // Migration 000791 (chỉ chạy trên PostgreSQL) đảo chiều: tên số nhiều thành
        // bảng vật lý, số ít thành view. sqlite giữ baseline số ít làm bảng vật lý,
        // nên yêu cầu "phải là storage ghi được" kiểm theo tên của từng driver.
        $requiredPhysical = array_merge($requiredPhysical, DB::getDriverName() === 'pgsql'
            ? ['reversal_documents', 'reversal_lines']
            : ['reversal_document', 'reversal_line']);

        foreach ($requiredPhysical as $table) {
            $this->assertTrue($this->isPhysicalTable($table), "{$table} must be physical storage, not a compatibility view.");
        }
    }

    public function test_reference_database_foreign_keys_match_documented_relationships(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite compatibility gate does not add post-hoc FK constraints.');
        }
        foreach ($this->contract['reference_db_relationships'] as [$table, $column, $foreignTable, $foreignColumn]) {
            $matched = collect(Schema::getForeignKeys($table))->contains(function (array $fk) use ($column, $foreignTable, $foreignColumn): bool {
                $columns = $fk['columns'] ?? [];
                $foreignColumns = $fk['foreign_columns'] ?? $fk['foreignColumns'] ?? [];
                $target = $fk['foreign_table'] ?? $fk['foreignTable'] ?? null;

                return in_array($column, $columns, true) && $target === $foreignTable && in_array($foreignColumn, $foreignColumns, true);
            });
            $this->assertTrue($matched, "Missing FK {$table}.{$column} -> {$foreignTable}.{$foreignColumn}.");
        }
    }

    public function test_canonical_permissions_and_required_roles_are_seeded(): void
    {
        $permissionCodes = DB::table('permissions')->pluck('permission_key')->all();
        foreach ($this->contract['permissions'] as $permission) {
            $this->assertContains($permission, $permissionCodes, "Missing canonical permission [{$permission}].");
        }
        $roleCodes = DB::table('roles')->pluck('role_code')->all();
        foreach ($this->contract['required_roles'] as $role) {
            $this->assertContains($role, $roleCodes, "Missing required role [{$role}].");
        }

        // Quyền đã thu hồi (2026_08_27_170000): không được nằm lại trong danh sách canonical,
        // và nếu dòng còn trong DB thì phải INACTIVE — ACTIVE lại là cấp lại một quyền ma.
        $retired = $this->contract['retired_permissions']['keys'];
        $this->assertNotEmpty($retired);
        $this->assertNotEmpty($this->contract['retired_permissions']['owner'] ?? null);
        $this->assertSame(
            [],
            array_values(array_intersect($retired, $this->contract['permissions'])),
            'Permission đã retire không được nằm trong danh sách permissions canonical.'
        );
        $statusByKey = DB::table('permissions')->whereIn('permission_key', $retired)->pluck('status', 'permission_key')->all();
        foreach ($statusByKey as $key => $status) {
            $this->assertSame('INACTIVE', strtoupper((string) $status), "Permission đã retire [{$key}] còn ACTIVE.");
        }
    }

    public function test_generated_swagger_has_route_parity_and_baseline_error_responses(): void
    {
        // Miễn trừ tường minh, có chủ sở hữu: operation được khai ĐÚNG tập status của nó
        // (vd. health chỉ 200/503) — không phải bỏ qua kiểm tra.
        $exemptions = [];
        foreach ($this->contract['api']['http_error_status_exemptions'] as $exemption) {
            $this->assertNotEmpty($exemption['owner'] ?? null, "Miễn trừ [{$exemption['operation']}] thiếu owner.");
            $this->assertNotEmpty($exemption['decision'] ?? null, "Miễn trừ [{$exemption['operation']}] thiếu decision.");
            $exemptions[$exemption['operation']] = array_map('strval', $exemption['documented_statuses']);
        }
        $usedExemptions = [];

        Artisan::call('l5-swagger:generate');
        $swagger = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $count = 0;
        foreach ($swagger['paths'] ?? [] as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'] as $method) {
                if (! isset($pathItem[$method])) {
                    continue;
                }
                $count++;
                $key = strtoupper($method).' '.$path;
                if (isset($exemptions[$key])) {
                    $documented = array_map('strval', array_keys($pathItem[$method]['responses'] ?? []));
                    $expected = $exemptions[$key];
                    sort($documented);
                    sort($expected);
                    $this->assertSame($expected, $documented, "{$key}: miễn trừ chỉ áp cho đúng tập status đã khai trong contract.");
                    $usedExemptions[$key] = true;

                    continue;
                }
                foreach ($this->contract['api']['http_error_statuses'] as $status) {
                    $this->assertArrayHasKey((string) $status, $pathItem[$method]['responses'] ?? [], "{$method} {$path} missing HTTP {$status}.");
                }
            }
        }
        foreach (array_keys($exemptions) as $key) {
            $this->assertArrayHasKey($key, $usedExemptions, "Miễn trừ [{$key}] không còn operation tương ứng — gỡ khỏi contract.");
        }
        $this->assertSame($this->expectedOperationCount(), $count);
    }

    public function test_postgres_types_single_warehouse_and_history_guards(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL physical contract gate.');
        }
        foreach ($this->contract['postgres_bigint_columns'] as [$table, $column]) {
            $type = DB::table('information_schema.columns')
                ->where('table_schema', DB::raw('current_schema()'))
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('data_type');
            $this->assertSame('bigint', $type, "{$table}.{$column} must be BIGINT.");
        }

        $triggerNames = collect(DB::select('SELECT tgname FROM pg_trigger WHERE NOT tgisinternal'))->pluck('tgname')->all();
        foreach ($this->contract['append_only_storage'] as $table) {
            $this->assertContains("trg_{$table}_append_only", $triggerNames, "{$table} must have append-only trigger.");
        }
        $this->assertContains('trg_stock_documents_posted_immutable', $triggerNames);
        $this->assertContains('trg_reversal_document_posted_immutable', $triggerNames);
        $this->assertContains('trg_component_issue_documents_posted_immutable', $triggerNames);
        $this->assertSame(1, DB::table('warehouses')->where('status', 'ACTIVE')->count());
    }

    private function expectedOperationCount(): int
    {
        // Base manifest remains pinned to the approved source bundle. Annex C alignment adds
        // the canonical §8.1/Annex C paths and ERD alignment adds ERD-required paths, each
        // listed explicitly. post_baseline_amendments lists every operation that reached the
        // runtime after the manifest froze (81782ec), grouped with commit, rationale and owner.
        // The former "+1 Flow 06 apply" is gone: GĐ1 removed POST label-snapshots/{id}/apply,
        // which is now asserted absent through api.retired_operations.
        $amended = 0;
        foreach ($this->contract['api']['post_baseline_amendments']['groups'] as $group) {
            $amended += count($group['operations']);
        }

        return (int) $this->contract['api']['operation_count']
            + count($this->contract['api']['annex_c_alignment']['operations'])
            + count($this->contract['api']['erd_alignment']['operations'])
            + $amended;
    }

    private function findRoute(string $method, string $uri): mixed
    {
        return collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true));
    }

    private function isPhysicalTable(string $name): bool
    {
        return match (DB::getDriverName()) {
            'sqlite' => DB::table('sqlite_master')->where('name', $name)->where('type', 'table')->exists(),
            'pgsql' => DB::table('pg_class as c')->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')->where('n.nspname', DB::raw('current_schema()'))->where('c.relname', $name)->whereIn('c.relkind', ['r', 'p'])->exists(),
            default => collect(DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))->map(fn (object $row): string => (string) array_values((array) $row)[0])->contains($name),
        };
    }

    private function databaseObjects(): array
    {
        return match (DB::getDriverName()) {
            'sqlite' => collect(DB::select("SELECT name FROM sqlite_master WHERE type IN ('table','view')"))->pluck('name')->all(),
            'pgsql' => collect(DB::select("SELECT c.relname AS name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND c.relkind IN ('r','p','v')"))->pluck('name')->all(),
            default => collect(DB::select('SHOW FULL TABLES'))->map(fn (object $row): string => (string) array_values((array) $row)[0])->all(),
        };
    }
}
