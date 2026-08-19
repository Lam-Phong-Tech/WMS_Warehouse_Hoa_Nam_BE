<?php

namespace Tests\Feature;

use App\Support\IdempotencyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use RuntimeException;
use Tests\Feature\Concerns\PreparesPutawayFixtures;
use Tests\TestCase;

/**
 * WWHN-193 · DEV193-P1-03R — hợp đồng phục hồi retry của putaway confirm/cancel
 * (DEC-PTW-RETRY-001).
 *
 * Reviewer: "Retry hiện trả controlled 409 nhưng chưa formal recovery semantics" và yêu cầu
 * chốt response-loss/timeout/concurrent retry contract, có test timeout-after-commit và
 * same-operation-concurrent, để FE/BA biết cách phục hồi.
 *
 * Bài này chứng minh cả hai tầng của hợp đồng, và ở MỌI nhánh đều kiểm bất biến
 * stock-neutral (AC-SRS-009: putaway không sinh Inventory_Movement).
 *
 * Ghi ra `build/evidence/wwhn-193/retry-recovery-proof.json` để QA1 đối chiếu số liệu thô
 * thay vì tin lời DEV.
 */
class Wwhn193PutawayRetryRecoveryContractTest extends TestCase
{
    use PreparesPutawayFixtures;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private static array $evidence = [];

    public static function tearDownAfterClass(): void
    {
        if (self::$evidence !== []) {
            // Fail-closed như Wwhn289StockInvariantProofTest: không ghi được thì đỏ, không
            // im lặng để cổng đọc lại artifact cũ đã commit.
            $directory = dirname(__DIR__, 2).'/build/evidence/wwhn-193';
            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new RuntimeException("Không tạo được thư mục evidence: {$directory}");
            }
            $path = $directory.'/retry-recovery-proof.json';
            $written = file_put_contents(
                $path,
                json_encode(self::$evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            if ($written === false) {
                throw new RuntimeException("Không ghi được evidence: {$path}");
            }
            self::$evidence = [];
        }

        parent::tearDownAfterClass();
    }

    private function actor(string $prefix = 'ptw-retry'): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'SUPER_ADMIN', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => $prefix.'-'.Str::lower(Str::random(6)),
            'display_name' => 'Putaway Retry Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['headers' => ['Authorization' => "Bearer {$token}"], 'user_id' => (string) $user->user_id];
    }

    /**
     * @return array{box:Container, tray:Container, event_id:string, sku_id:string}
     */
    private function draftFor(array $headers, string $suffix): array
    {
        $sku = Sku::create([
            'sku_code' => 'COMP-RETRY-'.$suffix,
            'sku_name' => 'Component retry '.$suffix,
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $box = Container::create([
            'container_code' => 'BOX-RETRY-'.$suffix,
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);
        $tray = Container::create([
            'container_code' => 'TRAY-RETRY-'.$suffix,
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'version' => 1,
        ]);
        $this->seedPostedReceiptForBox((string) $box->container_id, (string) $sku->sku_id);

        $draft = $this->postJson('/api/v1/putaway-tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers)->assertCreated();

        return [
            'box' => $box,
            'tray' => $tray,
            'event_id' => (string) $draft->json('data.id'),
            'sku_id' => (string) $sku->sku_id,
        ];
    }

    /**
     * @return array{movements:int, event_version:int, event_status:string, box_version:int, box_parent:?string}
     */
    private function snapshot(string $eventId, string $boxId): array
    {
        $event = DB::table('putaway_events')->where('id', $eventId)->first();
        $box = DB::table('containers')->where('container_id', $boxId)->first();

        return [
            'movements' => DB::table('stock_movements')->count(),
            'event_version' => (int) $event->version,
            'event_status' => (string) $event->status,
            'box_version' => (int) $box->version,
            'box_parent' => $box->parent_container_id !== null ? (string) $box->parent_container_id : null,
        ];
    }

    // ── Tầng 1 · Có Idempotency-Key: replay xác định sau mất response ───────

    /**
     * Response-loss / timeout-after-commit: request ĐÃ commit nhưng client không nhận được
     * response, nên gửi lại đúng request cũ (cùng key, cùng If-Match, cùng payload).
     */
    public function test_confirm_retry_with_same_idempotency_key_replays_committed_result(): void
    {
        $actor = $this->actor();
        $fixture = $this->draftFor($actor['headers'], 'REPLAY');
        $key = 'ptw-confirm-replay-0001';

        $first = $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $actor['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key]
        );
        $first->assertOk()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        $afterFirst = $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id);

        // Lần gửi lại y hệt — mô phỏng client retry sau khi mất response.
        $second = $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $actor['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key]
        );
        $second->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.stock_effect', 'NONE');

        $afterSecond = $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id);

        // Replay không được đụng vào state lần hai.
        $this->assertSame($afterFirst, $afterSecond);
        $this->assertSame('CONFIRMED', $afterSecond['event_status']);
        $this->assertSame(2, $afterSecond['event_version']);
        $this->assertSame(0, $afterSecond['movements']);
        $this->assertSame(
            1,
            DB::table('putaway_events')->where('id', $fixture['event_id'])->where('status', 'CONFIRMED')->count()
        );

        self::$evidence['response_loss_replay'] = [
            'scenario' => 'timeout-after-commit; retry cùng key + cùng payload',
            'first' => ['http' => $first->getStatusCode(), 'replayed' => $first->json('data.replayed')],
            'retry' => ['http' => $second->getStatusCode(), 'replayed' => $second->json('data.replayed')],
            'state_after_first' => $afterFirst,
            'state_after_retry' => $afterSecond,
            'verdict' => 'PASS — replay trả kết quả cũ, không transition lần hai, movements=0',
        ];
    }

    public function test_confirm_retry_with_same_key_but_different_payload_is_idempotency_conflict(): void
    {
        $actor = $this->actor();
        $fixture = $this->draftFor($actor['headers'], 'DIFFPAYLOAD');
        $key = 'ptw-confirm-diffpayload-01';

        $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $actor['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key]
        )->assertOk();

        $before = $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id);

        $conflict = $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            ['note' => 'payload khác cho cùng một khoá'],
            $actor['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key]
        );
        // SRS §8.2 chỉ công nhận 4 mã 409; cùng key khác payload là IDEMPOTENCY_CONFLICT.
        $conflict->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame($before, $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id));

        self::$evidence['same_key_different_payload'] = [
            'http' => 409,
            'error_code' => (string) $conflict->json('error.code'),
            'state_unchanged' => true,
            'verdict' => 'PASS',
        ];
    }

