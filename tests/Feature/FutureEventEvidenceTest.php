<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\UserBusinessProfile;
use Modules\Notification\Events\NotificationPushed;
use Modules\Notification\Services\NotificationService;
use Modules\Notification\Support\EventSourceSnapshot;
use Modules\Warehouse\Support\StockDocumentEventEvidence as Evidence;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class FutureEventEvidenceTest extends TestCase
{
    use BuildsScanDrivenInbound, InteractsWithBrowserSession, PreparesOutboundFixtures, RefreshDatabase;

    private function owner(): array
    {
        $auth = $this->superAdminHeaders();
        $user = User::findOrFail($auth['__user_id']);
        $secret = bin2hex(random_bytes(24));
        $user->forceFill(['email' => 'event-'.Str::uuid().'@example.test', 'password_hash' => Hash::make($secret), 'must_change_password' => false])->save();
        $warehouse = $this->warehouseFixture();
        $login = $this->browserLogin($user->email, $secret)->assertOk();
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk()->json('data.access_token');

        return [$user, $token, $warehouse];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_dispatch_snapshot_does_not_change_after_directory_and_title_edits(): void
    {
        Event::fake([NotificationPushed::class]);
        [$actor, $token, $warehouse] = $this->owner();
        UserBusinessProfile::put($actor->user_id, 'Warehouse TEST title', $actor->user_id);
        $snapshot = EventSourceSnapshot::capture($actor->user_id, $warehouse->id);
        $oldName = $actor->display_name;
        $oldWarehouse = $warehouse->name;
        $actor->forceFill(['display_name' => 'Changed now'])->save();
        UserBusinessProfile::put($actor->user_id, 'Changed title', $actor->user_id);
        $warehouse->update(['name' => 'Changed warehouse']);
        $n = app(NotificationService::class)->notify('inbound.created', 'TEST', 'TEST', [$actor->user_id],
            subjectType: 'stock_document', subjectId: (string) Str::uuid(), payload: ['warehouse_id' => $warehouse->id],
            actorId: $actor->user_id, sourceSnapshot: $snapshot);
        $recipient = $n->recipients()->firstOrFail();
        $before = DB::table('notification_recipients')->where('id', $recipient->id)->first();
        foreach (['/api/v1/notifications', '/api/v1/notifications/'.$recipient->id] as $url) {
            $r = $this->getJson($url, $this->headers($token))->assertOk();
            $row = $url === '/api/v1/notifications' ? $r->json('data.0') : $r->json('data');
            $this->assertSame($oldName, $row['actor_name']);
            $this->assertSame('Warehouse TEST title', $row['source_snapshot']['actor_job_title_snapshot']);
            $this->assertSame($oldWarehouse, $row['source_snapshot']['warehouse_name_snapshot']);
            $this->assertSame('AT_DOMAIN_EVENT_DISPATCH', $row['source_snapshot']['capture_policy']);
            $this->assertNull($row['read_at']);
        }
        $this->assertEquals($before, DB::table('notification_recipients')->where('id', $recipient->id)->first());
    }

    public function test_missing_historical_source_remains_null_even_with_current_title(): void
    {
        Event::fake([NotificationPushed::class]);
        [$actor, $token, $warehouse] = $this->owner();
        UserBusinessProfile::put($actor->user_id, 'Current only', $actor->user_id);
        $n = app(NotificationService::class)->notify('inbound.created', 'TEST', 'TEST', [$actor->user_id], actorId: $actor->user_id, sourceSnapshot: []);
        $this->getJson('/api/v1/notifications/'.$n->recipients()->firstOrFail()->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.source_snapshot.availability', 'NOT_RECORDED')
            ->assertJsonPath('data.source_snapshot.actor_job_title_snapshot', null);
    }

    public function test_explicit_human_check_receipt_is_versioned_replayable_and_stock_neutral(): void
    {
        [$actor, $token, $warehouse] = $this->owner();
        $sku = $this->productSkuFixture('TC-EVENT-'.Str::random(8));
        $source = $this->outboundRequestFixture($sku->sku_id, 1, 'TC-EVENT-'.Str::random(8));
        $document = $this->outboundDocumentFixture($warehouse->id, $source);
        $id = $document['document_id'];
        $url = '/api/v1/outbound-documents/'.$id.'/checks';
        $key = (string) Str::uuid();
        $body = ['warehouse_id' => $warehouse->id, 'confirmed' => true];
        $header = $this->headers($token) + ['Idempotency-Key' => $key, 'If-Match' => '"1"'];
        $before = DB::table('stock_documents')->where('id', $id)->first();
        $receipt = $this->postJson($url, $body, $header)->assertOk()->assertJsonPath('data.outcome', 'SUCCEEDED')->json('data');
        $this->postJson($url, $body, $header)->assertOk()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data', $receipt);
        $this->postJson($url, $body, $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"2"'])
            ->assertConflict()->assertJsonPath('error.code', 'DOCUMENT_CHECK_VERSION_CONFLICT');
        $this->getJson($url.'/requests/'.$key.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()->assertJsonPath('data', $receipt);
        $this->getJson($url.'/requests/'.Str::uuid().'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN');
        $this->assertSame(1, DB::table(Evidence::TABLE)->where('document_id', $id)->count());
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertEquals($before, DB::table('stock_documents')->where('id', $id)->first());
        $this->getJson('/api/v1/mini-app/outbound-documents/'.$id.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.p12.event_evidence.milestones.checked.availability', 'RECORDED')
            ->assertJsonPath('data.p12.event_evidence.milestones.created.availability', 'NOT_RECORDED');
        $actor->roles()->detach();
        $actor->increment('version');
        $this->postJson($url, $body, $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"1"'])->assertForbidden();
    }

    public function test_real_p04_record_captures_created_and_sent_but_never_a_human_check(): void
    {
        [$actor, $token, $warehouse] = $this->owner();
        UserBusinessProfile::put($actor->user_id, 'TEST job title', $actor->user_id);
        $sku = $this->productSkuFixture('TC-EVENT-IN-'.Str::random(8));
        $draft = (string) Str::uuid();
        $scan = (string) Str::uuid();
        $this->putJson('/api/v1/mini-app/inbound/drafts/'.$draft, ['warehouse_id' => $warehouse->id,
            'scan_session_id' => $scan, 'inbound_type' => 'receipt', 'name' => 'TEST event capture',
            'items' => [['raw_code' => 'HN1|SKU='.$sku->sku_code.'|ITEM=EVENT-NEW', 'quantity' => 1, 'scan_source' => 'MANUAL']]],
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"0"'])->assertCreated();
        $result = $this->postJson('/api/v1/mini-app/inbound/drafts/'.$draft.'/record',
            ['warehouse_id' => $warehouse->id, 'scan_session_id' => $scan],
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"1"'])->assertCreated();
        $id = $result->json('data.document_id');
        $this->getJson('/api/v1/mini-app/inbound-documents/'.$id.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.p12.event_evidence.milestones.created.availability', 'RECORDED')
            ->assertJsonPath('data.p12.event_evidence.milestones.sent.availability', 'RECORDED')
            ->assertJsonPath('data.p12.event_evidence.milestones.sent.actor.job_title_snapshot', 'TEST job title')
            ->assertJsonPath('data.p12.event_evidence.milestones.checked.availability', 'NOT_RECORDED');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_test_stock_and_warranty_are_created_through_real_owner_receipt_and_issue_not_balance_inserts(): void
    {
        [$actor, $token, $warehouse] = $this->owner();
        config(['p14.shift_enabled' => true]);
        $shift = $this->postJson('/api/v1/mini-app/work-shifts/start', ['warehouse_id' => $warehouse->id],
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.shift_id');
        $headers = fn (?int $version = null) => $this->headers($token) + [
            'Idempotency-Key' => (string) Str::uuid(), 'X-Request-ID' => (string) Str::uuid(),
            'X-Work-Shift-ID' => $shift, ...($version === null ? [] : ['If-Match' => '"'.$version.'"'])];
        $product = $this->productSkuFixture('TC26-LOCAL-'.Str::random(8));
        $component = $this->componentSkuFixture('TC26-COMP-'.Str::random(8), serialTracked: true);
        $items = [['raw_code' => 'HN1|SKU='.$product->sku_code.'|ITEM=TC26-LOCAL-WAR', 'quantity' => 1, 'scan_source' => 'MANUAL'],
            ['raw_code' => 'HN1|SKU='.$component->sku_code.'|ITEM=TC26-LOCAL-COMP', 'quantity' => 1, 'scan_source' => 'MANUAL']];
        $draft = (string) Str::uuid();
        $scan = (string) Str::uuid();
        $saved = $this->putJson('/api/v1/mini-app/inbound/drafts/'.$draft, ['warehouse_id' => $warehouse->id,
            'scan_session_id' => $scan, 'inbound_type' => 'receipt', 'name' => 'TC26 local receipt', 'items' => $items],
            $headers(0))->assertCreated();
        $record = $this->postJson('/api/v1/mini-app/inbound/drafts/'.$draft.'/record',
            ['warehouse_id' => $warehouse->id, 'scan_session_id' => $scan], $headers($saved->json('data.document.version')))->assertCreated();
        $this->postJson('/api/v1/mini-app/inbound-documents/'.$record->json('data.document_id').'/post-receipt', [],
            $headers($record->json('data.version')))->assertOk();
        $issue = $this->postJson('/api/v1/mini-app/outbound/record', ['warehouse_id' => $warehouse->id,
            'name' => 'TC26 local issue', 'recipient_name' => 'TEST', 'expected_total_qty' => 1,
            'items' => [['raw_code' => $items[0]['raw_code'], 'scan_source' => 'MANUAL']]], $headers())->assertCreated();
        $this->postJson('/api/v1/mini-app/outbound-documents/'.$issue->json('data.document_id').'/post-issue', [],
            $headers($issue->json('data.version')))->assertOk();
        $identity = $this->postJson('/api/v1/mini-app/warranty/resolve-code',
            ['warehouse_id' => $warehouse->id, 'raw_code' => $items[0]['raw_code']], $headers())->assertOk();
        $case = $this->postJson('/api/v1/mini-app/warranty-cases', ['warehouse_id' => $warehouse->id,
            'item_id' => $identity->json('data.item_id'), 'customer_name' => 'TEST', 'customer_phone' => '0900000000',
            'description' => 'TC26 local TEST'], $headers())->assertCreated();
        $id = $case->json('data.warranty_case_id');
        $this->patchJson('/api/v1/mini-app/warranty-cases/'.$id, ['warehouse_id' => $warehouse->id, 'status' => 'CHECKING'],
            $headers($case->json('data.receipt.version')))->assertOk();
        $this->postJson('/api/v1/mini-app/warranty-component-issues/resolve-code', ['warehouse_id' => $warehouse->id,
            'warranty_case_id' => $id, 'raw_code' => 'TC26-LOCAL-COMP'], $headers() + ['X-Component-Command-Contract' => 'p19-component-command-v1'])
            ->assertOk()->assertJsonPath('data.eligible_for_issue', true)->assertJsonPath('data.case_allows_issue', true)
            ->assertJsonPath('data.stock_effect', 'NONE');
        $product->update(['sku_name' => 'Changed current catalog name']);
        $this->getJson('/api/v1/mini-app/warranty-cases/'.$id.'?warehouse_id='.$warehouse->id, $headers())->assertOk()
            ->assertJsonPath('data.product_source.sku_name', 'Product outbound test')
            ->assertJsonPath('data.product_source.availability', 'RECORDED');
        $this->assertDatabaseCount('component_issue_documents', 0);
        $this->assertSame(0, (int) DB::table('inventory_balances')->where('product_id', $product->sku_id)->sum('available_qty'));
        $this->assertSame(1, (int) DB::table('inventory_balances')->where('product_id', $component->sku_id)->sum('available_qty'));
    }
}
