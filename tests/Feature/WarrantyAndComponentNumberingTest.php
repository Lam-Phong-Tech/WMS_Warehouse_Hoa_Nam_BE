<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\ComponentIssueNumber;
use Modules\Warehouse\Services\InboundDocumentNumber;
use Modules\Warehouse\Services\OutboundDocumentNumber;
use Modules\Warehouse\Services\WarrantyCaseNumber;
use Tests\TestCase;

class WarrantyAndComponentNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_warranty_numbers_use_vietnam_daily_sequence_and_reset_at_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03T16:59:50Z')); // 23:59:50 Asia/Ho_Chi_Minh
        $service = app(WarrantyCaseNumber::class);

        $this->assertSame('BH-20261103235950', $service->next());
        $this->assertSame('BH-20261103235951', $service->next());

        $this->travelTo(Carbon::parse('2026-11-03T17:00:01Z')); // 00:00:01 next Vietnam day
        $this->assertSame('BH-20261104000001', $service->next());
    }

    public function test_component_issue_numbers_use_vietnam_daily_sequence_and_reset_at_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03T16:59:50Z'));
        $service = app(ComponentIssueNumber::class);

        $this->assertSame('CID-20261103-0001', $service->next());
        $this->assertSame('CID-20261103-0002', $service->next());

        $this->travelTo(Carbon::parse('2026-11-03T17:00:01Z'));
        $this->assertSame('CID-20261104-0001', $service->next());
    }

    public function test_warranty_numbering_fails_closed_when_counter_schema_is_missing(): void
    {
        Schema::drop('warranty_daily_numbers');

        try {
            app(WarrantyCaseNumber::class)->next();
            $this->fail('Warranty numbering must not silently fall back when its counter table is missing.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(503, $exception->getResponse()->getStatusCode());
        }
    }

    public function test_warranty_numbering_is_ready_when_warranty_case_is_a_view(): void
    {
        // PostgreSQL layout: `warranty_cases` is the base table while
        // `warranty_case` is a view (INSTEAD OF trigger). Laravel 13's
        // PostgresGrammar::compileTableExists() only matches relkind r/p, so
        // Schema::hasTable('warranty_case') is false there. Readiness must
        // accept the view or every create returns 503.
        Schema::drop('warranty_case');
        DB::statement('CREATE VIEW warranty_case AS SELECT 1 AS warranty_case_id');

        $this->assertTrue(app(WarrantyCaseNumber::class)->ready());
    }

    public function test_component_issue_numbering_fails_closed_when_counter_schema_is_missing(): void
    {
        Schema::drop('component_issue_daily_numbers');

        try {
            app(ComponentIssueNumber::class)->next();
            $this->fail('Component issue numbering must not silently fall back when its counter table is missing.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(503, $exception->getResponse()->getStatusCode());
        }
    }

    public function test_same_second_requests_at_vietnam_midnight_roll_over_cleanly_for_pn_px_and_bh(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03T16:59:59Z')); // 23:59:59 Asia/Ho_Chi_Minh

        $inbound = app(InboundDocumentNumber::class);
        $outbound = app(OutboundDocumentNumber::class);
        $warranty = app(WarrantyCaseNumber::class);

        $this->assertSame('PN-20261103235959', $inbound->next());
        $this->assertSame('PN-20261104000000', $inbound->next());

        $this->assertSame('PX-20261103235959', $outbound->next());
        $this->assertSame('PX-20261104000000', $outbound->next());

        $this->assertSame('BH-20261103235959', $warranty->next());
        $this->assertSame('BH-20261104000000', $warranty->next());

        $this->travelTo(Carbon::parse('2026-11-03T17:00:00Z')); // 00:00:00 next Vietnam day
        $this->assertSame('PN-20261104000001', $inbound->next());
        $this->assertSame('PX-20261104000001', $outbound->next());
        $this->assertSame('BH-20261104000001', $warranty->next());
        $this->travelBack();
    }

    public function test_legacy_codes_remain_intact_and_guarded_alongside_unified_timestamp_codes(): void
    {
        $user = User::create([
            'username' => 'legacy-num-'.Str::lower(Str::random(6)),
            'display_name' => 'Legacy Number Guard User',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']
        );
        $draftIn = (string) Str::uuid();
        $draftOut = (string) Str::uuid();
        DB::table('mini_app_drafts')->insert([
            ['document_id' => $draftIn, 'actor_id' => (string) $user->user_id, 'auth_session_id' => (string) Str::uuid(), 'warehouse_id' => (string) $warehouse->id, 'operation' => 'INBOUND', 'scan_session_id' => (string) Str::uuid(), 'status' => 'DRAFT', 'codes' => '[]', 'form' => '{}', 'version' => 1, 'last_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()],
            ['document_id' => $draftOut, 'actor_id' => (string) $user->user_id, 'auth_session_id' => (string) Str::uuid(), 'warehouse_id' => (string) $warehouse->id, 'operation' => 'OUTBOUND', 'scan_session_id' => (string) Str::uuid(), 'status' => 'DRAFT', 'codes' => '[]', 'form' => '{}', 'version' => 1, 'last_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('inbound_draft_numbers')->insert([
            'draft_id' => $draftIn,
            'doc_no' => 'PN-20261103-0001',
            'assigned_at' => now(),
        ]);
        DB::table('outbound_draft_numbers')->insert([
            'draft_id' => $draftOut,
            'doc_no' => 'PX-20261103-0001',
            'assigned_at' => now(),
        ]);

        try {
            app(InboundDocumentNumber::class)->guardExplicit('PN-20261103-0001');
            $this->fail('Legacy reserved PN number must still be guarded.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('DOCUMENT_NUMBER_RESERVED', $e->getResponse()->getData(true)['error']['code']);
        }

        try {
            app(OutboundDocumentNumber::class)->guardExplicit('PX-20261103-0001');
            $this->fail('Legacy reserved PX number must still be guarded.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('DOCUMENT_NUMBER_RESERVED', $e->getResponse()->getData(true)['error']['code']);
        }
    }
}
