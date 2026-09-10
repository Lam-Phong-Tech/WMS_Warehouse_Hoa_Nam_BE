<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use RuntimeException;
use Tests\TestCase;
use Tests\TestCredentials;

/**
 * WWHN-165 / WWHN-35 — proof cho hai root fix:
 *
 *  (1) Audit-on-deny (ADR-AUDIT-002): mọi 403 permission (`CheckPermission`) và
 *      data-scope (`Enforce*WarehouseScope`) phải để lại đúng một audit row
 *      `result='DENIED'` (action AUTHZ_PERMISSION_DENIED / AUTHZ_DATA_SCOPE_DENIED)
 *      với request_id khớp header X-Request-ID của response, KHÔNG side-effect
 *      nghiệp vụ, và policy BEST_EFFORT — audit chết không được đổi response 403.
 *      401 anonymous KHÔNG ghi (không có actor xác thực — xem ADR-AUDIT-002).
 *
 *  (2) No-stack-trace fail-closed (`ApiConventionMiddleware`): debug payload
 *      (exception/file/line/trace) chỉ tồn tại khi APP_DEBUG=true VÀ môi trường
 *      local/development. APP_ENV=testing với debug bật nhầm vẫn không được lộ.
 *
 * Test cuối cùng lái toàn bộ ma trận lỗi hợp đồng (401/403x2/409x2/412/422/428)
 * qua HTTP thật và dump body vào `build/evidence/wwhn-165/samples/` — đây là nguồn
 * cho release pack `docs/api-contract/releases/WWHN-165/1.0.0/samples/`.
 *
 * Chạy được cả sqlite in-memory (mặc định) lẫn PostgreSQL (phpunit.pgsql.xml,
 * `audit_log` là VIEW trên `audit_logs` — mọi truy vấn đều qua tên view).
 */
class Wwhn165AuthzDenyAuditProofTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{status:int, body:mixed}> */
    private static array $samples = [];

    public static function tearDownAfterClass(): void
    {
        self::flushSamples();
        self::$samples = [];

        parent::tearDownAfterClass();
    }

    private static function flushSamples(): void
    {
        if (self::$samples === []) {
            return;
        }

        $directory = dirname(__DIR__, 2).'/build/evidence/wwhn-165/samples';
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Không tạo được thư mục samples: {$directory}");
        }

        foreach (self::$samples as $name => $sample) {
            $path = $directory.'/'.$name.'.json';
            $written = file_put_contents(
                $path,
                json_encode($sample['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            );
            if ($written === false) {
                throw new RuntimeException("Không ghi được sample: {$path}");
            }
        }
    }

    private static function capture(string $name, TestResponse $response): void
    {
        self::$samples[$name] = [
            'status' => $response->getStatusCode(),
            'body' => $response->json(),
        ];
    }

    /**
     * 403 thiếu permission trên endpoint WRITE: audit DENIED đúng 1 row, request_id
     * khớp header, bảng nghiệp vụ bất động, envelope không lộ trace.
     */
    public function test_permission_denied_403_writes_denied_audit_row_without_business_side_effect(): void
    {
        $owner = $this->headersForRole('SUPER_ADMIN');
        $fixture = $this->preparedReceipt($owner, 'WWHN165-PERM');

        $movementsBefore = DB::table('stock_movements')->count();
        $documentBefore = DB::table('stock_documents')
            ->where('id', $fixture['document_id'])->first(['version', 'status']);
        $deniedBefore = DB::table('audit_log')->where('result', 'DENIED')->count();

        $viewer = $this->headersForCustomRole(['inbound.view']);
        $requestId = 'wwhn165-perm-'.Str::lower(Str::random(12));

        $forbidden = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
            [],
            $viewer + [
                'If-Match' => $fixture['version'],
                'Idempotency-Key' => 'wwhn165-perm-'.Str::lower(Str::random(8)),
                'X-Request-ID' => $requestId,
            ]
        );

        $forbidden->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $forbidden->json('error.code'));
        $this->assertSame('ACCESS_DENIED', $forbidden->json('error_code'));
        $forbidden->assertJsonMissingPath('trace');
        $forbidden->assertJsonMissingPath('exception');
        $this->assertSame($requestId, $forbidden->headers->get('X-Request-ID'));
        $this->assertSame($requestId, $forbidden->json('meta.request_id'));

        // Không side-effect: movement + version/status của phiếu bất động.
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $documentAfter = DB::table('stock_documents')
            ->where('id', $fixture['document_id'])->first(['version', 'status']);
        $this->assertEquals($documentBefore->version, $documentAfter->version);
        $this->assertSame($documentBefore->status, $documentAfter->status);

        // Audit: tăng đúng 1 row DENIED, đúng action/mã lỗi/request_id/actor.
        $this->assertSame($deniedBefore + 1, DB::table('audit_log')->where('result', 'DENIED')->count());
        $rows = DB::table('audit_log')->where('request_id', $requestId)->get();
        $this->assertCount(1, $rows, 'Đúng MỘT audit row cho request bị deny.');
        $row = $rows->first();
        $this->assertSame('AUTHZ_PERMISSION_DENIED', $row->action);
        $this->assertSame('DENIED', $row->result);
        $this->assertSame('ACCESS_DENIED', $row->error_code);
        $this->assertNotNull($row->actor_id, 'Deny của user đã xác thực phải quy được về actor.');

        // Không PII/token thô trong metadata.
        $afterData = (string) $row->after_data;
        $token = substr((string) $viewer['Authorization'], 7);
        $this->assertStringContainsString('inbound.post', $afterData, 'Metadata phải nêu permission thiếu.');
        $this->assertStringNotContainsString($token, $afterData, 'Không được ghi JWT thô vào audit.');
        $this->assertStringNotContainsString('Bearer ', $afterData);
    }

    /**
     * 403 data-scope (kho ngoài scope_ref) — DATA_SCOPE_DENIED + audit
     * AUTHZ_DATA_SCOPE_DENIED, không side-effect.
     */
    public function test_data_scope_denied_403_writes_denied_audit_row_without_business_side_effect(): void
    {
        $owner = $this->headersForRole('SUPER_ADMIN');
        $fixture = $this->preparedReceipt($owner, 'WWHN165-SCOPE');

        $movementsBefore = DB::table('stock_movements')->count();
        $deniedBefore = DB::table('audit_log')->where('result', 'DENIED')->count();

        $scoped = $this->headersForCustomRole(
            ['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post'],
            (string) Str::uuid(), // scope_ref trỏ kho khác
        );
        $requestId = 'wwhn165-scope-'.Str::lower(Str::random(12));

        $forbidden = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
            [],
            $scoped + [
                'If-Match' => $fixture['version'],
                'Idempotency-Key' => 'wwhn165-scope-'.Str::lower(Str::random(8)),
                'X-Request-ID' => $requestId,
            ]
        );

        $forbidden->assertStatus(403);
        $this->assertSame('DATA_SCOPE_DENIED', $forbidden->json('error.code'));
        $forbidden->assertJsonMissingPath('trace');
        $forbidden->assertJsonMissingPath('exception');
        $this->assertSame($requestId, $forbidden->headers->get('X-Request-ID'));

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('stock_documents', [
            'id' => $fixture['document_id'], 'status' => 'SCANNING',
        ]);

        $this->assertSame($deniedBefore + 1, DB::table('audit_log')->where('result', 'DENIED')->count());
        $rows = DB::table('audit_log')->where('request_id', $requestId)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('AUTHZ_DATA_SCOPE_DENIED', $row->action);
        $this->assertSame('DENIED', $row->result);
        $this->assertSame('DATA_SCOPE_DENIED', $row->error_code);
        $this->assertNotNull($row->actor_id);
    }

    /**
     * Positive control: đủ permission + đúng scope thì Post Receipt đi qua bình
     * thường và KHÔNG có row DENIED — chứng minh guard không phải "chặn tất cả".
     */
    public function test_positive_control_in_scope_user_with_permission_posts_successfully(): void
    {
        $owner = $this->headersForRole('SUPER_ADMIN');
        $fixture = $this->preparedReceipt($owner, 'WWHN165-POS');

        $inScope = $this->headersForCustomRole(
            ['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post'],
            $fixture['warehouse_id'],
        );
        $requestId = 'wwhn165-pos-'.Str::lower(Str::random(12));
        $movementsBefore = DB::table('stock_movements')->count();

        $posted = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
            [],
            $inScope + [
                'If-Match' => $fixture['version'],
                'Idempotency-Key' => 'wwhn165-pos-'.Str::lower(Str::random(8)),
                'X-Request-ID' => $requestId,
            ]
        );

        $posted->assertOk();
        $this->assertGreaterThan(
            $movementsBefore,
            DB::table('stock_movements')->count(),
            'Post hợp lệ phải sinh movement.'
        );
        $this->assertSame(
            0,
            DB::table('audit_log')->where('request_id', $requestId)->where('result', 'DENIED')->count(),
            'Request hợp lệ không được có audit DENIED.'
        );
    }

    /**
     * BEST_EFFORT proof (ADR-AUDIT-002): ép cả audit_log lẫn audit_outbox chết →
     * response 403 vẫn nguyên trạng, không 500, envelope không đổi.
     */
    public function test_deny_response_is_unchanged_when_audit_write_is_unavailable(): void
    {
        $owner = $this->headersForRole('SUPER_ADMIN');
        $fixture = $this->preparedReceipt($owner, 'WWHN165-BEST');
        $viewer = $this->headersForCustomRole(['inbound.view']);

        // Đăng ký SAU khi fixture đã dựng xong để không giết audit của nghiệp vụ setup.
        AuditLog::creating(function (): void {
            throw new RuntimeException('WWHN-165 forced audit_log outage');
        });
        AuditOutbox::creating(function (): void {
            throw new RuntimeException('WWHN-165 forced audit_outbox outage');
        });

        $requestId = 'wwhn165-best-'.Str::lower(Str::random(12));
        $forbidden = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
            [],
            $viewer + [
                'If-Match' => $fixture['version'],
                'Idempotency-Key' => 'wwhn165-best-'.Str::lower(Str::random(8)),
                'X-Request-ID' => $requestId,
            ]
        );

        $forbidden->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $forbidden->json('error.code'));
        $this->assertFalse($forbidden->json('success'));
        $this->assertSame($requestId, $forbidden->json('meta.request_id'));
        $forbidden->assertJsonMissingPath('trace');
        $forbidden->assertJsonMissingPath('exception');

        $this->assertSame(
            0,
            DB::table('audit_log')->where('request_id', $requestId)->count(),
            'Audit outage thật thì không có row nào — nhưng 403 vẫn phải trả nguyên trạng.'
        );
        $this->assertDatabaseHas('stock_documents', [
            'id' => $fixture['document_id'], 'status' => 'SCANNING',
        ]);
    }

    /**
     * No-stack-trace fail-closed: APP_ENV=testing + APP_DEBUG=true (bật nhầm) —
     * cả 403 lẫn 500 đều không được chứa exception/file/line/trace.
     */
    public function test_stack_trace_never_leaks_even_when_debug_is_enabled_outside_local(): void
    {
        config(['app.debug' => true]);
        $this->assertTrue(app()->environment('testing'), 'Proof này chạy trên APP_ENV=testing.');

        // 403: permission deny với debug bật.
        $viewer = $this->headersForCustomRole(['inbound.view']);
        $forbidden = $this->postJson(
            '/api/v1/inbound-documents/'.Str::uuid().'/post-receipt',
            [],
            $viewer + ['If-Match' => '2', 'Idempotency-Key' => 'wwhn165-debug-403']
        );
        $forbidden->assertStatus(403);
        $forbidden->assertJsonMissingPath('trace');
        $forbidden->assertJsonMissingPath('exception');
        $forbidden->assertJsonMissingPath('file');
        $forbidden->assertJsonMissingPath('line');

        // 500: route ném RuntimeException thật — trước fix, debug=true là đủ để
        // ApiConventionMiddleware gắn lại exception/file/line/trace vào envelope.
        Route::get('/api/v1/wwhn165-boom', function (): void {
            throw new RuntimeException('WWHN-165 forced runtime failure');
        });

        $boom = $this->getJson('/api/v1/wwhn165-boom');
        $boom->assertStatus(500);
        $this->assertSame('INTERNAL_SERVER_ERROR', $boom->json('error.code'));
        $boom->assertJsonMissingPath('trace');
        $boom->assertJsonMissingPath('exception');
        $boom->assertJsonMissingPath('file');
        $boom->assertJsonMissingPath('line');
        $this->assertStringNotContainsString('vendor/laravel', (string) $boom->getContent());
    }

    /**
     * ADR-AUDIT-002 phạm vi: 401 anonymous KHÔNG ghi audit (không có actor xác
     * thực; chống unauthenticated write amplification).
     */
    public function test_anonymous_401_does_not_write_any_audit_row(): void
    {
        // Request ẩn danh phải là thao tác HTTP đầu tiên của test (bẫy JWT guard
        // giữ token của auth('api')->login() — xem Wwhn289SecurityRegressionProofTest).
        $anonymous = $this->postJson(
            '/api/v1/inbound-documents/'.Str::uuid().'/post-receipt',
            [],
            ['If-Match' => '2', 'Idempotency-Key' => 'wwhn165-anon']
        );

        $anonymous->assertStatus(401);
        $this->assertSame('UNAUTHENTICATED', $anonymous->json('error.code'));
        $this->assertSame(0, DB::table('audit_log')->count(), '401 anonymous không được ghi audit row nào.');
        $this->assertSame(0, DB::table('audit_outbox')->count());
    }

    /**
     * Lái toàn bộ ma trận lỗi hợp đồng WWHN-165 qua HTTP thật và dump body làm
     * samples cho release pack 1.0.0. Mỗi nhánh đều assert đúng HTTP + error code
     * trước khi capture — sample sai hợp đồng thì test đỏ, không lọt vào pack.
     */
    public function test_error_contract_samples_cover_release_pack_matrix(): void
    {
        // 401 — phải là thao tác HTTP đầu tiên (bẫy JWT guard nêu trên).
        $anonymous = $this->postJson(
            '/api/v1/inbound-documents/'.Str::uuid().'/post-receipt',
            [],
            ['If-Match' => '2', 'Idempotency-Key' => 'wwhn165-sample-anon']
        );
        $anonymous->assertStatus(401);
        $this->assertSame('UNAUTHENTICATED', $anonymous->json('error.code'));
        self::capture('401_UNAUTHENTICATED', $anonymous);

        $owner = $this->headersForRole('SUPER_ADMIN');
        $fixture = $this->preparedReceipt($owner, 'WWHN165-SAMPLE');

        // 403 ACCESS_DENIED — thiếu permission.
        $viewer = $this->headersForCustomRole(['inbound.view']);
        $accessDenied = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
            [],
            $viewer + ['If-Match' => $fixture['version'], 'Idempotency-Key' => 'wwhn165-sample-perm']
        );
        $accessDenied->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $accessDenied->json('error.code'));
        self::capture('403_ACCESS_DENIED', $accessDenied);

        // 403 DATA_SCOPE_DENIED — đủ permission, sai kho.
        $scoped = $this->headersForCustomRole(
            ['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post'],
            (string) Str::uuid(),
        );
        $scopeDenied = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
            [],
            $scoped + ['If-Match' => $fixture['version'], 'Idempotency-Key' => 'wwhn165-sample-scope']
        );
        $scopeDenied->assertStatus(403);
        $this->assertSame('DATA_SCOPE_DENIED', $scopeDenied->json('error.code'));
        self::capture('403_DATA_SCOPE_DENIED', $scopeDenied);

        // 428 PRECONDITION_REQUIRED — mutation thiếu If-Match.
        $precondition = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/scan",
            ['line_id' => $fixture['line_id'], 'quantity' => 1, 'container_id' => $fixture['container_id']],
            $owner
        );
        $precondition->assertStatus(428);
        $this->assertSame('PRECONDITION_REQUIRED', $precondition->json('error.code'));
        self::capture('428_PRECONDITION_REQUIRED', $precondition);

        // 412 STALE_VERSION — If-Match là version lỗi thời.
        $stale = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/scan",
            ['line_id' => $fixture['line_id'], 'quantity' => 1, 'container_id' => $fixture['container_id']],
            $owner + ['If-Match' => '999']
        );
        $stale->assertStatus(412);
        $this->assertSame('STALE_VERSION', $stale->json('error.code'));
        self::capture('412_STALE_VERSION', $stale);

        // 422 VALIDATION — hợp đồng scan-driven: header-only là hợp lệ, nên probe
        // validation là tạo phiếu THIẾU dst_warehouse_id (trường bắt buộc).
        $validation = $this->postJson(
            '/api/v1/inbound-documents',
            ['source_name' => 'WWHN-165 sample missing warehouse'],
            $owner
        );
        $validation->assertStatus(422);
        $this->assertNotNull($validation->json('error.code'));
        self::capture('422_VALIDATION', $validation);

        // 409 IDEMPOTENCY_CONFLICT — cùng Idempotency-Key, payload khác.
        $sharedKey = 'wwhn165-sample-idem-'.Str::lower(Str::random(8));
        $firstScan = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/scan",
            ['line_id' => $fixture['line_id'], 'quantity' => 1, 'container_id' => $fixture['container_id']],
            $owner + ['If-Match' => $fixture['version'], 'Idempotency-Key' => $sharedKey]
        );
        $firstScan->assertOk();
        $idempotencyConflict = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/scan",
            ['line_id' => $fixture['line_id'], 'quantity' => 2, 'container_id' => $fixture['container_id']],
            $owner + ['If-Match' => (string) $firstScan->json('data.version'), 'Idempotency-Key' => $sharedKey]
        );
        $idempotencyConflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $idempotencyConflict->json('error.code'));
        self::capture('409_IDEMPOTENCY_CONFLICT', $idempotencyConflict);

        // 409 STATE_CONFLICT — cập nhật phiếu đã POSTED.
        $posted = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
            [],
            $owner + [
                'If-Match' => (string) $firstScan->json('data.version'),
                'Idempotency-Key' => 'wwhn165-sample-post-'.Str::lower(Str::random(6)),
            ]
        );
        $posted->assertOk();
        $stateConflict = $this->patchJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}",
            ['source_name' => 'WWHN-165 sample update on posted document'],
            $owner + ['If-Match' => (string) $posted->json('data.version')]
        );
        $stateConflict->assertStatus(409);
        $this->assertSame('STATE_CONFLICT', $stateConflict->json('error.code'));
        self::capture('409_STATE_CONFLICT', $stateConflict);
    }

    // ────────────────────────────── fixtures ──────────────────────────────
    // Cùng mẫu với Wwhn289SecurityRegressionProofTest / Wwhn217RuntimeErrorContractTest.

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
        $user = $this->createUserWithPassword('wwhn165-'.Str::lower(Str::random(8)));
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
        $roleCode = 'WWHN165_'.Str::upper(Str::random(8));
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

        $user = $this->createUserWithPassword('wwhn165-scoped-'.Str::lower(Str::random(6)));
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
            'display_name' => 'WWHN-165 authz fixture',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    /**
     * Phiếu nhập ở trạng thái SCANNING (đã ghi nhận 1 đơn vị).
     *
     * @param  array<string,string>  $headers
     * @return array{document_id:string,line_id:string,container_id:string,sku_id:string,warehouse_id:string,version:string}
     */
    private function preparedReceipt(array $headers, string $skuCodePrefix): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hoa Nam');
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
            'source_name' => 'WWHN-165 authz fixture',
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
}
