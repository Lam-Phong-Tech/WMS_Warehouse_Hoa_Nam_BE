<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use RuntimeException;
use Tests\TestCase;

/**
 * WWHN-217 / FE217-P1-05 — bằng chứng RUNTIME cho "FE guard không được thay backend
 * enforcement".
 *
 * Register đòi QA2 xác nhận mã lỗi API THẬT trên candidate, tối thiểu 401/403/422/409
 * khi applicable, cho đúng ba luồng mà suite retest WWHN-217 đi qua: Inbound, Outbound,
 * Inventory. Ẩn nút trên UI không phải là kiểm soát phân quyền (SRS §8.4 WWHN-40:
 * "UI hiding is not authorization"), nên bằng chứng phải sinh ra từ tầng HTTP + middleware
 * thật, không phải từ ảnh chụp màn hình hay từ FE mock.
 *
 * KHÔNG dùng lại evidence của WWHN-289/292/144/211: file này tự dựng fixture, tự lái
 * HTTP thật và tự ghi `build/evidence/wwhn-217/runtime-error-contract.json` để trace
 * matrix của WWHN-217 có nguồn riêng, đúng yêu cầu zero-orphan của FE217-P1-04.
 *
 * Mapping mã lỗi theo SRS §8.2:
 *   401 AUTH_REQUIRED/TOKEN_EXPIRED  · 403 PERMISSION_DENIED/DATA_SCOPE_DENIED
 *   409 STATE_CONFLICT / IDEMPOTENCY_CONFLICT / CONCURRENCY_CONFLICT /
 *       DOWNSTREAM_DEPENDENCY_EXISTS
 *   422 VALIDATION_ERROR / BUSINESS_RULE_VIOLATION / INSUFFICIENT_STOCK
 * Mọi nhánh từ chối đều phải để nguyên state/stock/movement.
 */
