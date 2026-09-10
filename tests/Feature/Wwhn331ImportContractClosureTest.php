<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\SkuImportContract;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tests\TestCredentials;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-331 review R3 (77/100) — đóng ba mục còn lại thuộc code:
 *
 * - FE331-P1-02R3: `update_existing` là một phần ĐỊNH DANH của lần import, nên cùng
 *   file + khác tùy chọn không được coi là replay.
 * - FE331-P1-03R3: hạn mức 1000 dòng / 10MB / 24h không còn là hằng số "tạm" nằm rải
 *   rác; API phơi hợp đồng đã duyệt ra cho client qua `constraints`.
 * - FE331-P1-06R3: data-scope của Import báo cáo được định nghĩa và enforce thật —
 *   `sku.manage` không đồng nghĩa với "đọc được lần import của mọi người".
 */
class Wwhn331ImportContractClosureTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.write_policy' => 'fail_closed']);
        $this->seed(AuthDatabaseSeeder::class);
        $user = User::query()->where('email', 'test-super-admin@gmail.com')->firstOrFail();
        $this->token = JWTAuth::fromUser($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ── FE331-P1-02R3: định danh replay phải gồm cả tùy chọn ────────────────

    public function test_same_file_with_a_different_update_existing_option_is_not_a_replay(): void
    {
        [$first, $second] = $this->identicalXlsxPair(['code', 'name'], [
            ['OPTID-001', 'Ten ban dau'],
        ]);

        $create = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $first, 'update_existing' => false]);

        $create->assertOk()
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.inserted_rows', 1)
            ->assertJsonPath('data.update_existing', false);

        // Cùng nội dung file, cùng actor, cùng cửa sổ thời gian — nhưng người dùng bật
        // update_existing lên. Đây là một thao tác nghiệp vụ KHÁC (cho phép ghi đè SKU
        // đang tồn tại), không phải gửi lại cùng một thao tác, nên phải chạy thật.
        $rerun = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $second, 'update_existing' => true]);

        $rerun->assertOk()
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.updated_rows', 1)
            ->assertJsonPath('data.update_existing', true);

        $this->assertSame(
            1,
            Sku::query()->where('sku_code', 'OPTID-001')->count(),
            'Chạy lại với tùy chọn khác vẫn phải all-or-nothing trên đúng 1 SKU, không nhân bản.'
        );

        $reports = ImportReport::query()
            ->where('import_type', 'SKU')
            ->where('mode', 'COMMIT')
            ->get();

        $this->assertCount(2, $reports, 'Hai tùy chọn khác nhau phải để lại hai Import báo cáo độc lập.');
        $this->assertEqualsCanonicalizing(
            [
                SkuImportContract::optionsFingerprint(false),
                SkuImportContract::optionsFingerprint(true),
            ],
            $reports->pluck('options_fingerprint')->all(),
            'Mỗi báo cáo phải lưu đúng vân tay tùy chọn của lần chạy đó.'
        );
    }

    public function test_same_file_with_the_same_options_is_still_an_idempotent_replay(): void
    {
        [$first, $second] = $this->identicalXlsxPair(['code', 'name'], [
            ['OPTID-002', 'Ten ban dau'],
        ]);

        $create = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $first, 'update_existing' => true]);
        $create->assertOk()->assertJsonPath('data.duplicate', false);
        $originalReportId = $create->json('data.import_report_id');

        $replay = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $second, 'update_existing' => true]);

        $replay->assertOk()
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.duplicate_of_import_report_id', $originalReportId)
            ->assertJsonPath('data.import_report_id', $originalReportId);

        $this->assertSame(
            1,
            ImportReport::query()->where('import_type', 'SKU')->where('mode', 'COMMIT')->count(),
            'Replay đúng định danh không được tạo Import báo cáo thứ hai.'
        );
        $this->assertTrue(
            (bool) ImportReport::query()->findOrFail($originalReportId)->duplicate_of_replay,
            'Bản ghi gốc phải được đánh dấu đã từng bị phát lại (bằng chứng reconcile).'
        );
    }

    public function test_replay_identity_is_reproducible_from_the_documented_canonical_string(): void
    {
        // QA phải dựng lại được vân tay mà không cần đọc code: chuỗi chuẩn hoá ghi trong
        // DEC-IMP-001 là `update_existing=0|1`, băm bằng sha256.
        $this->assertSame('update_existing=0', SkuImportContract::canonicalOptions(false));
        $this->assertSame('update_existing=1', SkuImportContract::canonicalOptions(true));
        $this->assertSame(hash('sha256', 'update_existing=1'), SkuImportContract::optionsFingerprint(true));
        $this->assertNotSame(
            SkuImportContract::optionsFingerprint(false),
            SkuImportContract::optionsFingerprint(true)
        );
    }

    // ── FE331-P1-03R3: hạn mức đến từ hợp đồng đã duyệt, không phải hằng số rải rác ──

    public function test_every_import_response_carries_the_approved_constraints_contract(): void
    {
        $expected = [
            'decision_ref' => config('warehouse.sku_import.decision_ref'),
            'max_rows' => (int) config('warehouse.sku_import.max_rows'),
            'max_file_size_kb' => (int) config('warehouse.sku_import.max_file_size_kb'),
            'error_file_expiry_hours' => (int) config('warehouse.sku_import.error_file_expiry_hours'),
            'idempotency_window_hours' => (int) config('warehouse.sku_import.idempotency_window_hours'),
        ];

        $ok = $this->withHeaders($this->headers())->post('/api/v1/skus/import-excel', [
            'file' => $this->xlsx(['code', 'name'], [['CONSTR-001', 'Hop le']]),
            'dry_run' => true,
        ]);
        $ok->assertOk();
        foreach ($expected as $key => $value) {
            $ok->assertJsonPath("data.constraints.{$key}", $value);
        }
        $this->assertSame(['file_sha256', 'actor_id', 'update_existing'], $ok->json('data.constraints.idempotency_identity'));

        // Ca 422 (có row lỗi) cũng phải mang hợp đồng: UI hiển thị hạn mức ngay cả khi
        // lần gọi vừa rồi bị từ chối.
        $rejected = $this->withHeaders($this->headers())->post('/api/v1/skus/import-excel', [
            'file' => $this->xlsx(['code', 'name'], [['DUPC-001', 'A'], ['DUPC-001', 'B']]),
        ]);
        $rejected->assertStatus(422)
            ->assertJsonPath('data.constraints.max_rows', $expected['max_rows']);
    }

    public function test_row_limit_is_enforced_from_the_contract_not_a_hard_coded_number(): void
    {
        // Hạ hạn mức qua đúng hợp đồng config rồi kiểm tra máy chủ chặn theo giá trị mới —
        // bằng chứng rằng 1000 là một giá trị cấu hình đã duyệt, không phải số cứng.
        config(['warehouse.sku_import.max_rows' => 2]);

        $rows = [['LIMIT-001', 'A'], ['LIMIT-002', 'B'], ['LIMIT-003', 'C']];

        $response = $this->withHeaders($this->headers())->post('/api/v1/skus/import-excel', [
            'file' => $this->xlsx(['code', 'name'], $rows),
            'dry_run' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_ROW_LIMIT_EXCEEDED')
            ->assertJsonPath('data.max_rows', 2)
            ->assertJsonPath('data.actual_rows', 3)
            ->assertJsonPath('data.constraints.max_rows', 2);
    }

    // ── FE331-P1-06R3: data-scope của Import báo cáo ────────────────────────

    public function test_report_list_meta_exposes_constraints_and_effective_data_scope(): void
    {
        $response = $this->withHeaders($this->headers())->get('/api/v1/skus/import-reports');

        $response->assertOk()
            ->assertJsonPath('meta.data_scope', 'GLOBAL')
            ->assertJsonPath('meta.constraints.max_rows', (int) config('warehouse.sku_import.max_rows'))
            ->assertJsonPath('meta.constraints.decision_ref', config('warehouse.sku_import.decision_ref'));
    }

    public function test_warehouse_scoped_actor_cannot_read_or_download_another_actors_import_report(): void
    {
        $owner = $this->scopedSkuManager('import_owner_331');
        $stranger = $this->scopedSkuManager('import_stranger_331');

        // Người A chạy import có dòng lỗi → sinh báo cáo + file lỗi CSV của riêng A.
        $import = $this->withHeaders($this->authHeaders($owner))->post('/api/v1/skus/import-excel', [
            'file' => $this->xlsx(['code', 'name'], [['SCOPE-001', 'A'], ['SCOPE-001', 'B']]),
        ]);
        $import->assertStatus(422);
        $reportId = $import->json('data.import_report_id');
        $this->assertNotNull($reportId);

        // Người A đọc được của chính mình.
        $this->withHeaders($this->authHeaders($owner))
            ->get("/api/v1/skus/import-reports/{$reportId}")
            ->assertOk()
            ->assertJsonPath('data.import_report_id', $reportId);
        $this->withHeaders($this->authHeaders($owner))
            ->get("/api/v1/skus/import-reports/{$reportId}/error-file")
            ->assertOk();
        $ownerList = $this->withHeaders($this->authHeaders($owner))->get('/api/v1/skus/import-reports');
        $ownerList->assertOk()->assertJsonPath('meta.data_scope', 'ACTOR_SCOPED');
        $this->assertSame([$reportId], collect($ownerList->json('data'))->pluck('import_report_id')->all());

        // Người B có ĐỦ permission sku.manage nhưng không có quyền đọc lần import của A:
        // 404 y hệt bản ghi không tồn tại, không xác nhận sự tồn tại.
        $this->withHeaders($this->authHeaders($stranger))
            ->get("/api/v1/skus/import-reports/{$reportId}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->withHeaders($this->authHeaders($stranger))
            ->get("/api/v1/skus/import-reports/{$reportId}/error-file")
            ->assertStatus(404);

        $strangerList = $this->withHeaders($this->authHeaders($stranger))->get('/api/v1/skus/import-reports');
        $strangerList->assertOk()->assertJsonPath('meta.data_scope', 'ACTOR_SCOPED');
        $this->assertSame([], $strangerList->json('data'));

        // Vai trò không giới hạn kho vẫn đối soát chéo được (GLOBAL).
        $this->withHeaders($this->headers())
            ->get("/api/v1/skus/import-reports/{$reportId}")
            ->assertOk();
        $this->withHeaders($this->headers())
            ->get("/api/v1/skus/import-reports/{$reportId}/error-file")
            ->assertOk();
    }

    public function test_missing_sku_manage_is_still_rejected_before_data_scope(): void
    {
        $role = Role::create(['role_code' => 'NO_SKU_MANAGE_331', 'role_name' => 'No sku manage 331', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $permission = Permission::firstOrCreate(
            ['permission_key' => 'warehouse.view'],
            ['permission_name' => 'warehouse.view', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );
        $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);

        $user = User::create([
            'username' => 'no_sku_manage_331',
            'display_name' => 'No sku manage 331',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $this->withHeaders($this->authHeaders($user))
            ->get('/api/v1/skus/import-reports')
            ->assertStatus(403);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Người dùng có sku.manage nhưng BỊ giới hạn kho → data-scope ACTOR_SCOPED. */
    private function scopedSkuManager(string $username): User
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SKU_MANAGER_SCOPED_331'],
            ['role_name' => 'Sku manager scoped 331', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );
        $permission = Permission::firstOrCreate(
            ['permission_key' => 'sku.manage'],
            ['permission_name' => 'sku.manage', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );
        // syncWithoutDetaching thay vì attach: helper này được gọi cho nhiều người dùng
        // dùng chung một role, và tên bảng permission khác nhau giữa sqlite/pgsql nên
        // không thể qualify cột trong điều kiện exists().
        $role->permissions()->syncWithoutDetaching([
            $permission->permission_id => ['role_permission_id' => (string) Str::uuid()],
        ]);

        $user = User::create([
            'username' => $username,
            'display_name' => $username,
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        // scope_ref khác rỗng = bị giới hạn kho; đây chính là nguồn scope mà
        // EnforceInventoryWarehouseScope và /reports/dashboard-quick đang dùng.
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => (string) Str::uuid(),
        ]);

        return $user;
    }

    /** @return array<string, string> */
    private function authHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     * @return array{0: UploadedFile, 1: UploadedFile}
     */
    private function identicalXlsxPair(array $headers, array $rows): array
    {
        $first = $this->xlsx($headers, $rows);
        $copyPath = sys_get_temp_dir().'/wwhn331-copy-'.bin2hex(random_bytes(8)).'.xlsx';
        copy((string) $first->getRealPath(), $copyPath);
        $this->tempFiles[] = $copyPath;

        return [$first, new UploadedFile(
            $copyPath,
            basename($copyPath),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        )];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function xlsx(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        $path = sys_get_temp_dir().'/wwhn331-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tempFiles[] = $path;

        return new UploadedFile(
            $path,
            basename($path),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }
}
