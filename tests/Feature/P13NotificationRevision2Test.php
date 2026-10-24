<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationRecipient;
use Modules\Notification\Support\P13NotificationPresentation;
use Modules\Warehouse\Support\P12HandoffTimeline;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Owner writes below are isolated regression fixtures, never DEV/live smoke. */
class P13NotificationRevision2Test extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/notifications';

    private const PERMISSIONS = ['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.cancel',
        'outbound.view', 'outbound.request.manual_create', 'outbound.scan', 'outbound.cancel', 'documents.view'];

    protected function setUp(): void
    {
        $app = $this->createApplication();
        $name = $app['config']->get('database.default');
        $config = $app['config']->get('database.connections.'.$name);
        $safe = $app['config']->get('app.env') === 'testing' && empty($config['url'])
            && (($config['driver'] === 'sqlite' && $config['database'] === ':memory:')
                || ($config['driver'] === 'pgsql' && $config['host'] === '127.0.0.1'
                    && in_array($config['database'], ['p12_contract_test', 'p13_contract_test'], true)));
        $app->flush();
        if (! $safe) {
            throw new \LogicException('P13 r2 tests require isolated memory or localhost p12/p13_contract_test.');
        }
        parent::setUp();
    }

    public function test_eight_existing_routes_require_authentication(): void
    {
        $id = (string) Str::uuid();
        foreach ([self::BASE, self::BASE.'/unread-count', self::BASE.'/'.$id,
            '/api/v1/mini-app/inbound-documents', '/api/v1/mini-app/inbound-documents/'.$id,
            '/api/v1/mini-app/outbound-documents', '/api/v1/mini-app/outbound-documents/'.$id] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->patch(self::BASE.'/'.$id.'/read', [], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_read_rejects_raw_bytes_and_timeout_recovery_is_get_only(): void
    {
        [$user, $token] = $this->actor();
        $recipient = $this->notice($user);
        $url = self::BASE.'/'.$recipient->id.'/read';
        // Preserve the shared parser's 400; valid JSON and whitespace reach the owner's 422 guard.
        $cases = [
            '{}' => [422, 'NOTIFICATION_READ_BODY_NOT_ALLOWED'],
            '[]' => [422, 'NOTIFICATION_READ_BODY_NOT_ALLOWED'],
            'null' => [422, 'NOTIFICATION_READ_BODY_NOT_ALLOWED'],
            ' ' => [422, 'NOTIFICATION_READ_BODY_NOT_ALLOWED'],
            'broken-json' => [400, 'MALFORMED_JSON'],
        ];
        foreach ($cases as $raw => [$status, $code]) {
            $this->headers($token);
            $this->call('PATCH', $url, [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ], $raw)->assertStatus($status)->assertJsonPath('error.code', $code);
            $this->assertNull($recipient->fresh()->read_at);
        }
        $this->patch($url.'?read_at=now', [], $this->headers($token))->assertStatus(422);
        $first = $this->patch($url, [], $this->headers($token))->assertOk()->assertJsonPath('meta.changed', true);
        $time = $first->json('data.read_at');
        // Simulate the client losing the PATCH response; no second mutation is needed to reconcile.
        $this->getJson(self::BASE.'/'.$recipient->id, $this->headers($token))->assertOk()->assertJsonPath('data.read_at', $time);
        $this->getJson(self::BASE.'/unread-count', $this->headers($token))->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->patch($url, [], $this->headers($token))->assertOk()->assertJsonPath('meta.changed', false)
            ->assertJsonPath('data.read_at', $time)->assertJsonPath('meta.unread_count', 0);
    }

    public function test_event_and_subject_mapping_is_exact_and_not_a_title_or_permission_inference(): void
    {
        [$user, $token, $warehouse] = $this->actor([]);
        foreach (P13NotificationPresentation::EVENTS as $event => [$subjectType, $businessType, $path]) {
            $id = (string) Str::uuid();
            $payload = [$subjectType === 'stock_document' ? 'document_id' : 'case_id' => $id, 'warehouse_id' => $warehouse->id];
            $recipient = $this->notice($user, ['event_key' => $event, 'subject_type' => $subjectType,
                'subject_id' => $id, 'title' => 'Misleading text is not a route', 'payload' => $payload]);
            $this->getJson(self::BASE.'/'.$recipient->id, $this->headers($token))->assertOk()
                ->assertJsonPath('data.id', $recipient->id)->assertJsonPath('data.subject.business_type', $businessType)
                ->assertJsonPath('data.subject.read_target.path', '/api/v1/mini-app/'.$path.'/'.$id)
                ->assertJsonPath('data.subject.web_url', null)->assertJsonPath('data.read_at', null)
                ->assertJsonPath('data.subject.requires_subject_revalidation', true);
        }
        $this->getJson('/api/v1/mini-app/inbound-documents/'.Str::uuid(), $this->headers($token))->assertForbidden();
    }

    public function test_missing_warehouse_unknown_events_and_mismatched_ids_never_invent_navigation(): void
    {
        [$user, $token] = $this->actor();
        $id = (string) Str::uuid();
        $notice = $this->notice($user, ['event_key' => 'warranty.case_created', 'subject_type' => 'warranty_case',
            'subject_id' => $id, 'payload' => ['case_id' => $id]]);
        $this->getJson(self::BASE.'/'.$notice->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.subject.business_id', $id)->assertJsonPath('data.subject.warehouse_id', null)
            ->assertJsonPath('data.subject.navigation_status', 'WAREHOUSE_SOURCE_REQUIRED')->assertJsonPath('data.subject.read_target', null);
        $bad = $this->notice($user, ['event_key' => 'inbound.created', 'subject_type' => 'stock_document',
            'subject_id' => $id, 'payload' => ['document_id' => (string) Str::uuid(), 'type' => 'issue']]);
        $this->getJson(self::BASE.'/'.$bad->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.subject.mapping_status', 'SUBJECT_IDENTITY_MISMATCH')->assertJsonPath('data.subject.read_target', null);
        $unknown = $this->notice($user, ['title' => 'inbound.created PN-001', 'subject_type' => 'stock_document', 'subject_id' => $id]);
        $this->getJson(self::BASE.'/'.$unknown->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.subject.mapping_status', 'UNMAPPED_EVENT')->assertJsonPath('data.subject.business_type', null);
    }

    public function test_new_login_cannot_reuse_cursor_but_notifications_remain_account_owned(): void
    {
        [$user, $token] = $this->actor();
        $notice = $this->notice($user);
        $this->notice($user);
        $cursor = $this->getJson(self::BASE.'?cursor=start&per_page=1', $this->headers($token))->assertOk()->json('meta.next_cursor');
        $this->headers($token);
        $newToken = $this->browserLogin($user->email, 'secret123')->assertOk()->json('data.access_token');
        $this->getJson(self::BASE.'?'.http_build_query(['cursor' => $cursor, 'per_page' => 1]), $this->headers($newToken))
            ->assertConflict()->assertJsonPath('error.code', 'NOTIFICATION_CURSOR_SCOPE_MISMATCH');
        $this->getJson(self::BASE.'/'.$notice->id, $this->headers($newToken))->assertOk()->assertJsonPath('data.id', $notice->id);
    }

    public function test_account_count_filtered_total_and_loaded_rows_have_distinct_units(): void
    {
        [$user, $token] = $this->actor();
        $this->notice($user);
        $this->notice($user);
        $this->notice($user, ['severity' => 'warning']);
        $this->getJson(self::BASE.'?cursor=start&severity=info&per_page=1', $this->headers($token))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.count_unit', 'NOTIFICATION_RECIPIENT');
        $this->getJson(self::BASE.'/unread-count', $this->headers($token))->assertOk()->assertJsonPath('data.unread_count', 3);
    }

    public function test_real_p04_record_receipt_is_projected_without_relabelling_current_posted_state(): void
    {
        [$user, $token, $warehouse] = $this->actor();
        $record = $this->recordInbound($token, $warehouse);
        $id = $record->json('data.document_id');
        $url = '/api/v1/mini-app/inbound-documents/'.$id;
        $counts = $this->counts();
        $read = $this->getJson($url, $this->headers($token))->assertOk()
            ->assertJsonPath('data.p13.evidence_status', 'CONFIRMED_RECORD_RECEIPT')
            ->assertJsonPath('data.p13.record_handoff.business_document_id', $id)
            ->assertJsonPath('data.p13.record_handoff.state_at_record', 'SENT_WAITING_WEB')
            ->assertJsonPath('data.p13.current_waiting_for_web', true)
            ->assertJsonPath('data.p13.current_handoff.state', 'WAITING_WEB_POST')
            ->assertJsonPath('data.p12.status.waiting_for_web', null);
        $this->assertSame($record->json('meta.p03_draft.receipt.committed_at'), $read->json('data.p13.record_handoff.recorded_at'));
        $timeline = $this->getJson($url, $this->headers($token) + [P12HandoffTimeline::HEADER => P12HandoffTimeline::CONTRACT])
            ->assertOk()->assertJsonPath('meta.p12.timeline_contract', P12HandoffTimeline::CONTRACT)
            ->assertJsonPath('data.p12.timeline.coverage', 'PARTIAL')
            ->assertJsonPath('data.p12.timeline.milestones.checked.availability', 'SOURCE_MAPPING_PENDING')
            ->assertJsonPath('data.p12.timeline.milestones.sent_to_web.availability', 'RECORDED')
            ->assertJsonPath('data.p12.timeline.milestones.sent_to_web.occurred_at', $read->json('data.p13.current_handoff.submitted_at'))
            ->assertJsonPath('data.p12.timeline.milestones.sent_to_web.timestamp_source', 'mini_app_web_post_handoffs.submitted_at')
            ->assertJsonPath('data.p12.timeline.milestones.sent_to_web.actor', null)
            ->assertJsonPath('data.p12.timeline.milestones.sent_to_web.event_id', null);
        $this->assertStringContainsString(P12HandoffTimeline::HEADER, $timeline->headers->get('Vary'));
        $this->assertSame('SOURCE_MAPPING_PENDING', $read->json('data.p12.timeline.milestones.sent_to_web.availability'));
        $this->getJson('/api/v1/mini-app/inbound-documents?per_page=1', $this->headers($token))->assertOk()
            ->assertJsonPath('data.0.p13.record_handoff.business_document_id', $id)->assertJsonPath('meta.total', 1);
        $this->getJson($url.'/scan-entries?per_page=1', $this->headers($token))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.summary.entry_count', 2)->assertJsonPath('meta.summary.quantity_total', 2);
        $this->assertSame($counts, $this->counts());
        config(['p12_mixed_documents.enabled' => true]);
        $queue = '/api/v1/mini-app/documents?warehouse_id='.$warehouse->id.'&web_queue=WAITING_WEB_POST';
        $this->get($queue, $this->headers($token) + ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        // Test-only state change proves a receipt does not become a current waiting label.
        DB::table('stock_documents')->where('id', $id)->update(['status' => 'POSTED', 'posted_at' => now()]);
        $this->getJson($url, $this->headers($token))->assertOk()
            ->assertJsonPath('data.p13.record_handoff.semantics', 'HISTORICAL_RECORD_RECEIPT_NOT_CURRENT_DOCUMENT_STATE')
            ->assertJsonPath('data.p13.current_waiting_for_web', false)
            ->assertJsonPath('data.p13.current_handoff.state', 'POSTED');
        $this->getJson($url, $this->headers($token) + [P12HandoffTimeline::HEADER => P12HandoffTimeline::CONTRACT])
            ->assertOk()->assertJsonPath('data.p13.current_waiting_for_web', false)
            ->assertJsonPath('data.p12.timeline.milestones.sent_to_web.occurred_at', $timeline->json('data.p12.timeline.milestones.sent_to_web.occurred_at'));
        $this->get($queue, $this->headers($token) + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_scanning_without_valid_receipt_is_not_sent_and_receipt_is_private_to_session(): void
    {
        [$user, $token, $warehouse] = $this->actor();
        $record = $this->recordInbound($token, $warehouse);
        $id = $record->json('data.document_id');
        $url = '/api/v1/mini-app/inbound-documents/'.$id;
        [, $otherToken] = $this->actor();
        $this->getJson($url, $this->headers($otherToken))->assertOk()->assertJsonPath('data.p13.record_handoff', null)
            ->assertJsonPath('data.p13.current_waiting_for_web', true); // Warehouse authority, not another actor's private receipt.
        $this->getJson($url, $this->headers($otherToken) + [P12HandoffTimeline::HEADER => P12HandoffTimeline::CONTRACT])
            ->assertOk()->assertJsonPath('data.p13.record_handoff', null)
            ->assertJsonPath('data.p12.timeline.milestones.sent_to_web.availability', 'RECORDED')
            ->assertJsonPath('data.p12.timeline.milestones.sent_to_web.actor', null);
        $this->headers($token);
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $newToken = $this->selectWarehouse($login->json('data.access_token'), $warehouse);
        $this->getJson($url, $this->headers($newToken))->assertOk()->assertJsonPath('data.p13.record_handoff', null);
        // Only disposable fixture data is corrupted; current SCANNING/readiness must not repair it.
        DB::table('mini_app_draft_receipts')->where('command', 'RECORD')->update(['response_body' => '{}']);
        $counts = $this->counts();
        $this->getJson($url, $this->headers($token))->assertOk()->assertJsonPath('data.p13.evidence_status', 'NOT_ESTABLISHED')
            ->assertJsonPath('data.p13.record_handoff', null)->assertJsonPath('data.status', 'SCANNING');
        $this->assertSame($counts, $this->counts());
        DB::table('mini_app_web_post_handoffs')->where('stock_document_id', $id)->delete();
        $this->getJson($url, $this->headers($otherToken) + [P12HandoffTimeline::HEADER => P12HandoffTimeline::CONTRACT])
            ->assertOk()->assertJsonPath('data.p12.timeline.milestones.sent_to_web.availability', 'NOT_RECORDED')
            ->assertJsonPath('data.p12.timeline.milestones.sent_to_web.occurred_at', null);
    }

    public function test_real_p05_source_linked_record_and_full_parent_summary_remain_separate_from_page(): void
    {
        [, $token, $warehouse] = $this->actor();
        $sku = $this->productSkuFixture('P13-OUT-'.Str::upper(Str::random(8)));
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);
        $items = [];
        for ($i = 0; $i < 2; $i++) {
            $item = $this->seedItemInStock($sku->sku_id);
            $code = 'P13-ITEM-'.Str::upper(Str::random(10));
            $this->seedScanCode($item, $code);
            $items[] = ['raw_code' => 'HN1|SKU='.$sku->sku_code.'|ITEM='.$code, 'scan_source' => 'CAMERA'];
        }
        $request = $this->outboundRequestFixture($sku->sku_id, 2, 'P13-REQ-'.Str::upper(Str::random(10)));
        $document = $this->outboundDocumentFixture($warehouse->id, $request);
        $id = $document['document_id'];
        $draft = (string) Str::uuid();
        $scan = (string) Str::uuid();
        $this->putJson('/api/v1/mini-app/drafts/'.$draft, ['warehouse_id' => $warehouse->id, 'operation' => 'OUTBOUND',
            'scan_session_id' => $scan, 'codes' => $items, 'form' => ['p05_source' => ['document_id' => $id, 'version' => 1]]],
            $this->headers($token) + ['If-Match' => '"0"', 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $key = (string) Str::uuid();
        $this->postJson('/api/v1/mini-app/outbound/record', ['warehouse_id' => $warehouse->id,
            'source_document_id' => $id, 'source_version' => 1, 'expected_total_qty' => 2, 'items' => $items],
            $this->headers($token) + ['Idempotency-Key' => $key, 'X-Draft-ID' => $draft,
                'X-Draft-Scan-Session-ID' => $scan, 'X-Draft-Version' => '1'])->assertOk()->assertJsonPath('data.posted', false);
        $counts = $this->counts();
        $url = '/api/v1/mini-app/outbound-documents/'.$id;
        $this->getJson($url, $this->headers($token))->assertOk()->assertJsonPath('data.p13.record_handoff.request_id', $key)
            ->assertJsonPath('data.p13.record_handoff.business_type', 'OUTBOUND')->assertJsonPath('data.p13.record_handoff.draft_id', $draft)
            ->assertJsonPath('data.p13.current_handoff.state', 'WAITING_WEB_POST');
        $this->getJson('/api/v1/mini-app/outbound-documents?per_page=1', $this->headers($token))->assertOk()
            ->assertJsonPath('data.0.p13.record_handoff.business_document_id', $id)->assertJsonPath('meta.p13.all_sources_aggregate', false);
        $this->getJson($url.'/scan-entries?per_page=1', $this->headers($token))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.summary.entry_count', 2)->assertJsonPath('meta.summary.quantity_total', 2);
        $this->assertSame($counts, $this->counts());
    }

    private function recordInbound(string $token, object $warehouse): TestResponse
    {
        $sku = $this->productSkuFixture('P13-IN-'.Str::upper(Str::random(8)));
        $draft = (string) Str::uuid();
        $scan = (string) Str::uuid();
        $items = [];
        foreach (['ONE', 'TWO'] as $suffix) {
            $items[] = ['raw_code' => 'HN1|SKU='.$sku->sku_code.'|ITEM='.$suffix, 'quantity' => 1, 'scan_source' => 'MANUAL'];
        }
        $this->putJson('/api/v1/mini-app/inbound/drafts/'.$draft, [
            'warehouse_id' => $warehouse->id, 'scan_session_id' => $scan, 'inbound_type' => 'receipt',
            'name' => 'Isolated P13 inbound', 'items' => $items,
        ], $this->headers($token) + ['If-Match' => '"0"', 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return $this->postJson('/api/v1/mini-app/inbound/drafts/'.$draft.'/record', [
            'warehouse_id' => $warehouse->id, 'scan_session_id' => $scan,
        ], $this->headers($token) + ['If-Match' => '"1"', 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }

    private function actor(array $permissions = self::PERMISSIONS): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P13 isolated warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p13r2_'.$suffix, 'display_name' => 'P13 isolated actor',
            'email' => 'p13r2_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P13R2_'.$suffix, 'role_name' => 'Scoped test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->headers('');
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $token = $this->selectWarehouse($login->json('data.access_token'), $warehouse);

        return [$user, $token, $warehouse];
    }

    private function selectWarehouse(string $token, object $warehouse): string
    {
        return $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($token))->assertOk()->json('data.access_token');
    }

    private function notice(User $user, array $overrides = []): NotificationRecipient
    {
        $notification = Notification::create(array_replace(['event_key' => 'p13.unknown-event',
            'title' => 'Isolated P13 notification', 'body' => 'Not a live message', 'severity' => 'info',
            'source_module' => 'warehouse', 'payload' => [], 'created_at' => '2026-10-01 09:00:00'], $overrides));

        return NotificationRecipient::create(['notification_id' => $notification->id, 'user_id' => $user->user_id,
            'created_at' => '2026-10-01 09:00:00']);
    }

    private function counts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), [
            'stock_documents', 'stock_movements', 'mini_app_drafts', 'mini_app_draft_receipts', 'notification_recipients',
        ]);
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }
}
