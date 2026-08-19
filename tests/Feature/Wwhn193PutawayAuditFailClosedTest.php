<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesPutawayFixtures;
use Tests\TestCase;

/**
 * DEV193-P1-04R: failure-injection cho AuditLogger (fail_closed) trên vòng đời
 * putaway — chứng minh khi audit_log VÀ audit_outbox đều ghi lỗi (hoặc policy
 * fail_closed yêu cầu fail dù outbox ghi được), business state (container
 * relocation + putaway_events) rollback cùng transaction, không tạo trạng thái
 * "đã xếp hàng nhưng mất dấu vết audit". Kỹ thuật injection theo đúng pattern
 * đã có ở Wwhn172AuditFailClosedTest (event eloquent.creating chặn AuditLog::create).
 */
class Wwhn193PutawayAuditFailClosedTest extends TestCase
{
    use PreparesPutawayFixtures;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private static array $evidence = [];

    private function authHeaders(): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'SUPER_ADMIN', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => 'ptw-audit-'.Str::lower(Str::random(6)),
            'display_name' => 'Putaway Audit Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_putaway_confirm_rolls_back_container_relocation_when_audit_write_fails_closed(): void
    {
        config(['audit.write_policy' => 'fail_closed', 'app.debug' => true]);
        $headers = $this->authHeaders();

        $sku = Sku::create([
            'sku_code' => 'COMP-AUDITFAIL-01',
            'sku_name' => 'Component AUDITFAIL',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $box = Container::create([
            'container_code' => 'BOX-AUDITFAIL-01',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);
        // DEV193-P1-05R: BOX phải neo vào phiếu nhập đã POSTED mới tạo được putaway task.
        $this->seedPostedReceiptForBox((string) $box->container_id, (string) $sku->sku_id);
        $tray = Container::create([
            'container_code' => 'TRAY-AUDITFAIL-01',
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        // DRAFT create phải chạy trước khi ta gắn injection, để không chặn nhầm
        // audit của chính thao tác tạo DRAFT.
        $draft = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers)->assertCreated();
        $eventId = (string) $draft->json('data.id');
        $auditCountBeforeConfirm = DB::table('audit_log')->count();
        $before = $this->stateSnapshot($eventId, (string) $box->container_id);

        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new \RuntimeException('sensitive-audit-failure /vendor/project/secret.php:77');
        });

        try {
            $response = $this->postJson(
                "/api/v1/putaway/tasks/{$eventId}/confirm",
                [],
                $headers + ['If-Match' => '1']
            );
        } finally {
            Event::forget($eventName);
        }

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INTERNAL_SERVER_ERROR')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('line')
            ->assertJsonMissingPath('trace');
        $payload = $response->getContent();
        $this->assertStringNotContainsString('sensitive-audit-failure', $payload);
        $this->assertStringNotContainsString('/vendor/', $payload);

        // Business state phải rollback nguyên vẹn: container vẫn ở BOX gốc,
        // chưa STORED; putaway_events vẫn DRAFT, chưa CONFIRMED.
        $this->assertDatabaseHas('containers', [
            'container_id' => $box->container_id,
            'parent_container_id' => null,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);
        $this->assertDatabaseHas('putaway_events', [
            'id' => $eventId,
            'status' => 'DRAFT',
            'version' => 1,
        ]);
        $this->assertSame($auditCountBeforeConfirm, DB::table('audit_log')->count(), 'Audit-fail phải rollback, không để lại audit_log row mồ côi cho lần confirm lỗi.');
        $this->assertSame(0, DB::table('audit_outbox')->count(), 'fail_closed rollback outer transaction phải cuốn theo cả outbox insert trong savepoint.');

        $after = $this->stateSnapshot($eventId, (string) $box->container_id);
        $this->assertSame($before, $after, 'DB before/after của lần confirm lỗi phải giống hệt nhau.');

        // DEV193-P1-04R yêu cầu chứng minh trên ĐÚNG engine đích, kèm DB before/after và
        // log thô. Ghi engine/env thật của lần chạy này để không thể ký nhầm bằng chứng
        // sqlite cho một cổng đòi PostgreSQL.
        self::$evidence = [
            'defect' => 'DEV193-P1-04R',
            'scenario' => 'Injected audit write failure (fail_closed) trong POST /putaway-tasks/{id}/confirm',
            'engine' => [
                'driver' => DB::connection()->getDriverName(),
                'database' => (string) DB::connection()->getDatabaseName(),
                'server_version' => (string) (DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION) ?? ''),
            ],
            'audit_write_policy' => (string) config('audit.write_policy'),
            'http_status' => $response->getStatusCode(),
            'error_code' => (string) $response->json('error.code'),
            'leaks_internal_detail' => false,
            'db_before' => $before,
            'db_after' => $after,
            'audit_log_rows_delta' => DB::table('audit_log')->count() - $auditCountBeforeConfirm,
            'audit_outbox_rows' => DB::table('audit_outbox')->count(),
            'raw_error_response' => json_decode($payload, true),
            'verdict' => 'PASS — rollback toàn phần, không có trạng thái "đã xếp hàng nhưng mất dấu vết audit"',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateSnapshot(string $eventId, string $boxId): array
    {
        $event = DB::table('putaway_events')->where('id', $eventId)->first();
        $container = DB::table('containers')->where('container_id', $boxId)->first();

        return [
            'putaway_event' => [
                'id' => $eventId,
                'status' => (string) $event->status,
                'version' => (int) $event->version,
                'confirmed_at' => $event->confirmed_at,
                'confirmed_by' => $event->confirmed_by,
            ],
            'container' => [
                'container_id' => $boxId,
                'status' => (string) $container->status,
                'parent_container_id' => $container->parent_container_id,
                'version' => (int) $container->version,
            ],
            'stock_movements' => DB::table('stock_movements')->count(),
        ];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$evidence !== []) {
            $directory = dirname(__DIR__, 2).'/build/evidence/wwhn-193';
            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new \RuntimeException("Không tạo được thư mục evidence: {$directory}");
            }
            $driver = (string) (self::$evidence['engine']['driver'] ?? 'unknown');
            $path = $directory.'/audit-fail-closed-proof-'.$driver.'.json';
            $written = file_put_contents(
                $path,
                json_encode(self::$evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            if ($written === false) {
                throw new \RuntimeException("Không ghi được evidence: {$path}");
            }
            self::$evidence = [];
        }

        parent::tearDownAfterClass();
    }
}
