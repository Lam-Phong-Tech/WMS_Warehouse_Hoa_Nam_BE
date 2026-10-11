<?php

namespace Tests\Unit;

use Modules\Warehouse\Support\P12DocumentPresentation as Presentation;
use PHPUnit\Framework\TestCase;

class P12DocumentPresentationTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    private const WAREHOUSE = '22222222-2222-4222-8222-222222222222';

    public function test_owner_status_and_readiness_never_prove_handoff(): void
    {
        foreach (['INBOUND', 'OUTBOUND', 'WARRANTY'] as $source) {
            $row = ['id' => self::ID, 'warranty_case_id' => self::ID, 'status' => 'SCANNING',
                'mini_app_status' => 'WAITING_APPROVAL', 'ready_for_post' => true,
                'created_at' => '2026-10-11T01:00:00Z', 'doc_date' => '2026-10-11',
                'submitted_at' => '2026-10-11T02:00:00Z'];
            $result = Presentation::record($row, $source, true);
            $this->assertSame('SCANNING', $result['status']['canonical']);
            $this->assertSame('WAITING_APPROVAL', $result['status']['mini_app']);
            $this->assertNull($result['status']['waiting_for_web']);
            $this->assertNull($result['timeline']['milestones']['checked']['occurred_at']);
            $this->assertNull($result['timeline']['milestones']['sent_to_web']['occurred_at']);
            $this->assertNull($result['identity']['warehouse_id']);
            $this->assertFalse(Presentation::metadata($source)['all_sources_aggregate']);
        }
    }

    public function test_timeline_uses_only_explicit_server_timestamps_not_current_status(): void
    {
        $row = ['id' => self::ID, 'status' => 'POSTED', 'doc_date' => '2026-10-11',
            'updated_at' => '2026-10-11T03:00:00Z', 'created_at' => null, 'posted_at' => null];
        $result = Presentation::record($row, 'INBOUND', true);
        foreach (['created', 'posted'] as $key) {
            $this->assertSame('NOT_RECORDED', $result['timeline']['milestones'][$key]['availability']);
            $this->assertNull($result['timeline']['milestones'][$key]['occurred_at']);
        }
        $row['posted_at'] = '2026-10-11T02:00:00+07:00';
        $result = Presentation::record($row, 'OUTBOUND', true);
        $this->assertSame($row['posted_at'], $result['timeline']['milestones']['posted']['occurred_at']);
        $this->assertSame('stock_documents.posted_at', $result['timeline']['milestones']['posted']['timestamp_source']);
        $this->assertNull($result['timeline']['milestones']['posted']['event_id']);
        $this->assertArrayNotHasKey('timeline', Presentation::record($row, 'INBOUND', false));
    }

    public function test_receipt_requires_success_and_matching_canonical_ids_and_has_no_pii(): void
    {
        foreach (['UNKNOWN', 'PROCESSING', 'FAILED', 'SUCCEEDED'] as $state) {
            $data = ['warranty_case_id' => self::ID, 'receipt' => ['state' => $state,
                'warranty_case_id' => self::ID, 'customer_phone' => 'DO_NOT_PROJECT', 'note' => 'DO_NOT_PROJECT']];
            $result = Presentation::receipt($data, ['warehouse_id' => self::WAREHOUSE]);
            $this->assertSame('P09', $result['recovery_owner']);
            $this->assertFalse($result['receipt_is_detail']);
            $this->assertSame([], $result['write_operations']);
            $this->assertStringNotContainsString('DO_NOT_PROJECT', json_encode($result));
            if ($state === 'SUCCEEDED') {
                $this->assertSame('/api/v1/mini-app/warranty-cases/'.self::ID, $result['read_target']['path']);
                $this->assertSame('GET', $result['read_target']['method']);
            } else {
                $this->assertNull($result['read_target']);
            }
        }
        $data['warranty_case_id'] = self::WAREHOUSE;
        $this->assertNull(Presentation::receipt($data, [])['read_target']);
    }

    public function test_identity_does_not_fall_back_to_display_codes_drafts_or_request_ids(): void
    {
        $row = ['id' => 'WC-2026-001', 'draft_id' => self::ID, 'request_id' => self::ID,
            'warranty_case_code' => self::ID, 'warehouse_id' => self::WAREHOUSE];
        foreach (['INBOUND', 'OUTBOUND', 'WARRANTY'] as $source) {
            $result = Presentation::record($row, $source, true);
            $this->assertNull($result['identity']['id']);
            $this->assertNull($result['read_target']);
        }
        $row['warranty_case_id'] = self::ID;
        $result = Presentation::record($row, 'WARRANTY', true);
        $this->assertSame('WARRANTY_CASE', $result['identity']['resource_type']);
        $this->assertSame('NOT_APPLICABLE', $result['timeline']['milestones']['posted']['availability']);
    }
}
