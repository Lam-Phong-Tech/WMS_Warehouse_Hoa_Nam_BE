<?php

namespace Tests\Unit;

use Illuminate\Http\JsonResponse;
use Modules\Warehouse\Support\P12DocumentReadContract;
use PHPUnit\Framework\TestCase;

class P12ScanEvidenceResponseTest extends TestCase
{
    private const ID = '00000000-0000-4000-8000-000000000012';

    public function test_current_route_evidence_overwrites_reserved_legacy_meta_only(): void
    {
        $summary = $this->summary();
        $response = new JsonResponse(['success' => true, 'data' => [['id' => 'unchanged-row']], 'meta' => [
            'contract' => 'p12-scan-evidence-v1', 'document_id' => 'wrong-parent', 'scan_status' => 'REMOVED',
            'summary' => ['quantity_total' => 999], 'snapshot_consistent' => true,
            'pagination_mode' => 'cursor', 'sort' => ['id' => 'asc'],
            'total' => 2, 'current_page' => 2, 'request_id' => 'retain-request-id',
        ]]);
        $response->headers->set('ETag', '"owner-version"');
        $result = P12DocumentReadContract::evidenceResponse($response, $summary, self::ID, 'ALL');
        $body = $result->getData(true);
        $this->assertSame('p12-scan-evidence-v2', $body['meta']['contract']);
        $this->assertSame(self::ID, $body['meta']['document_id']);
        $this->assertSame('ALL', $body['meta']['scan_status']);
        $this->assertSame($summary, $body['meta']['summary']);
        $this->assertFalse($body['meta']['snapshot_consistent']);
        $this->assertSame('offset', $body['meta']['pagination_mode']);
        $this->assertSame(['created_at' => 'desc', 'id' => 'desc'], $body['meta']['sort']);
        $this->assertSame(2, $body['meta']['total']);
        $this->assertSame(2, $body['meta']['current_page']);
        $this->assertSame('retain-request-id', $body['meta']['request_id']);
        $this->assertSame([['id' => 'unchanged-row']], $body['data']);
        $this->assertSame('"owner-version"', $result->headers->get('ETag'));
        $this->assertStringContainsString('private', $result->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
    }

    public function test_legacy_cache_never_retains_complete_or_current_coverage_flags(): void
    {
        $summary = ['entry_count' => 2, 'quantity_total' => 2,
            'quantity_by_unit' => [['unit' => 'cai', 'quantity' => 2]],
            'unit_complete' => true, 'mixed_units' => false, 'coverage' => 'CURRENT_QUERY'];
        $result = $this->render($summary);
        $this->assertPending($result);
        $this->assertSame(2, $result['entry_count']);
        $this->assertNull($result['quantity_by_unit'][0]['quantity']);
        $this->assertNull($result['quantity_by_unit'][0]['known_quantity']);
        $this->assertNull($result['quantity_by_unit'][0]['missing_quantity_count']);
    }

    public function test_one_v2_boolean_is_not_a_complete_cache_schema(): void
    {
        $summary = ['entry_count' => 2, 'quantity_complete' => true, 'quantity_total' => 2,
            'quantity_by_unit' => [['unit' => 'cai', 'quantity' => 2]]];
        $this->assertPending($this->render($summary));
        $summary = $this->summary();
        unset($summary['quantity_by_unit'][0]['known_quantity']);
        $this->assertPending($this->render($summary));
    }

    public function test_each_missing_v2_field_requires_refresh_instead_of_numeric_fallback(): void
    {
        foreach (['quantity_total', 'quantity_by_unit', 'quantity_complete', 'missing_quantity_count',
            'unit_complete', 'unknown_unit_entry_count', 'mixed_units', 'coverage'] as $field) {
            $summary = $this->summary();
            unset($summary[$field]);
            $this->assertPending($this->render($summary));
        }
    }

    public function test_unknown_counters_and_malformed_groups_cannot_claim_current_evidence(): void
    {
        $summary = $this->summary();
        $summary['missing_quantity_count'] = null;
        $this->assertPending($this->render($summary));
        $summary = $this->summary();
        $summary['quantity_by_unit'][0]['entry_count'] = null;
        $this->assertPending($this->render($summary));
        $summary['quantity_by_unit'] = [null, 'invalid'];
        $this->assertPending($this->render($summary));
    }

    public function test_nullable_group_quantity_and_known_subtotal_survive_response_decoration(): void
    {
        $summary = $this->summary();
        $result = $this->render($summary);
        $this->assertSame($summary, $result);
        $this->assertNull($result['quantity_total']);
        $this->assertNull($result['quantity_by_unit'][0]['quantity']);
        $this->assertSame(2, $result['quantity_by_unit'][0]['known_quantity']);
        $this->assertFalse($result['quantity_complete']);
    }

    public function test_confirmed_empty_zero_is_distinct_from_unverified_legacy_zero(): void
    {
        $summary = array_replace($this->summary(), ['entry_count' => 0, 'sku_count' => 0,
            'item_count' => 0, 'resolved_serial_count' => 0, 'quantity_total' => 0,
            'quantity_by_unit' => [], 'quantity_complete' => true, 'missing_quantity_count' => 0]);
        $this->assertSame($summary, $this->render($summary));
        $this->assertPending($this->render(['entry_count' => 0, 'quantity_total' => 0, 'quantity_by_unit' => []]));
    }

    private function render(array $summary): array
    {
        return P12DocumentReadContract::evidenceResponse(
            new JsonResponse(['success' => true, 'data' => [], 'meta' => ['total' => 2]]),
            $summary, self::ID, 'ALL'
        )->getData(true)['meta']['summary'];
    }

    private function assertPending(array $summary): void
    {
        $this->assertSame('LEGACY_CACHE_PENDING_REFRESH', $summary['coverage']);
        foreach (['quantity_total', 'quantity_complete', 'missing_quantity_count', 'unit_complete',
            'unknown_unit_entry_count', 'mixed_units'] as $field) {
            $this->assertNull($summary[$field]);
        }
    }

    private function summary(): array
    {
        return [
            'scope' => 'FILTERED_DOCUMENT_SCAN_ENTRIES', 'entry_count' => 2, 'sku_count' => 1,
            'item_count' => 1, 'resolved_serial_count' => 1, 'quantity_total' => null,
            'quantity_by_unit' => [['unit' => 'cai', 'quantity' => null, 'known_quantity' => 2,
                'entry_count' => 2, 'missing_quantity_count' => 1]],
            'quantity_complete' => false, 'missing_quantity_count' => 1, 'unit_complete' => true,
            'unknown_unit_entry_count' => 0, 'mixed_units' => false, 'coverage' => 'CURRENT_QUERY',
        ];
    }
}
