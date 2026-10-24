<?php

namespace Tests\Feature;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Warehouse\Support\P12DocumentPresentation;
use Modules\Warehouse\Support\P12HandoffTimeline;
use Tests\TestCase;

/** Pure owner-response fixtures. No database, real auth session or notification writes. */
class P12HandoffTimelineTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    private const WAREHOUSE = '22222222-2222-4222-8222-222222222222';

    public function test_without_opt_in_legacy_timeline_and_nulls_are_unchanged(): void
    {
        $body = $this->body();
        $this->assertSame($body, P12HandoffTimeline::decorate($this->request(false), $body, 'INBOUND', 'DETAIL'));
    }

    public function test_recorded_time_is_exact_and_does_not_invent_check_actor_description_or_event(): void
    {
        $body = $this->body();
        $result = P12HandoffTimeline::decorate($this->request(), $body, 'INBOUND', 'DETAIL');
        $sent = $result['data']['p12']['timeline']['milestones']['sent_to_web'];
        $this->assertSame('2026-10-24T08:12:45.123456Z', $sent['occurred_at']);
        $this->assertSame('mini_app_web_post_handoffs.submitted_at', $sent['timestamp_source']);
        $this->assertSame('RECORDED', $sent['availability']);
        foreach (['actor', 'description', 'event_id'] as $key) {
            $this->assertNull($sent[$key]);
        }
        $this->assertSame(self::ID, $sent['source_record']['document_id']);
        $this->assertSame('SOURCE_MAPPING_PENDING', $result['data']['p12']['timeline']['milestones']['checked']['availability']);
        $this->assertSame('PARTIAL', $result['data']['p12']['timeline']['coverage']);
        $this->assertSame('SOURCE_MAPPING_PENDING', $body['data']['p12']['timeline']['milestones']['sent_to_web']['availability']);
    }

    public function test_no_persisted_handoff_is_not_recorded_even_with_a_receipt_or_scanning_status(): void
    {
        $body = $this->body();
        $body['data']['p13']['current_handoff_availability'] = 'NOT_ESTABLISHED';
        $body['data']['p13']['current_handoff'] = null;
        $body['data']['p13']['record_handoff'] = ['recorded_at' => '2026-10-24T08:12:45Z'];
        $result = P12HandoffTimeline::decorate($this->request(), $body, 'INBOUND', 'DETAIL');
        $this->assertSame('NOT_RECORDED', $result['data']['p12']['timeline']['milestones']['sent_to_web']['availability']);
        $this->assertNull($result['data']['p12']['timeline']['milestones']['sent_to_web']['occurred_at']);
    }

    public function test_unavailable_or_invalid_owner_evidence_fails_closed(): void
    {
        foreach (['SOURCE_UNAVAILABLE', 'BAD_WAREHOUSE', 'BAD_TIME', 'BAD_VERSION'] as $case) {
            $body = $this->body();
            if ($case === 'SOURCE_UNAVAILABLE') {
                $body['data']['p13']['current_handoff_availability'] = $case;
            } else {
                $field = ['BAD_WAREHOUSE' => 'warehouse_id', 'BAD_TIME' => 'submitted_at', 'BAD_VERSION' => 'document_version_at_record'][$case];
                $body['data']['p13']['current_handoff'][$field] = 'unverified';
            }
            try {
                P12HandoffTimeline::decorate($this->request(), $body, 'INBOUND', 'DETAIL');
                $this->fail('Unverified source accepted');
            } catch (HttpResponseException $error) {
                $this->assertSame(503, $error->getResponse()->getStatusCode());
            }
        }
    }

    public function test_list_warranty_and_wrong_contract_are_not_supported(): void
    {
        foreach ([['INBOUND', 'LIST', P12HandoffTimeline::CONTRACT], ['WARRANTY', 'DETAIL', P12HandoffTimeline::CONTRACT],
            ['INBOUND', 'DETAIL', 'unapproved']] as [$source, $mode, $contract]) {
            $request = $this->request();
            $request->headers->set(P12HandoffTimeline::HEADER, $contract);
            try {
                P12HandoffTimeline::decorate($request, $this->body(), $source, $mode);
                $this->fail('Unsupported contract accepted');
            } catch (HttpResponseException $error) {
                $this->assertSame(422, $error->getResponse()->getStatusCode());
            }
        }
    }

    private function request(bool $optIn = true): Request
    {
        $request = Request::create('/api/v1/mini-app/inbound-documents/'.self::ID);
        $route = new Route('GET', 'api/v1/mini-app/inbound-documents/{id}', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        if ($optIn) {
            $request->headers->set(P12HandoffTimeline::HEADER, P12HandoffTimeline::CONTRACT);
        }

        return $request;
    }

    private function body(): array
    {
        $row = ['id' => self::ID, 'dst_warehouse_id' => self::WAREHOUSE, 'status' => 'SCANNING',
            'created_at' => '2026-10-24T07:00:00Z', 'posted_at' => null];
        $row['p12'] = P12DocumentPresentation::record($row, 'INBOUND', true);
        $row['p13'] = ['current_handoff_availability' => 'RECORDED', 'record_handoff' => null,
            'current_handoff' => ['contract' => 'p13-web-post-queue-v1', 'warehouse_id' => self::WAREHOUSE,
                'source' => 'mini_app_web_post_handoffs+stock_documents.status',
                'document_version_at_record' => 2, 'submitted_at' => '2026-10-24T08:12:45.123456Z']];

        return ['success' => true, 'data' => $row, 'meta' => ['p12' => P12DocumentPresentation::metadata('INBOUND')]];
    }
}
