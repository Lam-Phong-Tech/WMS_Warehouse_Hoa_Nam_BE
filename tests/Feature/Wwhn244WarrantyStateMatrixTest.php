<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\TestCase;

/**
 * WWHN-244 — DEV244-P1-02 (ma trận trạng thái/guard đã chốt) và DEV244-P1-06
 * (one-active-case phải là ràng buộc tầng DB, không chỉ guard trong controller).
 */
class Wwhn244WarrantyStateMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'war244-'.Str::lower(Str::random(6)),
            'display_name' => 'Warranty Matrix Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => 'war244-'.(string) Str::uuid()];
    }

    private function issuedItem(string $suffix): ItemInstance
    {
        $sku = Sku::create([
            'sku_code' => 'PROD-244-'.$suffix,
            'sku_name' => 'San pham bao hanh '.$suffix,
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);

        return ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-244-'.$suffix,
            'item_status' => 'ISSUED',
        ]);
    }

    private function createCase(array $headers, ItemInstance $item): string
    {
        $response = $this->postJson('/api/v1/warranty-cases', [
            'item_id' => $item->item_id,
            'customer_name' => 'Nguyen Van A',
            'customer_phone' => '0901234567',
            'description' => 'May khong khoi dong',
        ], $headers + $this->idem());
        $response->assertCreated()->assertJsonPath('data.status', 'RECEIVED');

        return (string) $response->json('data.id');
    }

    private function patchCase(array $headers, string $caseId, array $payload, int $version)
    {
        return $this->patchJson(
            "/api/v1/warranty-cases/{$caseId}",
            $payload,
            $headers + $this->idem() + ['If-Match' => (string) $version]
        );
    }

    /**
     * DEC-WAR-STATE-001: CHECKING -> COMPLETED được phép khi đã có kết luận kiểm tra
     * và kết quả xử lý; case không phải đi vòng qua REPAIRING giả.
     */
    public function test_checking_to_completed_is_allowed_with_diagnosis_and_treatment_result(): void
    {
        $headers = $this->authHeaders();
        $caseId = $this->createCase($headers, $this->issuedItem('A'));

        $this->patchCase($headers, $caseId, ['status' => 'CHECKING'], 1)
            ->assertOk()->assertJsonPath('data.status', 'CHECKING');

        $this->patchCase($headers, $caseId, [
            'status' => 'COMPLETED',
            'confirmed_defect' => 'Kiem tra khong phat hien loi, do thao tac nguoi dung',
            'note' => 'Ve sinh, huong dan su dung, khong thay linh kien',
        ], 2)->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        $this->assertDatabaseHas('warranty_event', [
            'warranty_case_id' => $caseId,
            'event_type' => 'COMPLETE',
            'from_status' => 'CHECKING',
            'to_status' => 'COMPLETED',
        ]);
    }

    /** Guard 1: không rời CHECKING khi chưa ghi confirmed_defect. */
    public function test_leaving_checking_without_diagnosis_is_rejected(): void
    {
        $headers = $this->authHeaders();
        $caseId = $this->createCase($headers, $this->issuedItem('B'));
        $this->patchCase($headers, $caseId, ['status' => 'CHECKING'], 1)->assertOk();

        foreach (['REPAIRING', 'COMPLETED'] as $target) {
            $this->patchCase($headers, $caseId, ['status' => $target, 'note' => 'xong'], 2)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'WARRANTY_DIAGNOSIS_REQUIRED');
        }

        $this->assertDatabaseHas('warranty_case', [
            'warranty_case_id' => $caseId,
            'status' => 'CHECKING',
            'version' => 2,
        ]);
    }

    /** Guard 2/3: COMPLETED cần kết quả xử lý, RETURNED cần biên bản bàn giao. */
    public function test_completed_and_returned_require_treatment_and_handover_evidence(): void
    {
        $headers = $this->authHeaders();
        $caseId = $this->createCase($headers, $this->issuedItem('C'));
        $this->patchCase($headers, $caseId, ['status' => 'CHECKING'], 1)->assertOk();
        $this->patchCase($headers, $caseId, [
            'status' => 'REPAIRING',
            'confirmed_defect' => 'Hong pin 20V',
        ], 2)->assertOk()->assertJsonPath('data.status', 'REPAIRING');

        $this->patchCase($headers, $caseId, ['status' => 'COMPLETED'], 3)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_TREATMENT_RESULT_REQUIRED');

        $this->patchCase($headers, $caseId, [
            'status' => 'COMPLETED',
            'note' => 'Da thay pin 20V',
        ], 3)->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        $this->patchCase($headers, $caseId, ['status' => 'RETURNED'], 4)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_HANDOVER_REQUIRED');

        $handover = [
            'status' => 'RETURNED',
            'receiver' => 'Nguyen Van A',
            'handover_date' => '2026-10-15',
            'handover_checked' => true,
            'note' => 'Khach da kiem tra va ky nhan.',
        ];
        $returned = $this->patchCase($headers, $caseId, $handover, 4)
            ->assertOk()->assertJsonPath('data.status', 'RETURNED')
            ->assertJsonPath('data.handover.receiver', 'Nguyen Van A')
            ->assertJsonPath('data.handover.handover_date', '2026-10-15')
            ->assertJsonPath('data.handover.handover_checked', true)
            ->assertJsonPath('data.handover.note', 'Khach da kiem tra va ky nhan.')
            ->assertJsonPath('data.handover.returned_at_source', 'warranty_case.returned_at_server_time');
        $this->assertNotNull($returned->json('data.returned_at'));
        $this->assertDatabaseHas('warranty_handovers', ['warranty_case_id' => $caseId, 'handover_date' => '2026-10-15']);

        $this->assertDatabaseHas('warranty_event', [
            'warranty_case_id' => $caseId,
            'event_type' => 'RETURN',
            'note' => 'Khach da kiem tra va ky nhan.',
        ]);
    }

    public function test_return_rejects_stale_version_and_replays_same_key_without_second_transition(): void
    {
        $headers = $this->authHeaders();
        $caseId = $this->createCase($headers, $this->issuedItem('RETRY'));
        $this->patchCase($headers, $caseId, ['status' => 'CHECKING'], 1)->assertOk();
        $this->patchCase($headers, $caseId, ['status' => 'REPAIRING', 'confirmed_defect' => 'Da sua'], 2)->assertOk();
        $this->patchCase($headers, $caseId, ['status' => 'COMPLETED', 'note' => 'Da sua xong'], 3)->assertOk();

        $payload = [
            'status' => 'RETURNED', 'receiver' => 'Nguoi nhan retry',
            'handover_date' => '2026-10-15', 'handover_checked' => true,
            'note' => 'Retry handover proof',
        ];
        $key = 'war244-return-'.Str::uuid();
        $requestId = (string) Str::uuid();
        $requestHeaders = $headers + ['If-Match' => '3', 'Idempotency-Key' => $key, 'X-Request-ID' => $requestId];
        $this->patchJson("/api/v1/warranty-cases/{$caseId}", $payload, $requestHeaders)
            ->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->assertDatabaseHas('warranty_case', ['warranty_case_id' => $caseId, 'status' => 'COMPLETED', 'version' => 4]);

        $requestHeaders['If-Match'] = '4';
        $first = $this->patchJson("/api/v1/warranty-cases/{$caseId}", $payload, $requestHeaders)
            ->assertOk()->assertJsonPath('data.status', 'RETURNED');
        $second = $this->patchJson("/api/v1/warranty-cases/{$caseId}", $payload, $requestHeaders)
            ->assertOk()->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.version', $first->json('data.version'));
        $this->assertSame($first->json('data.returned_at'), $second->json('data.returned_at'));
        $this->assertDatabaseCount('warranty_handovers', 1);
        $this->assertCount(1, DB::table('warranty_event')->where('warranty_case_id', $caseId)->where('event_type', 'RETURN')->get());
    }

    /**
     * Ma trận đầy đủ: mọi cặp (from, to) ngoài DEC-WAR-STATE-001 phải trả 409 và
     * không đổi trạng thái. Chốt bằng test để BE/FE/OpenAPI không trôi khỏi nhau.
     */
    public function test_full_transition_matrix_rejects_every_undeclared_edge(): void
    {
        $allowed = [
            'RECEIVED' => ['CHECKING', 'CANCELLED'],
            'CHECKING' => ['REPAIRING', 'COMPLETED', 'CANCELLED'],
            'REPAIRING' => ['COMPLETED', 'CANCELLED'],
            'COMPLETED' => ['RETURNED', 'CANCELLED'],
        ];
        $states = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'];
        $headers = $this->authHeaders();
        $index = 0;

        foreach ($allowed as $from => $targets) {
            foreach ($states as $to) {
                if ($to === $from || in_array($to, $targets, true)) {
                    continue;
                }
                [$caseId, $version] = $this->caseAtStatus($headers, $from, 'M'.($index++));
                $this->patchCase($headers, $caseId, [
                    'status' => $to,
                    'confirmed_defect' => 'chan doan day du',
                    'note' => 'ghi chu day du',
                ], $version)
                    ->assertStatus(409)
                    ->assertJsonPath('error.code', 'INVALID_WARRANTY_TRANSITION');
                $this->assertDatabaseHas('warranty_case', [
                    'warranty_case_id' => $caseId,
                    'status' => $from,
                ]);
            }
        }

        // Terminal: RETURNED/CANCELLED không nhận mutation nào, kể cả khi không kèm status.
        foreach (['RETURNED', 'CANCELLED'] as $terminal) {
            [$caseId, $version] = $this->caseAtStatus($headers, $terminal, 'T'.($index++));
            $this->patchCase($headers, $caseId, ['confirmed_defect' => 'sua sau khi dong'], $version)
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'STATE_CONFLICT');
        }
    }

    /**
     * Đưa một case mới tới trạng thái yêu cầu.
     *
     * @return array{0: string, 1: int}
     */
    private function caseAtStatus(array $headers, string $status, string $suffix): array
    {
        $caseId = $this->createCase($headers, $this->issuedItem($suffix));
        $version = 1;
        $path = match ($status) {
            'CHECKING' => [['status' => 'CHECKING']],
            'REPAIRING' => [['status' => 'CHECKING'], ['status' => 'REPAIRING', 'confirmed_defect' => 'chan doan']],
            'COMPLETED' => [['status' => 'CHECKING'], ['status' => 'COMPLETED', 'confirmed_defect' => 'chan doan', 'note' => 'da xu ly']],
            'RETURNED' => [
                ['status' => 'CHECKING'],
                ['status' => 'COMPLETED', 'confirmed_defect' => 'chan doan', 'note' => 'da xu ly'],
                ['status' => 'RETURNED', 'receiver' => 'Khach hang', 'handover_date' => '2026-10-15',
                    'handover_checked' => true, 'note' => 'ban giao khach'],
            ],
            'CANCELLED' => [['status' => 'CANCELLED', 'cancel_reason' => 'Khach rut may']],
            default => [],
        };

        foreach ($path as $payload) {
            $this->patchCase($headers, $caseId, $payload, $version)->assertOk();
            $version++;
        }

        return [$caseId, $version];
    }

    /**
     * DEV244-P1-06: phpunit.xml khai DB_CONNECTION=sqlite mà không có force="true",
     * nên biến môi trường của job CI thắng thế. Test này chạy TRONG tiến trình
     * phpunit nên là chỗ duy nhất chứng minh được suite thật sự đang nói chuyện với
     * PostgreSQL — nếu không, job "PostgreSQL" chỉ là bằng chứng giả chạy trên SQLite.
     */
    public function test_suite_runs_on_the_driver_the_pipeline_claims(): void
    {
        $required = (string) getenv('WARRANTY_REQUIRE_DB_DRIVER');
        if ($required === '') {
            $this->markTestSkipped('WARRANTY_REQUIRE_DB_DRIVER không được đặt; chỉ gate trong CI.');
        }

        $this->assertSame($required, DB::connection()->getDriverName());
    }

    /**
     * DEV244-P1-06: ràng buộc phải nằm ở tầng DB. Test này ghi thẳng vào bảng, bỏ
     * qua controller, nên chỉ pass khi partial unique index thực sự tồn tại trên
     * driver đang chạy (pgsql lẫn sqlite sau migration 2026_08_18_000100).
     */
    public function test_second_active_case_for_same_item_is_blocked_at_database_level(): void
    {
        $headers = $this->authHeaders();
        $item = $this->issuedItem('DB');
        $this->createCase($headers, $item);

        $this->expectException(QueryException::class);
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => 'WC-BYPASS-'.Str::upper(Str::random(6)),
            'item_id' => $item->item_id,
            'product_id' => $item->sku_id,
            'identification_status' => 'IDENTIFIED',
            'sales_trace_status' => 'TRACEABLE',
            'customer_name' => 'Bypass Actor',
            'customer_phone' => '0900000000',
            'status' => 'RECEIVED',
            'description' => 'Ghi thang vao bang, bo qua API guard',
            'reported_defect' => 'Ghi thang vao bang, bo qua API guard',
            'received_at' => now(),
            'version' => 1,
            'legal_hold_flag' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** Sau khi case đóng (RETURNED/CANCELLED) item được nhận bảo hành lần kế tiếp. */
    public function test_closed_case_releases_item_for_a_new_warranty_case(): void
    {
        $headers = $this->authHeaders();
        $item = $this->issuedItem('RE');
        $caseId = $this->createCase($headers, $item);

        $this->patchCase($headers, $caseId, ['status' => 'CANCELLED', 'cancel_reason' => 'Khach rut may'], 1)
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        DB::table('item_instances')->where('item_id', $item->item_id)->update(['item_status' => 'ISSUED']);

        $this->postJson('/api/v1/warranty-cases', [
            'item_id' => $item->item_id,
            'customer_name' => 'Nguyen Van A',
            'customer_phone' => '0901234567',
            'description' => 'Tiep nhan lai',
        ], $headers + $this->idem())->assertCreated()->assertJsonPath('data.status', 'RECEIVED');
    }
}
