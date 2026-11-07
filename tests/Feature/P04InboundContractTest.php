<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\StockDocumentService;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class P04InboundContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/inbound';

    private const PERMISSIONS = ['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.cancel'];

    public function test_internal_receipt_creation_does_not_reuse_an_app_reservation(): void
    {
        [$actor, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse);
        $number = $this->getJson($this->draftUrl($id, $body), $this->headers($token))->assertOk()->json('data.document.document_no');
        $receipt = app(StockDocumentService::class)->create('receipt', ['dst_warehouse_id' => (string) $warehouse->id, 'lines' => []], (string) $actor->user_id);
        $this->assertNotSame($number, $receipt->doc_no);
        $this->assertSame('PN-'.now('Asia/Ho_Chi_Minh')->format('Ymd').'-0002', $receipt->doc_no);
    }

    public function test_web_and_app_share_daily_receipt_numbers_and_preserve_replay(): void
    {
        [, $token, , $warehouse] = $this->actor();
        $prefix = 'PN-'.now('Asia/Ho_Chi_Minh')->format('Ymd').'-';
        $key = (string) Str::uuid();
        $payload = ['dst_warehouse_id' => (string) $warehouse->id];
        $first = $this->postJson('/api/v1/inbound-documents', $payload, $this->headers($token, null, $key))->assertCreated();
        $first->assertJsonPath('data.doc_no', $prefix.'0001')->assertJsonPath('data.source_reference', null);
        $this->postJson('/api/v1/inbound-documents', $payload, $this->headers($token, null, $key))->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.doc_no', $prefix.'0001');
        [$id, $body] = $this->save($token, $warehouse);
        $this->getJson($this->draftUrl($id, $body), $this->headers($token))->assertOk()
            ->assertJsonPath('data.document.document_no', $prefix.'0002');
        $this->postJson('/api/v1/inbound-documents', $payload + ['source_reference' => 'GH-035'], $this->headers($token, null, (string) Str::uuid()))
            ->assertCreated()->assertJsonPath('data.doc_no', $prefix.'0003')->assertJsonPath('data.source_reference', 'GH-035');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_app_external_reference_is_optional_and_independent_of_legacy_title(): void
    {
        [, $token, , $warehouse] = $this->actor();
        foreach ([[], ['name' => null], ['name' => ''], ['name' => 'PN-20261107-0003']] as $metadata) {
            $id = (string) Str::uuid();
            $body = $this->body($warehouse);
            $body['items'][0]['raw_code'] .= '-'.Str::uuid();
            unset($body['name']);
            $body = array_replace($body, $metadata);
            $this->putJson(self::BASE.'/drafts/'.$id, $body, $this->headers($token, 0, (string) Str::uuid()))->assertCreated();
            $key = (string) Str::uuid();
            $result = $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body) + ['client_request_id' => $key], $this->headers($token, 1, $key))->assertCreated();
            $this->assertDatabaseHas('stock_documents', ['id' => $result->json('data.document_id'), 'source_name' => null]);
        }
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_app_preserves_explicit_external_reference_through_save_record_and_replay(): void
    {
        [, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse, ['name' => '', 'source_reference' => '  GH-035  ']);
        $key = (string) Str::uuid();
        $payload = $this->identity($body) + ['client_request_id' => $key];
        $result = $this->postJson(self::BASE.'/drafts/'.$id.'/record', $payload, $this->headers($token, 1, $key))->assertCreated();
        $documentId = $result->json('data.document_id');
        $this->assertDatabaseHas('stock_documents', ['id' => $documentId, 'source_name' => 'GH-035']);
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $payload, $this->headers($token, 1, $key))->assertCreated()
            ->assertJsonPath('data.document_id', $documentId)->assertHeader('Idempotent-Replay', 'true');
        $this->assertDatabaseCount('stock_documents', 1);
    }

    public function test_all_p04_routes_require_authentication(): void
    {
        $id = (string) Str::uuid();
        foreach (['/context', '/types', '/suppliers', '/drafts/'.$id, '/drafts/'.$id.'/scan-session', '/documents/'.$id] as $path) {
            $this->getJson(self::BASE.$path)->assertUnauthorized();
        }
        foreach (['validate-code', 'review', 'record', 'cancel'] as $action) {
            $this->postJson(self::BASE.'/drafts/'.$id.'/'.$action, [])->assertUnauthorized();
        }
        $this->putJson(self::BASE.'/drafts/'.$id, [])->assertUnauthorized();
    }

    public function test_context_does_not_select_a_warehouse_or_create_a_shift(): void
    {
        [, $token, , $warehouse] = $this->actor(confirm: false);
        $this->getJson(self::BASE.'/context', $this->headers($token))->assertOk()
            ->assertJsonPath('data.warehouse_context.status', 'REQUIRED')
            ->assertJsonPath('data.active_warehouse_id', null)->assertJsonPath('data.permissions.record', false)
            ->assertJsonPath('data.scan_event_mode', 'LOCAL_UNTIL_RECORD');
        $this->putJson(self::BASE.'/drafts/'.Str::uuid(), $this->body($warehouse), $this->headers($token, 0, (string) Str::uuid()))
            ->assertConflict()->assertJsonPath('error.code', 'WAREHOUSE_CONTEXT_REQUIRED');
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
        $this->assertDatabaseCount('mini_app_drafts', 0);
    }

    public function test_type_catalogue_is_the_canonical_capability_and_supplier_directory_has_no_seeded_rows(): void
    {
        [, $token, , $warehouse] = $this->actor();
        $this->getJson(self::BASE.'/types?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'receipt')
            ->assertJsonPath('data.0.allowed', true)->assertJsonPath('meta.source', 'CANONICAL_RECORD_CAPABILITY');
        $this->getJson(self::BASE.'/suppliers', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('inbound_suppliers', 0);
    }

    public function test_supplier_search_is_global_with_status_literal_query_and_sql_pagination(): void
    {
        [, $token, , $warehouse] = $this->actor();
        $a = $this->supplierRow($warehouse, 'Alpha 100%');
        $this->supplierRow($warehouse, 'Alpha 200');
        $inactive = $this->supplierRow($warehouse, 'Alpha inactive', 'INACTIVE');
        $other = Warehouse::create(['code' => 'P04-OTHER', 'name' => 'Other isolated warehouse', 'status' => 'INACTIVE']);
        $this->supplierRow($other, 'Alpha foreign');
        $url = self::BASE.'/suppliers?status=ACTIVE';
        $this->getJson($url.'&q=Alpha&per_page=1&page=1', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.scope', 'GLOBAL')->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a);
        $this->getJson($url.'&q=100%25', $this->headers($token))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::BASE.'/suppliers?status=INACTIVE', $this->headers($token))->assertOk()->assertJsonPath('data.0.id', $inactive);
        $this->getJson($url.'&per_page=101', $this->headers($token))->assertStatus(422);
    }

    public function test_draft_and_scan_session_reserve_a_real_number_without_creating_stock(): void
    {
        [$actor, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse);
        $this->getJson($this->draftUrl($id, $body), $this->headers($token))->assertOk()
            ->assertJsonPath('data.document.actor_id', (string) $actor->user_id)
            ->assertJsonPath('data.document.operation', 'INBOUND')
            ->assertJsonPath('data.document.scan_session.status', 'OPEN')->assertJsonPath('data.document.scan_session.expiry_policy', 'AUTH_SESSION');
        $this->getJson($this->draftUrl($id, $body, '/scan-session'), $this->headers($token))->assertOk()
            ->assertJsonPath('data.scan_session_id', $body['scan_session_id'])->assertJsonPath('data.document_id', $id);
        $this->assertStockNeutral();
        $number = $this->getJson($this->draftUrl($id, $body), $this->headers($token))->json('data.document.document_no');
        $this->assertMatchesRegularExpression('/^PN-[0-9]{8}-[0-9]{4,}$/D', (string) $number);
    }

    public function test_context_advertises_save_numbering_and_the_three_approved_categories(): void
    {
        [, $token] = $this->actor();
        $this->getJson(self::BASE.'/context', $this->headers($token))->assertOk()
            ->assertJsonPath('data.document_number_assignment', 'WITH_DRAFT_SAVE')
            ->assertJsonPath('data.document_number_format', 'PN-YYYYMMDD-STT')
            ->assertJsonPath('data.ui_metadata_extension', 'p04-ui-options-v1')
            ->assertJsonPath('data.inbound_categories.0.id', 'PRODUCT')
            ->assertJsonPath('data.inbound_categories.1.id', 'COMPONENT')
            ->assertJsonPath('data.inbound_categories.2.id', 'OTHER');
        $this->assertStockNeutral();
    }

    public function test_initial_empty_save_replay_and_metadata_edits_keep_one_reserved_number(): void
    {
        [, $token, , $warehouse] = $this->actor();
        $id = (string) Str::uuid();
        $body = $this->body($warehouse, ['name' => '', 'items' => []]);
        $key = (string) Str::uuid();
        $first = $this->putJson(self::BASE.'/drafts/'.$id, $body, $this->headers($token, 0, $key))->assertCreated();
        $number = $first->json('data.document.document_no');
        $this->assertMatchesRegularExpression('/^PN-[0-9]{8}-[0-9]{4,}$/D', (string) $number);
        $this->putJson(self::BASE.'/drafts/'.$id, $body, $this->headers($token, 0, $key))->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.document.document_no', $number);
        $body['name'] = $number;
        $body['inbound_category'] = 'COMPONENT';
        $body['note'] = "Ghi chú giữ nguyên\nTiếng Việt đ";
        $this->putJson(self::BASE.'/drafts/'.$id, $body, $this->headers($token, 1, (string) Str::uuid()))->assertOk()
            ->assertJsonPath('data.document.document_no', $number)
            ->assertJsonPath('data.document.metadata.inbound_category', 'COMPONENT')
            ->assertJsonPath('data.document.version', 2);
        $this->assertDatabaseCount('inbound_draft_numbers', 1);
        $this->getJson($this->receiptUrl($id, $body, $key, 'SAVE'), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.document.document_no', $number);
        $this->assertStockNeutral();
    }

    public function test_each_category_survives_save_review_record_and_keeps_the_reserved_number(): void
    {
        [, $token, , $warehouse] = $this->actor();
        foreach (['PRODUCT', 'COMPONENT', 'OTHER'] as $category) {
            $item = $this->body($warehouse)['items'][0];
            $item['raw_code'] .= '-'.$category; // Physical ITEM identity is globally unique, even across SKUs.
            [$id, $body] = $this->save($token, $warehouse, ['inbound_category' => $category, 'note' => 'P04 '.$category, 'items' => [$item]]);
            $number = $this->getJson($this->draftUrl($id, $body), $this->headers($token))->assertOk()->json('data.document.document_no');
            $this->postJson(self::BASE.'/drafts/'.$id.'/review', $this->identity($body), $this->headers($token, 1))->assertOk()
                ->assertJsonPath('data.metadata.inbound_category', $category)->assertJsonPath('data.can_record', true);
            $key = (string) Str::uuid();
            $record = $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 1, $key))->assertCreated()
                ->assertJsonPath('data.doc_no', $number)->assertJsonPath('data.inbound_category', $category)
                ->assertJsonPath('data.status', 'SCANNING')->assertJsonPath('data.posted', false)->assertJsonPath('data.stock_effect', 'NONE');
            $this->assertDatabaseHas('mini_app_inbound_record_metadata', ['draft_id' => $id, 'inbound_category' => $category]);
            $this->assertDatabaseHas('stock_documents', ['id' => $record->json('data.document_id'), 'doc_no' => $number, 'note' => 'P04 '.$category]);
        }
        $this->assertDatabaseCount('stock_documents', 3);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
    }

    public function test_daily_numbers_follow_vietnam_midnight_and_do_not_reuse_a_cancelled_number(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02T16:59:50Z'));
        [, $token, , $warehouse] = $this->actor();
        [$id, $body, $key] = $this->save($token, $warehouse);
        $number = $this->getJson($this->draftUrl($id, $body), $this->headers($token))->json('data.document.document_no');
        $this->assertSame('PN-20261102-0001', $number);
        $this->postJson(self::BASE.'/drafts/'.$id.'/cancel', $this->identity($body) + ['confirmed_request_id' => $key, 'reason' => 'Cancel isolated draft'], $this->headers($token, 1, (string) Str::uuid()))->assertOk();
        [$next, $nextBody] = $this->save($token, $warehouse);
        $this->getJson($this->draftUrl($next, $nextBody), $this->headers($token))->assertOk()->assertJsonPath('data.document.document_no', 'PN-20261102-0002');
        $this->travelTo(Carbon::parse('2026-11-02T17:00:01Z'));
        [$tomorrow, $tomorrowBody] = $this->save($token, $warehouse);
        $this->getJson($this->draftUrl($tomorrow, $tomorrowBody), $this->headers($token))->assertOk()->assertJsonPath('data.document.document_no', 'PN-20261103-0001');
        $this->travelBack();
        $this->assertStockNeutral();
    }

    public function test_web_receipt_creation_cannot_take_a_number_reserved_by_an_app_draft(): void
    {
        [$actor, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse);
        $number = $this->getJson($this->draftUrl($id, $body), $this->headers($token))->json('data.document.document_no');
        $this->assertNotEmpty($number);
        try {
            app(StockDocumentService::class)->create('receipt', [
                'doc_no' => $number, 'dst_warehouse_id' => (string) $warehouse->id, 'lines' => [],
            ], (string) $actor->user_id);
            $this->fail('A reserved draft number cannot be taken by another receipt.');
        } catch (HttpResponseException $error) {
            $this->assertSame(409, $error->getResponse()->getStatusCode());
            $this->assertSame('DOCUMENT_NUMBER_RESERVED', $error->getResponse()->getData(true)['error']['code']);
        }
        $this->assertStockNeutral();
    }

    public function test_active_web_patch_cannot_rename_another_receipt_to_a_reserved_draft_number(): void
    {
        [$actor, $token, , $warehouse] = $this->actor();
        $permission = Permission::firstOrCreate(['permission_key' => 'inbound.update'], ['permission_name' => 'inbound.update', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $actor->roles()->first()->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        [$id, $body] = $this->save($token, $warehouse);
        $number = $this->getJson($this->draftUrl($id, $body), $this->headers($token))->json('data.document.document_no');
        $web = app(StockDocumentService::class)->create('receipt', [
            'doc_no' => 'WEB-NUMBER-KEEP', 'dst_warehouse_id' => (string) $warehouse->id, 'lines' => [],
        ], (string) $actor->user_id);
        $this->patchJson('/api/v1/inbound-documents/'.$web->id, ['doc_no' => $number], $this->headers($token, 1, (string) Str::uuid()))
            ->assertConflict()->assertJsonPath('error.code', 'DOCUMENT_NUMBER_RESERVED');
        $this->assertDatabaseHas('stock_documents', ['id' => $web->id, 'doc_no' => 'WEB-NUMBER-KEEP', 'version' => 1]);
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 1, (string) Str::uuid()))
            ->assertCreated()->assertJsonPath('data.doc_no', $number)->assertJsonPath('data.stock_effect', 'NONE');
        $this->assertDatabaseCount('stock_documents', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_shared_document_service_cannot_take_a_reserved_receipt_number_for_another_type(): void
    {
        [$actor, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse);
        $number = $this->getJson($this->draftUrl($id, $body), $this->headers($token))->json('data.document.document_no');
        try {
            app(StockDocumentService::class)->create('issue', [
                'doc_no' => $number, 'src_warehouse_id' => (string) $warehouse->id, 'lines' => [],
            ], (string) $actor->user_id);
            $this->fail('A shared globally unique document number must respect the P04 reservation.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('DOCUMENT_NUMBER_RESERVED', $e->getResponse()->getData(true)['error']['code']);
        }
        $this->assertDatabaseCount('stock_documents', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_allocator_skips_existing_web_numbers_and_keeps_more_than_four_sequence_digits(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02T03:00:00Z'));
        [$actor, $token, , $warehouse] = $this->actor();
        app(StockDocumentService::class)->create('receipt', [
            'doc_no' => 'PN-20261102-9999', 'dst_warehouse_id' => (string) $warehouse->id, 'lines' => [],
        ], (string) $actor->user_id);
        [$id, $body] = $this->save($token, $warehouse);
        $this->getJson($this->draftUrl($id, $body), $this->headers($token))->assertOk()
            ->assertJsonPath('data.document.document_no', 'PN-20261102-10000');
        $this->assertDatabaseCount('stock_documents', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->travelBack();
    }

    public function test_unknown_category_is_rejected_without_allocating_a_number(): void
    {
        [, $token, , $warehouse] = $this->actor();
        $this->putJson(self::BASE.'/drafts/'.Str::uuid(), $this->body($warehouse, ['inbound_category' => 'TRANSFER']), $this->headers($token, 0, (string) Str::uuid()))->assertStatus(422);
        $this->assertDatabaseCount('mini_app_drafts', 0);
        $this->assertStockNeutral();
    }

    public function test_legacy_cancelled_save_receipt_replay_does_not_allocate_a_replacement_number(): void
    {
        [, $token, , $warehouse] = $this->actor();
        [$id, $body, $key] = $this->save($token, $warehouse);
        $this->postJson(self::BASE.'/drafts/'.$id.'/cancel', $this->identity($body) + [
            'confirmed_request_id' => $key, 'reason' => 'Historical cancelled draft fixture',
        ], $this->headers($token, 1, (string) Str::uuid()))->assertOk();
        // Test DB only: represent a pre-extension cancelled checkpoint that
        // never had a number. A historical SAVE receipt still has to replay.
        DB::table('inbound_draft_numbers')->where('draft_id', $id)->delete();
        $this->putJson(self::BASE.'/drafts/'.$id, $body, $this->headers($token, 0, $key))->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.document.document_no', null);
        $this->assertDatabaseCount('inbound_draft_numbers', 0);
        $this->assertStockNeutral();
    }

    public function test_validate_event_is_read_only_and_catches_normalized_duplicates_and_quantity_errors(): void
    {
        [, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse);
        $input = $this->identity($body) + ['client_event_id' => (string) Str::uuid(), 'item' => $body['items'][0]];
        $this->postJson(self::BASE.'/drafts/'.$id.'/validate-code', $input, $this->headers($token, 1))->assertOk()
            ->assertJsonPath('data.result.outcome', 'DUPLICATE')->assertJsonPath('data.event_persisted', false)
            ->assertJsonPath('data.scan_evidence_created', false);
        $input['item']['raw_code'] = str_replace('ITEM=ONE', 'ITEM=TWO', $input['item']['raw_code']);
        $input['item']['quantity'] = 2;
        $this->postJson(self::BASE.'/drafts/'.$id.'/validate-code', $input, $this->headers($token, 1))->assertOk()
            ->assertJsonPath('data.result.reason_code', 'ITEM_QUANTITY_MUST_BE_ONE');
        $input['item']['quantity'] = 1;
        $this->postJson(self::BASE.'/drafts/'.$id.'/validate-code', $input, $this->headers($token, 1))->assertOk()
            ->assertJsonPath('data.result.outcome', 'ACCEPTED');
        $this->assertDatabaseCount('mini_app_draft_receipts', 1);
        $this->assertStockNeutral();
    }

    public function test_review_reads_the_saved_version_not_client_replacement_data(): void
    {
        [, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse);
        $this->postJson(self::BASE.'/drafts/'.$id.'/review', $this->identity($body), $this->headers($token, 1))->assertOk()
            ->assertJsonPath('data.can_record', true)->assertJsonPath('data.sku_summary.0.quantity', 1)
            ->assertJsonPath('data.revalidation_policy', 'AGAIN_AT_RECORD');
        $this->postJson(self::BASE.'/drafts/'.$id.'/review', $this->identity($body) + ['items' => []], $this->headers($token, 1))
            ->assertStatus(422)->assertJsonPath('error.code', 'UNEXPECTED_FIELD');
        $this->postJson(self::BASE.'/drafts/'.$id.'/review', $this->identity($body), $this->headers($token, 2))->assertStatus(412);
        $this->assertStockNeutral();
    }

    public function test_record_replay_reconcile_and_p12_p22_reads_share_one_real_document(): void
    {
        [, $token, , $warehouse] = $this->actor();
        $supplierWarehouse = Warehouse::create(['code' => 'P04-GLOBAL-SUPPLIER', 'name' => 'Historical supplier origin', 'status' => 'INACTIVE']);
        $supplier = $this->supplierRow($supplierWarehouse, 'Isolated P04 supplier');
        [$id, $body] = $this->save($token, $warehouse, ['supplier_id' => $supplier]);
        $key = (string) Str::uuid();
        $input = $this->identity($body) + ['client_request_id' => $key];
        $result = $this->postJson(self::BASE.'/drafts/'.$id.'/record', $input, $this->headers($token, 1, $key))->assertCreated()
            ->assertJsonPath('data.record_status', 'RECORDED')->assertJsonPath('data.posted', false)
            ->assertJsonPath('data.status', 'SCANNING')->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('meta.p03_draft.receipt.command', 'RECORD')->assertJsonPath('data.supplier.id', $supplier);
        $documentId = $result->json('data.document_id');
        $this->assertNotSame($id, $documentId);
        $this->assertNotEmpty($result->json('data.doc_no'));
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $input, $this->headers($token, 1, $key))->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.document_id', $documentId);
        $this->getJson($this->receiptUrl($id, $body, $key), $this->headers($token))->assertOk()
            ->assertJsonPath('data.state', 'RECORDED')->assertJsonPath('data.outcome', 'SUCCEEDED');
        $this->getJson(self::BASE.'/documents/'.$documentId.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.document.id', $documentId)->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.posted', false)->assertJsonPath('data.inbound_metadata.supplier_id', $supplier)
            ->assertJsonStructure(['data' => ['history']]);
        $this->assertDatabaseCount('stock_documents', 1);
        $this->assertDatabaseCount('mini_app_inbound_record_metadata', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
        // Only a controlled test changes the canonical status here; no P04 API Posts.
        DB::table('stock_documents')->where('id', $documentId)->update(['status' => 'POSTED']);
        $this->getJson($this->receiptUrl($id, $body, $key), $this->headers($token))->assertOk()->assertJsonPath('data.state', 'POSTED');
        $cancel = $this->identity($body) + ['confirmed_request_id' => $key, 'reason' => 'Cannot cancel recorded'];
        $this->postJson(self::BASE.'/drafts/'.$id.'/cancel', $cancel, $this->headers($token, 2, (string) Str::uuid()))
            ->assertConflict()->assertJsonPath('error.code', 'DRAFT_STATE_CONFLICT');
        fwrite(STDOUT, "\nP04_RECORD_EVIDENCE=".json_encode([
            'document_id' => $documentId, 'draft_id' => $id, 'doc_no' => $result->json('data.doc_no'),
            'status' => $result->json('data.status'), 'posted' => $result->json('data.posted'),
            'stock_effect' => $result->json('data.stock_effect'), 'receipt' => $result->json('meta.p03_draft.receipt'),
        ], JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_unknown_request_and_identity_conflicts_never_create_replacement_documents(): void
    {
        [, $token, , $warehouse] = $this->actor();
        [$id, $body, $saveKey] = $this->save($token, $warehouse);
        $this->getJson($this->receiptUrl($id, $body, (string) Str::uuid()), $this->headers($token))->assertOk()
            ->assertJsonPath('data.state', 'UNKNOWN')->assertJsonPath('data.retry', 'SAME_REQUEST_ONLY')
            ->assertJsonPath('data.new_document_allowed', false)->assertJsonPath('data.cancel_allowed', false);
        $this->getJson($this->receiptUrl($id, $body, $saveKey, 'SAVE'), $this->headers($token))->assertOk()->assertJsonPath('data.outcome', 'SUCCEEDED');
        $this->getJson($this->receiptUrl((string) Str::uuid(), $body, $saveKey, 'SAVE'), $this->headers($token))->assertConflict();
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body) + ['client_request_id' => (string) Str::uuid()], $this->headers($token, 1, (string) Str::uuid()))
            ->assertConflict()->assertJsonPath('error.code', 'REQUEST_IDENTITY_MISMATCH');
        $this->assertStockNeutral();
    }

    public function test_unknown_sku_requires_explicit_type_and_preview_does_not_create_it(): void
    {
        [, $token, , $warehouse] = $this->actor();
        $item = ['raw_code' => 'HN1|SKU=P04-NEW-TYPE|ITEM=P04-NEW-ONE', 'quantity' => 1];
        [$id, $body] = $this->save($token, $warehouse, ['items' => [$item]]);
        $this->postJson(self::BASE.'/drafts/'.$id.'/review', $this->identity($body), $this->headers($token, 1))->assertOk()
            ->assertJsonPath('data.can_record', false)->assertJsonPath('data.items.0.reason_code', 'NEW_SKU_TYPE_REQUIRED');
        $this->assertDatabaseMissing('sku', ['sku_code' => 'P04-NEW-TYPE']);
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 1, (string) Str::uuid()))
            ->assertStatus(422)->assertJsonPath('error.code', 'P04_REVALIDATION_FAILED');
        $body['items'][0]['new_sku_type'] = 'PRODUCT';
        $this->putJson(self::BASE.'/drafts/'.$id, $body, $this->headers($token, 1, (string) Str::uuid()))->assertOk();
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 2, (string) Str::uuid()))->assertCreated()
            ->assertJsonPath('data.posted', false)->assertJsonPath('data.product_info_complete', false);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_box_quantity_is_required_and_record_keeps_inventory_unchanged(): void
    {
        [, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse, ['items' => [['raw_code' => 'BOX-P04-COMP-0001']]]);
        $this->postJson(self::BASE.'/drafts/'.$id.'/review', $this->identity($body), $this->headers($token, 1))->assertOk()
            ->assertJsonPath('data.items.0.reason_code', 'BOX_QUANTITY_REQUIRED');
        $body['items'][0]['quantity'] = 50;
        $this->putJson(self::BASE.'/drafts/'.$id, $body, $this->headers($token, 1, (string) Str::uuid()))->assertOk();
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 2, (string) Str::uuid()))->assertCreated()
            ->assertJsonPath('data.scanned_total_qty', 50)->assertJsonPath('data.items.0.item_type', 'BOX');
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_supplier_withdrawal_is_revalidated_after_review_before_record(): void
    {
        [, $token, , $warehouse] = $this->actor();
        $supplier = $this->supplierRow($warehouse, 'Supplier to withdraw');
        [$id, $body] = $this->save($token, $warehouse, ['supplier_id' => $supplier]);
        $this->postJson(self::BASE.'/drafts/'.$id.'/review', $this->identity($body), $this->headers($token, 1))->assertOk()->assertJsonPath('data.can_record', true);
        DB::table('inbound_suppliers')->where('id', $supplier)->update(['status' => 'INACTIVE', 'version' => 2]);
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 1, (string) Str::uuid()))
            ->assertConflict()->assertJsonPath('error.code', 'SUPPLIER_INACTIVE');
        $this->assertDatabaseCount('mini_app_inbound_record_metadata', 0);
        $this->assertStockNeutral();
    }

    public function test_actor_session_and_warehouse_isolation_are_enforced(): void
    {
        [$actor, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse);
        [, $other] = $this->actor($warehouse);
        $this->getJson($this->draftUrl($id, $body), $this->headers($other))->assertNotFound();
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($other, 1, (string) Str::uuid()))->assertNotFound();
        [$newToken] = $this->login($actor, $warehouse);
        $this->getJson($this->draftUrl($id, $body), $this->headers($newToken))->assertNotFound();
        $foreign = $body;
        $foreign['warehouse_id'] = (string) Str::uuid();
        $this->putJson(self::BASE.'/drafts/'.Str::uuid(), $foreign, $this->headers($token, 0, (string) Str::uuid()))->assertForbidden();
        $this->assertStockNeutral();
    }

    public function test_refresh_retains_the_scan_session_and_saved_document(): void
    {
        [, $token, $cookie, $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse);
        $before = $this->getJson($this->draftUrl($id, $body), $this->headers($token))->assertOk()->json('data.document.auth_session_id');
        $this->resetAuth();
        $refresh = $this->browserRefresh($cookie)->assertOk();
        $this->getJson($this->draftUrl($id, $body), $this->headers($refresh->json('data.access_token')))->assertOk()
            ->assertJsonPath('data.document.auth_session_id', $before)->assertJsonPath('data.document.scan_session_id', $body['scan_session_id']);
    }

    public function test_permission_withdrawal_blocks_record_replay(): void
    {
        [$actor, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse);
        $key = (string) Str::uuid();
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 1, $key))->assertCreated();
        DB::table('role_permission')->where('role_id', $actor->roles()->first()->role_id)
            ->whereIn('permission_id', Permission::where('permission_key', 'inbound.scan')->pluck('permission_id'))->delete();
        $actor->forceFill(['version' => $actor->version + 1])->save();
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 1, $key))->assertForbidden();
        $this->assertDatabaseCount('stock_documents', 1);
    }

    public function test_soft_cancel_requires_confirmed_save_and_closes_the_session(): void
    {
        [, $token, , $warehouse] = $this->actor();
        [$id, $body, $saved] = $this->save($token, $warehouse);
        $cancel = $this->identity($body) + ['confirmed_request_id' => (string) Str::uuid(), 'reason' => 'Cancel isolated draft'];
        $this->postJson(self::BASE.'/drafts/'.$id.'/cancel', $cancel, $this->headers($token, 1, (string) Str::uuid()))->assertConflict();
        $cancel['confirmed_request_id'] = $saved;
        $key = (string) Str::uuid();
        $this->postJson(self::BASE.'/drafts/'.$id.'/cancel', $cancel, $this->headers($token, 1, $key))->assertOk()
            ->assertJsonPath('data.document.status', 'CANCELLED')->assertJsonPath('data.document.scan_session.status', 'CLOSED');
        $this->postJson(self::BASE.'/drafts/'.$id.'/cancel', $cancel, $this->headers($token, 1, $key))->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->postJson(self::BASE.'/drafts/'.$id.'/review', $this->identity($body), $this->headers($token, 2))->assertConflict();
        $this->assertDatabaseCount('mini_app_drafts', 1);
        $this->assertStockNeutral();
    }

    public function test_version_key_and_forged_fields_do_not_overwrite_or_record(): void
    {
        [, $token, , $warehouse] = $this->actor();
        [$id, $body, $key] = $this->save($token, $warehouse);
        $changed = $body;
        $changed['name'] = 'Changed request';
        $this->putJson(self::BASE.'/drafts/'.$id, $changed, $this->headers($token, 0, $key))->assertConflict();
        $this->putJson(self::BASE.'/drafts/'.$id, $changed, $this->headers($token, 2, (string) Str::uuid()))->assertStatus(412);
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token))->assertStatus(428);
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 1))->assertStatus(428);
        $this->putJson(self::BASE.'/drafts/'.$id, $body + ['actor_id' => (string) Str::uuid()], $this->headers($token, 1, (string) Str::uuid()))->assertStatus(422);
        $this->assertStockNeutral();
    }

    public function test_receipt_storage_failure_rolls_back_canonical_record_and_supplier_metadata(): void
    {
        [, $token, , $warehouse] = $this->actor();
        [$id, $body] = $this->save($token, $warehouse);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'mini_app_draft_receipts')) {
                throw new \RuntimeException('Isolated P04 receipt storage fault');
            }
        });
        $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 1, (string) Str::uuid()))->assertStatus(500);
        $this->assertDatabaseCount('mini_app_inbound_record_metadata', 0);
        $this->assertDatabaseCount('mini_app_draft_receipts', 1);
        $this->assertDatabaseHas('mini_app_drafts', ['document_id' => $id, 'status' => 'DRAFT', 'version' => 1]);
        $this->assertStockNeutral();
    }

    public function test_supplier_api_edit_preserves_recorded_receipt_snapshot(): void
    {
        [$actor, $token, , $warehouse] = $this->actor();
        $permission = Permission::firstOrCreate(['permission_key' => 'catalog.manage'], [
            'permission_name' => 'catalog.manage', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE',
        ]);
        DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(),
            'role_id' => $actor->roles()->first()->role_id, 'permission_id' => $permission->permission_id,
            'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
        $supplier = $this->supplierRow($warehouse, 'Original supplier');
        DB::table('inbound_suppliers')->where('id', $supplier)->update(['code' => 'NCC-001']);
        [$id, $body] = $this->save($token, $warehouse, ['supplier_id' => $supplier]);
        $key = (string) Str::uuid();
        $recorded = $this->postJson(self::BASE.'/drafts/'.$id.'/record', $this->identity($body), $this->headers($token, 1, $key))->assertCreated();
        $documentId = $recorded->json('data.document_id');
        $snapshot = (array) DB::table('mini_app_inbound_record_metadata')->where('document_id', $documentId)->first();
        $document = (array) DB::table('stock_documents')->where('id', $documentId)->first();

        $this->patchJson(self::BASE.'/suppliers/'.$supplier, ['name' => 'Renamed supplier', 'status' => 'INACTIVE'], $this->headers($token, 1))
            ->assertOk()->assertJsonPath('data.code', 'NCC-001')->assertJsonPath('data.version', 2);

        $this->assertSame('Original supplier', $snapshot['supplier_name']);
        $this->assertSame($snapshot, (array) DB::table('mini_app_inbound_record_metadata')->where('document_id', $documentId)->first());
        $this->assertSame($document, (array) DB::table('stock_documents')->where('id', $documentId)->first());
    }

    private function actor(?Warehouse $warehouse = null, bool $confirm = true): array
    {
        $warehouse ??= $this->ensureActiveWarehouse('KHO-TONG-HN', 'Isolated P04 warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p04_'.$suffix, 'display_name' => 'P04 CI actor', 'email' => 'p04_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P04_SCOPED_'.$suffix, 'role_name' => 'P04 test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => (string) $warehouse->id]);
        foreach (self::PERMISSIONS as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$token, $cookie] = $this->login($user, $confirm ? $warehouse : null);

        return [$user, $token, $cookie, $warehouse];
    }

    private function login(User $user, ?Warehouse $warehouse): array
    {
        $this->resetAuth();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $cookie = $this->refreshTokenFromCookie($login);
        $token = $login->json('data.access_token');
        if ($warehouse !== null) {
            $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id], $this->headers($token))->assertOk()->json('data.access_token');
        }

        return [$token, $cookie];
    }

    private function body(Warehouse $warehouse, array $overrides = []): array
    {
        $sku = $this->productSkuFixture('P04-PRODUCT-'.substr((string) Str::uuid(), 0, 8));

        return array_replace([
            'warehouse_id' => (string) $warehouse->id, 'scan_session_id' => (string) Str::uuid(),
            'inbound_type' => 'receipt', 'name' => 'Isolated P04 receipt',
            'items' => [['raw_code' => 'HN1|SKU='.$sku->sku_code.'|ITEM=ONE', 'quantity' => 1, 'scan_source' => 'MANUAL']],
        ], $overrides);
    }

    private function save(string $token, Warehouse $warehouse, array $overrides = []): array
    {
        $id = (string) Str::uuid();
        $body = $this->body($warehouse, $overrides);
        $key = (string) Str::uuid();
        $this->putJson(self::BASE.'/drafts/'.$id, $body, $this->headers($token, 0, $key))->assertCreated()->assertJsonPath('data.document.version', 1);

        return [$id, $body, $key];
    }

    private function identity(array $body): array
    {
        return array_intersect_key($body, array_flip(['warehouse_id', 'scan_session_id']));
    }

    private function draftUrl(string $id, array $body, string $suffix = ''): string
    {
        return self::BASE.'/drafts/'.$id.$suffix.'?'.http_build_query($this->identity($body));
    }

    private function receiptUrl(string $id, array $body, string $key, string $command = 'RECORD'): string
    {
        return self::BASE.'/drafts/'.$id.'/requests/'.$key.'?'.http_build_query($this->identity($body) + ['command' => $command]);
    }

    private function supplierRow(Warehouse $warehouse, string $name, string $status = 'ACTIVE'): string
    {
        $id = (string) Str::uuid();
        DB::table('inbound_suppliers')->insert(['id' => $id, 'warehouse_id' => (string) $warehouse->id, 'name' => $name, 'status' => $status, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function headers(string $token, ?int $version = null, ?string $key = null): array
    {
        $this->resetAuth();
        $headers = ['Authorization' => 'Bearer '.$token];
        if ($version !== null) {
            $headers['If-Match'] = '"'.$version.'"';
        }
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $headers;
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }

    private function assertStockNeutral(): void
    {
        $this->assertDatabaseCount('stock_documents', 0);
        $this->assertDatabaseCount('inbound_scan_entries', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
    }
}