    /**
     * Same-operation-concurrent: hai request cùng key chạy chồng nhau. Request đến sau gặp
     * lease chưa hết hạn -> 409 CONCURRENCY_CONFLICT chứ không phải "sai payload".
     */
    public function test_concurrent_retry_on_live_lease_returns_concurrency_conflict(): void
    {
        $actor = $this->actor();
        $fixture = $this->draftFor($actor['headers'], 'LEASE');
        $key = 'ptw-confirm-lease-0001';
        $operation = 'putaway_confirm_'.$fixture['event_id'];

        // Mô phỏng request #1 đang chạy dở: lease `processing` còn hạn của cùng (key, op, user).
        // request_hash phải là hash THẬT của chính request sắp gửi — nếu để null thì trên
        // PostgreSQL trigger mirror `idempotency_records` vi phạm NOT NULL, còn nếu để một
        // giá trị khác thì store sẽ trả IDEMPOTENCY_CONFLICT (khác payload) thay vì đúng
        // nhánh tranh chấp lease mà bài này cần chứng minh.
        $lockedRequest = Request::create("/api/v1/putaway-tasks/{$fixture['event_id']}/confirm", 'POST');
        DB::table(IdempotencyStore::TABLE)->insert([
            'idem_key' => $key,
            'user_id' => $actor['user_id'],
            'operation' => $operation,
            'business_key' => IdempotencyStore::businessKey($key, $operation, $actor['user_id']),
            'request_hash' => IdempotencyStore::requestHash($lockedRequest),
            'status' => 'processing',
            'locked_until' => now()->addMinutes(IdempotencyStore::LOCK_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id);

        $response = $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $actor['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key]
        );
        $response->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENCY_CONFLICT');

        $after = $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id);
        $this->assertSame($before, $after);
        $this->assertSame('DRAFT', $after['event_status']);
        $this->assertSame(0, $after['movements']);

        self::$evidence['concurrent_same_operation'] = [
            'http' => 409,
            'error_code' => (string) $response->json('error.code'),
            'event_status_after' => $after['event_status'],
            'movements' => $after['movements'],
            'verdict' => 'PASS — chỉ một lượt được chạy, lượt còn lại conflict và không ghi gì',
        ];
    }

    // ── Tầng 2 · Không có key: 409 kèm ngữ nghĩa phục hồi ───────────────────

    public function test_confirm_retry_without_key_returns_recovery_already_applied_by_caller(): void
    {
        $actor = $this->actor();
        $fixture = $this->draftFor($actor['headers'], 'RECOVERSELF');

        $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $actor['headers'] + ['If-Match' => '1']
        )->assertOk();

        $before = $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id);