class Wwhn217RuntimeErrorContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * §8.2 liệt kê mã lỗi ở cột **"Error code examples"**, không phải enum đóng; dòng
     * `Envelope` mới là luật ràng buộc: "HTTP and business code must match Annex/API
     * contract". Vì vậy điều kiện đúng để kiểm tra runtime là:
     *   (1) mã trả về phải có trong catalog hợp đồng của backend, và
     *   (2) catalog phải khai đúng hạng HTTP mà runtime vừa trả.
     * Danh sách dưới đây chỉ dùng để ĐÁNH DẤU mã có nằm trong ví dụ §8.2 hay không —
     * ghi vào evidence cho BA/QA đọc, không dùng làm điều kiện đỏ. Khoá cứng theo ví dụ
     * §8.2 sẽ biến một mã hợp lệ như `SCAN_EXCEEDS_REQUIRED` (§8.2 không liệt kê nhưng
     * catalog khai 422) thành cổng đỏ giả.
     */
    private const SRS_82_EXAMPLE_CODES = [
        401 => ['AUTH_REQUIRED', 'TOKEN_EXPIRED'],
        403 => ['PERMISSION_DENIED', 'DATA_SCOPE_DENIED'],
        409 => ['STATE_CONFLICT', 'IDEMPOTENCY_CONFLICT', 'CONCURRENCY_CONFLICT', 'DOWNSTREAM_DEPENDENCY_EXISTS'],
        412 => ['STALE_VERSION'],
        422 => ['VALIDATION_ERROR', 'BUSINESS_RULE_VIOLATION', 'INSUFFICIENT_STOCK'],
    ];

    /** @var array<string, list<int>>|null */
    private static ?array $errorCodeCatalog = null;

    /**
     * Catalog hợp đồng `HN-BE-API-002` (sinh bởi scripts/generate-error-code-catalog.mjs).
     * Đọc fail-closed: thiếu file hoặc hỏng JSON thì dừng, không im lặng bỏ qua kiểm tra.
     *
     * @return array<string, list<int>>
     */
    private static function errorCodeCatalog(): array
    {
        if (self::$errorCodeCatalog !== null) {
            return self::$errorCodeCatalog;
        }

        $path = dirname(__DIR__, 2).'/docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Không đọc được error code catalog: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['codes']) || ! is_array($decoded['codes'])) {
            throw new RuntimeException("Error code catalog sai định dạng: {$path}");
        }

        $map = [];
        foreach ($decoded['codes'] as $entry) {
            if (! is_array($entry) || ! isset($entry['code'])) {
                continue;
            }
            $map[(string) $entry['code']] = array_map('intval', (array) ($entry['http_status'] ?? []));
        }

        return self::$errorCodeCatalog = $map;
    }

    /** @var array<string, mixed> */
    private static array $probes = [];

    public static function tearDownAfterClass(): void
    {
        self::flushEvidence();
        self::$probes = [];

        parent::tearDownAfterClass();
    }

    private static function flushEvidence(): void
    {
        if (self::$probes === []) {
            return;
        }

        $directory = dirname(__DIR__, 2).'/build/evidence/wwhn-217';
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Không tạo được thư mục evidence: {$directory}");
        }

        $probes = self::$probes;
        ksort($probes);

        $payload = [
            'ticket' => 'WWHN-217',
            'register_item' => 'FE217-P1-05',
            'source' => 'backend runtime (HTTP kernel + middleware thật, không mock)',
            'srs' => 'SRS §8.2 HTTP/Error Contract; §8.4 WWHN-40 "UI hiding is not authorization"; NFR-SRS-005',
            'backend_branch' => 'm1',
            'backend_sha' => self::resolveBackendSha(),
            'generated_by' => 'tests/Feature/Wwhn217RuntimeErrorContractTest.php',
            'http_classes_covered' => array_values(array_unique(array_map(
                static fn (array $probe): int => $probe['http'],
                $probes
            ))),
            'probes' => $probes,
        ];

        $path = $directory.'/runtime-error-contract.json';
        $written = file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );

        if ($written === false) {
            throw new RuntimeException("Không ghi được evidence: {$path}");
        }
    }

    /**
     * SHA của backend candidate. Đọc trực tiếp `.git/HEAD` thay vì chạy `git` để test
     * không phụ thuộc PATH của runner; không đọc được thì ghi rõ `unresolved` chứ không
     * bịa một giá trị trông như thật.
     */
    private static function resolveBackendSha(): string
    {
        $envSha = getenv('SOURCE_SHA') ?: getenv('GITHUB_SHA');
        if (is_string($envSha) && preg_match('/^[0-9a-f]{40}$/', $envSha) === 1) {
            return $envSha;
        }

        $gitDir = dirname(__DIR__, 2).'/.git';
        $head = @file_get_contents($gitDir.'/HEAD');
        if (! is_string($head)) {
            return 'unresolved';
        }

        $head = trim($head);
        if (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
            return $head;
        }

        if (str_starts_with($head, 'ref: ')) {
            $ref = trim(substr($head, 5));
            $refSha = @file_get_contents($gitDir.'/'.$ref);
            if (is_string($refSha) && preg_match('/^[0-9a-f]{40}$/', trim($refSha)) === 1) {
                return trim($refSha);
            }

            $packed = @file_get_contents($gitDir.'/packed-refs');
            if (is_string($packed) && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', $packed, $m) === 1) {
                return $m[1];
            }
        }

        return 'unresolved';
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordProbe(string $id, string $flow, string $method, string $path, int $http, ?string $code, string $rule, array $extra = []): void
    {
        $catalog = self::errorCodeCatalog();
        $this->assertArrayHasKey(
            (string) $code,
            $catalog,
            "Mã lỗi {$code} của {$id} không có trong catalog hợp đồng HN-BE-API-002."
        );
        $this->assertContains(
            $http,
            $catalog[(string) $code],
            "Catalog khai mã {$code} ở hạng HTTP khác với hạng runtime vừa trả ({$http})."
        );

        self::$probes[$id] = array_merge([
            'flow' => $flow,
            'method' => $method,
            'path' => $path,
            'http' => $http,
            'error_code' => $code,
            'error_code_in_contract_catalog' => true,
            'listed_in_srs_82_examples' => in_array(
                (string) $code,
                self::SRS_82_EXAMPLE_CODES[$http] ?? [],
                true
            ),
            'rule' => $rule,
            'verdict' => 'PASS',
        ], $extra);
    }

    /**
     * 401 — không có token thì ba luồng đều bị chặn TRƯỚC khi chạm dữ liệu.
     *
     * Request ẩn danh phải là thao tác đầu tiên: `auth('api')->login()` dùng dựng fixture
     * sẽ nhét token vào chính instance JWT mà guard giữ, nên gọi sau đó thì request
     * "không Authorization header" vẫn được coi là đã đăng nhập và ta ký một bằng chứng SAI.
     */
    public function test_401_anonymous_requests_are_rejected_on_all_three_flows(): void
    {
        $inbound = $this->postJson('/api/v1/inbound-documents/'.Str::uuid().'/post-receipt', [], [
            'If-Match' => '1',
            'Idempotency-Key' => 'wwhn217-anon-inbound',
        ]);
        $inbound->assertStatus(401);

        $outbound = $this->postJson('/api/v1/outbound-documents/'.Str::uuid().'/post-issue', [], [
            'If-Match' => '1',
            'Idempotency-Key' => 'wwhn217-anon-outbound',
        ]);
        $outbound->assertStatus(401);

        $inventory = $this->getJson('/api/v1/inventory/balances');
        $inventory->assertStatus(401);

        $this->assertSame(0, DB::table('stock_movements')->count(), 'Request ẩn danh không được ghi stock effect.');

        $this->recordProbe(
            'TC-217-API-401-IN',
            'Inbound',
            'POST',
            '/api/v1/inbound-documents/{id}/post-receipt',
            401,
            $inbound->json('error.code'),
            'Không token → 401 trước khi chạm dữ liệu; không stock effect.',
            ['stock_effect_written' => false]
        );
        $this->recordProbe(
            'TC-217-API-401-OUT',
            'Outbound',
            'POST',
            '/api/v1/outbound-documents/{id}/post-issue',
            401,
            $outbound->json('error.code'),
            'Không token → 401; không stock effect.',
            ['stock_effect_written' => false]
        );
        $this->recordProbe(
            'TC-217-API-401-INV',
            'Inventory',
            'GET',
            '/api/v1/inventory/balances',
            401,
            $inventory->json('error.code'),
            'Không token → 401 cho cả endpoint chỉ đọc.',
            ['stock_effect_written' => false]
        );
    }

    /**
     * 403 — có token hợp lệ nhưng thiếu đúng permission của hành động. Đây là vế chứng
     * minh "ẩn nút trên FE không phải kiểm soát": FE có thể bị bypass (gọi thẳng API),
     * backend vẫn phải từ chối và giữ nguyên trạng thái phiếu.
     */
    public function test_403_underprivileged_token_is_rejected_on_all_three_flows(): void
    {
        $owner = $this->headersForRole('SUPER_ADMIN');
        $fixture = $this->preparedReceipt($owner, 'WWHN217-RBAC');
        $movementsBefore = DB::table('stock_movements')->count();

        $inboundViewer = $this->headersForCustomRole(['inbound.view']);
        $inbound = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $inboundViewer + [
            'If-Match' => $fixture['version'],
            'Idempotency-Key' => 'wwhn217-noperm-inbound',
        ]);
        $inbound->assertStatus(403);

        $outboundViewer = $this->headersForCustomRole(['outbound.view']);
        $outbound = $this->postJson('/api/v1/outbound-documents/'.Str::uuid().'/post-issue', [], $outboundViewer + [
            'If-Match' => '1',
            'Idempotency-Key' => 'wwhn217-noperm-outbound',
        ]);
        $outbound->assertStatus(403);

        $noInventory = $this->headersForCustomRole(['inbound.view']);
        $inventory = $this->getJson('/api/v1/inventory/balances', $noInventory);
        $inventory->assertStatus(403);

        $this->assertSame(
            $movementsBefore,
            DB::table('stock_movements')->count(),
            '403 tuyệt đối không được ghi stock effect.'
        );
        $this->assertDatabaseHas('stock_documents', [
            'id' => $fixture['document_id'],
            'status' => 'SCANNING',
        ]);

        $this->recordProbe(
            'TC-217-API-403-IN',
            'Inbound',
            'POST',
            '/api/v1/inbound-documents/{id}/post-receipt',
            403,
            $inbound->json('error.code'),
            'Token chỉ có inbound.view gọi hành động đòi inbound.post → 403; phiếu vẫn SCANNING.',
            [
                'granted_permissions' => ['inbound.view'],
                'required_permission' => 'inbound.post',
                'document_status_after' => 'SCANNING',
                'stock_effect_written' => false,
            ]
        );
        $this->recordProbe(
            'TC-217-API-403-OUT',
            'Outbound',
            'POST',
            '/api/v1/outbound-documents/{id}/post-issue',
            403,
            $outbound->json('error.code'),
            'Token chỉ có outbound.view gọi hành động đòi outbound.post → 403 ở tầng middleware.',
            [
                'granted_permissions' => ['outbound.view'],
                'required_permission' => 'outbound.post',
                'stock_effect_written' => false,
            ]
        );
        $this->recordProbe(
            'TC-217-API-403-INV',
            'Inventory',
            'GET',
            '/api/v1/inventory/balances',
            403,
            $inventory->json('error.code'),
            'Token không có inventory.view → 403 cho endpoint đọc tồn.',
            [
                'granted_permissions' => ['inbound.view'],
                'required_permission' => 'inventory.view',
                'stock_effect_written' => false,
            ]
        );
    }

    /**
     * 422 — validation/business rule. Hợp đồng GĐ1 scan-driven KHÔNG còn gate
     * "quét vượt Expected" (qty_planned tự nâng theo scan), nên probe 422 là
     * quantity không hợp lệ (0) — §8.2 vẫn bắt buộc "state, stock and movement
     * unchanged".
     */
    public function test_422_validation_leaves_document_and_stock_unchanged(): void
    {
        $owner = $this->headersForRole('SUPER_ADMIN');
        $fixture = $this->preparedReceipt($owner, 'WWHN217-VALIDATION');
        $movementsBefore = DB::table('stock_movements')->count();

        $invalidScan = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/scan", [
            'line_id' => $fixture['line_id'],
            'quantity' => 0,
            'container_id' => $fixture['container_id'],
        ], $owner + ['If-Match' => $fixture['version']]);

        $invalidScan->assertStatus(422);

        $this->assertSame(
            $movementsBefore,
            DB::table('stock_movements')->count(),
            '422 không được ghi stock effect.'
        );
        $this->assertDatabaseHas('stock_documents', [
            'id' => $fixture['document_id'],
            'status' => 'SCANNING',
        ]);

        $this->recordProbe(
            'TC-217-API-422-IN',
            'Inbound',
            'POST',
            '/api/v1/inbound-documents/{id}/scan',
            422,
            $invalidScan->json('error.code'),
            'Quantity 0 không hợp lệ → 422; phiếu và tồn không đổi.',
            [
                'scenario' => 'scan quantity 0 (scan-driven: không còn gate quét vượt Expected)',
                'document_status_after' => 'SCANNING',
                'stock_effect_written' => false,
            ]
        );
    }

    /**
     * 409 — hai nhánh khác nhau của cùng hạng, đúng như FE phải phân biệt:
     *  (a) cùng Idempotency-Key nhưng payload khác → IDEMPOTENCY_CONFLICT (phải bắt đầu
     *      lại bằng key mới);
     *  (b) Post lại phiếu đã POSTED bằng key MỚI → xung đột trạng thái (không được cộng
     *      tồn lần hai).
     * Đây chính là hợp đồng mà nhóm TC duplicate/retry của suite QA2 dựa vào.
     */
    public function test_409_conflicts_do_not_double_apply_stock(): void
    {
        $owner = $this->headersForRole('SUPER_ADMIN');
        $fixture = $this->preparedReceipt($owner, 'WWHN217-CONFLICT');

        $sharedKey = 'wwhn217-conflict-'.Str::lower(Str::random(8));
        $first = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/scan", [
            'line_id' => $fixture['line_id'],
            'quantity' => 1,
            'container_id' => $fixture['container_id'],
        ], $owner + ['If-Match' => $fixture['version'], 'Idempotency-Key' => $sharedKey]);
        $first->assertOk();

        $conflicting = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/scan", [
            'line_id' => $fixture['line_id'],
            'quantity' => 2,
            'container_id' => $fixture['container_id'],
        ], $owner + ['If-Match' => (string) $first->json('data.version'), 'Idempotency-Key' => $sharedKey]);
        $conflicting->assertStatus(409);

        $this->recordProbe(
            'TC-217-API-409-IDEMPOTENCY',
            'Inbound',
            'POST',
            '/api/v1/inbound-documents/{id}/scan',
            409,
            $conflicting->json('error.code'),
            'Cùng Idempotency-Key nhưng payload khác → 409; không cộng dồn lần hai.',
            ['scenario' => 'same Idempotency-Key, quantity 1 → 2']
        );

        // (b) Phiếu đã scan đủ 2/2 (1 từ fixture + 1 ở $first) → Post rồi Post lại bằng
        // key MỚI. 409 ở bước (a) là no-write nên version vẫn là version của $first.
        $posted = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $owner + [
            'If-Match' => (string) $first->json('data.version'),
            'Idempotency-Key' => 'wwhn217-post-'.Str::lower(Str::random(6)),
        ]);
        $posted->assertOk();
        $movementsAfterPost = DB::table('stock_movements')->count();

        $repost = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $owner + [
            'If-Match' => (string) $posted->json('data.version'),
            'Idempotency-Key' => 'wwhn217-repost-'.Str::lower(Str::random(6)),
        ]);
        $this->assertContains(
            $repost->getStatusCode(),
            [409, 412],
            'Post lại phiếu đã POSTED bằng key mới phải là xung đột trạng thái/version.'
        );

        $this->assertSame(
            $movementsAfterPost,
            DB::table('stock_movements')->count(),
            'Post lần hai không được sinh movement thứ hai.'
        );

        $this->recordProbe(
            'TC-217-API-409-STATE',
            'Inbound',
            'POST',
            '/api/v1/inbound-documents/{id}/post-receipt',
            $repost->getStatusCode(),
            $repost->json('error.code'),
            'Post lại phiếu POSTED bằng Idempotency-Key mới → xung đột; số movement không đổi.',
            [
                'movements_after_first_post' => $movementsAfterPost,
                'movements_after_second_post' => DB::table('stock_movements')->count(),
            ]
        );
    }

    private function seedAuthOnce(): void
    {
        if (DB::table('permission')->count() === 0) {
            $this->seed(AuthDatabaseSeeder::class);
            Cache::flush();
        }
    }

    /** @return array<string,string> */
    private function headersForRole(string $roleCode): array
    {
        $this->seedAuthOnce();
        $role = Role::where('role_code', $roleCode)->firstOrFail();
        $user = $this->createUserWithPassword('wwhn217-'.Str::lower(Str::random(8)));
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    /**
     * @param  list<string>  $permissionKeys
     * @return array<string,string>
     */
    private function headersForCustomRole(array $permissionKeys, ?string $scopeRef = null): array
    {
        $this->seedAuthOnce();
        $roleCode = 'WWHN217_'.Str::upper(Str::random(8));
        $role = Role::create([
            'role_code' => $roleCode,
            'role_name' => $roleCode,
            'scope_type' => $scopeRef === null ? 'SYSTEM' : 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                ['role_permission_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $user = $this->createUserWithPassword('wwhn217-scoped-'.Str::lower(Str::random(6)));
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => $scopeRef,
        ]);
        Cache::flush();

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function createUserWithPassword(string $username): User
    {
        return User::create([
            'username' => $username,
            'display_name' => 'WWHN-217 runtime contract fixture',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    /**
     * Phiếu nhập đã ở trạng thái SCANNING (đã ghi nhận 1 đơn vị) — đúng trạng thái mà
     * màn IN-03 của suite QA2 thao tác, và là điều kiện để các nhánh 403/422/409 bên
     * dưới không bị 412 STALE_VERSION che mất.
     *
     * @param  array<string,string>  $headers
     * @return array{document_id:string,line_id:string,container_id:string,sku_id:string,warehouse_id:string,version:string}
     */
    private function preparedReceipt(array $headers, string $skuCodePrefix): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hòa Nam');
        $sku = Sku::create([
            'sku_code' => $skuCodePrefix.'-'.Str::upper(Str::random(6)),
            'sku_name' => "Component {$skuCodePrefix}",
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        // Hợp đồng GĐ1 scan-driven: header-only + materialize dòng COMPONENT qua
        // /component-lines (không còn tạo phiếu kèm lines/qty_planned).
        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'WWHN-217 runtime contract fixture',
        ], $headers);
        $create->assertCreated();

        $documentId = (string) $create->json('data.id');

        $ensure = $this->postJson("/api/v1/inbound-documents/{$documentId}/component-lines", [
            'sku_id' => (string) $sku->sku_id,
        ], $headers)->assertOk();
        $lineId = (string) $ensure->json('data.line_id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-'.Str::upper(Str::random(8)),
        ], $headers);
        $box->assertSuccessful();
        $containerId = (string) $box->json('data.container_id');

        $scan = $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'container_id' => $containerId,
        ], $headers + ['If-Match' => '1']);
        $scan->assertOk();

        return [
            'document_id' => $documentId,
            'line_id' => $lineId,
            'container_id' => $containerId,
            'sku_id' => (string) $sku->sku_id,
            'warehouse_id' => (string) $warehouse->id,
            'version' => (string) $scan->json('data.version'),
        ];
    }

    private function warehouseFixture(): Warehouse
    {
        return $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hòa Nam');
    }
}