        $retry = $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $actor['headers'] + ['If-Match' => '1']
        );
        $retry->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT')
            ->assertJsonPath('error.details.recovery.contract', 'DEC-PTW-RETRY-001')
            ->assertJsonPath('error.details.recovery.outcome', 'ALREADY_APPLIED_BY_CALLER')
            ->assertJsonPath('error.details.recovery.applied_by_caller', true)
            ->assertJsonPath('error.details.recovery.safe_to_treat_as_success', true)
            ->assertJsonPath('error.details.recovery.next_action', 'RELOAD_AND_CONTINUE')
            ->assertJsonPath('error.details.current_status', 'CONFIRMED')
            ->assertJsonPath('error.details.current_version', 2)
            ->assertJsonPath('error.details.expected_version', 1);

        $this->assertSame($before, $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id));

        self::$evidence['recovery_without_key_same_actor'] = [
            'http' => 409,
            'error_code' => 'STATE_CONFLICT',
            'recovery' => $retry->json('error.details.recovery'),
            'verdict' => 'PASS — FE phân biệt được "chính mình đã làm xong"',
        ];
    }

    public function test_confirm_retry_by_other_actor_is_reported_as_applied_by_other(): void
    {
        $owner = $this->actor('ptw-owner');
        $fixture = $this->draftFor($owner['headers'], 'RECOVEROTHER');

        $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $owner['headers'] + ['If-Match' => '1']
        )->assertOk();

        // Người thứ hai cầm cùng dữ liệu cũ trên màn hình và bấm confirm.
        $other = $this->actor('ptw-other');
        $retry = $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $other['headers'] + ['If-Match' => '1']
        );
        $retry->assertStatus(409)
            ->assertJsonPath('error.details.recovery.outcome', 'ALREADY_APPLIED_BY_OTHER')
            ->assertJsonPath('error.details.recovery.applied_by_caller', false)
            ->assertJsonPath('error.details.recovery.safe_to_treat_as_success', true);

        // Không lộ danh tính actor khác trong envelope lỗi.
        $this->assertStringNotContainsString($owner['user_id'], (string) $retry->getContent());

        self::$evidence['recovery_without_key_other_actor'] = [
            'http' => 409,
            'recovery' => $retry->json('error.details.recovery'),
            'leaks_other_actor_id' => false,
            'verdict' => 'PASS',
        ];
    }

    public function test_cancel_after_confirm_is_reported_as_finalized_different_outcome(): void
    {
        $actor = $this->actor();
        $fixture = $this->draftFor($actor['headers'], 'DIFFOUTCOME');

        $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $actor['headers'] + ['If-Match' => '1']
        )->assertOk();

        $before = $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id);

        $cancel = $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/cancel",
            [],
            $actor['headers'] + ['If-Match' => '1']
        );
        $cancel->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT')
            ->assertJsonPath('error.details.recovery.outcome', 'FINALIZED_DIFFERENT_OUTCOME')
            ->assertJsonPath('error.details.recovery.safe_to_treat_as_success', false)
            ->assertJsonPath('error.details.recovery.next_action', 'RELOAD_AND_REVIEW');

        $this->assertSame($before, $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id));

        self::$evidence['cancel_after_confirm'] = [
            'http' => 409,
            'recovery' => $cancel->json('error.details.recovery'),
            'verdict' => 'PASS — terminal khác nhau không được coi là thành công',
        ];
    }

    public function test_cancel_retry_with_same_idempotency_key_replays_and_stays_stock_neutral(): void
    {
        $actor = $this->actor();
        $fixture = $this->draftFor($actor['headers'], 'CANCELREPLAY');
        $key = 'ptw-cancel-replay-0001';

        $first = $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/cancel",
            [],
            $actor['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key]
        );
        $first->assertOk()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.replayed', false);

        $after = $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id);

        $second = $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/cancel",
            [],
            $actor['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key]
        );
        $second->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($after, $this->snapshot($fixture['event_id'], (string) $fixture['box']->container_id));
        $this->assertSame(0, $after['movements']);
        $this->assertNull($after['box_parent']);

        self::$evidence['cancel_replay'] = [
            'first' => ['http' => 200, 'replayed' => false],
            'retry' => ['http' => 200, 'replayed' => true],
            'state_after' => $after,
            'verdict' => 'PASS',
        ];
    }

    /**
     * Idempotency-Key vẫn là TUỲ CHỌN đúng Annex C (cột Idempotency/If-Match của
     * confirmPutawayTask/cancelPutawayTask chỉ khai If-Match): thiếu header không được 428.
     */
    public function test_missing_idempotency_key_is_not_a_precondition_error(): void
    {
        $actor = $this->actor();
        $fixture = $this->draftFor($actor['headers'], 'NOKEY');

        $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $actor['headers'] + ['If-Match' => '1']
        )->assertOk()->assertJsonPath('data.replayed', false);

        // If-Match thì vẫn bắt buộc như trước.
        $fixture2 = $this->draftFor($actor['headers'], 'NOIFMATCH');
        $this->postJson("/api/v1/putaway-tasks/{$fixture2['event_id']}/confirm", [], $actor['headers'])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        self::$evidence['idempotency_key_optional'] = [
            'confirm_without_key' => 200,
            'confirm_without_if_match' => 428,
            'verdict' => 'PASS — không phá contract Annex C',
        ];
    }

    public function test_idempotency_key_shorter_than_contract_minimum_is_rejected(): void
    {
        $actor = $this->actor();
        $fixture = $this->draftFor($actor['headers'], 'SHORTKEY');

        $this->postJson(
            "/api/v1/putaway-tasks/{$fixture['event_id']}/confirm",
            [],
            $actor['headers'] + ['If-Match' => '1', 'Idempotency-Key' => 'abc']
        )->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseHas('putaway_events', ['id' => $fixture['event_id'], 'status' => 'DRAFT']);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }
}
