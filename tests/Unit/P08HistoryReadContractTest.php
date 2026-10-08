<?php

namespace Tests\Unit;

use Modules\Warehouse\Http\Middleware\P08HistoryReadContract;
use Modules\Warehouse\Support\P08HistoryPolicy;
use PHPUnit\Framework\TestCase;

class P08HistoryReadContractTest extends TestCase
{
    public function test_session_allows_only_scope_and_pagination(): void
    {
        $this->assertSame([], P08HistoryReadContract::unexpectedFilters('SCAN_SESSION', ['warehouse_id' => 'w', 'limit' => 20, 'cursor' => 'c']));
        foreach (['source', 'status', 'business', 'keyword', 'date_mode', 'date', 'from', 'to', 'activityId', 'tenant_id', 'org_id', 'user_id'] as $field) {
            $this->assertSame([$field], P08HistoryReadContract::unexpectedFilters('SCAN_SESSION', [$field => 'x']));
        }
    }

    public function test_summary_has_no_pagination_but_records_keep_list_filters(): void
    {
        $this->assertSame(['limit', 'cursor', 'activityId'], P08HistoryReadContract::unexpectedFilters('SUMMARY', ['limit' => 1, 'cursor' => 'c', 'activityId' => 'id']));
        $this->assertSame([], P08HistoryReadContract::unexpectedFilters('SUMMARY', ['source' => 'SCAN', 'date_mode' => 'DATE', 'date' => '2026-10-08']));
        $this->assertSame([], P08HistoryReadContract::unexpectedFilters('RECORDS', ['status' => 'ACCEPTED', 'limit' => 1]));
    }

    public function test_counts_preserve_unclassified_statuses_and_unknown_quantity(): void
    {
        $counts = P08HistoryPolicy::scanCounts(['ACCEPTED' => 2, 'DUPLICATE' => 1, 'RESOLVED' => 3, 'RECEIVED' => 4]);
        $this->assertSame(10, $counts['total']);
        $this->assertSame(2, $counts['accepted']);
        $this->assertSame(1, $counts['duplicate']);
        $this->assertSame(7, $counts['other']);
        $this->assertNull($counts['quantity']);
    }

    public function test_cursor_is_bound_to_actor_scope_filter_and_expiry(): void
    {
        $scope = ['warehouse_id' => 'w', 'actor_id' => 'u'];
        $filters = ['source' => 'SCAN', 'limit' => 1];
        $fingerprint = P08HistoryPolicy::fingerprint($scope, $filters);
        $cursor = ['v' => 1, 'scope' => $fingerprint, 'expires' => 200, 'asOf' => '2026-10-08', 'time' => '', 'activityId' => 'SCAN:id'];
        $this->assertTrue(P08HistoryPolicy::cursorMatches($cursor, $fingerprint, 100));
        $this->assertFalse(P08HistoryPolicy::cursorMatches($cursor, $fingerprint, 200));
        $this->assertFalse(P08HistoryPolicy::cursorMatches($cursor, P08HistoryPolicy::fingerprint(['warehouse_id' => 'other', 'actor_id' => 'u'], $filters), 100));
        $this->assertFalse(P08HistoryPolicy::cursorMatches($cursor, P08HistoryPolicy::fingerprint($scope, [...$filters, 'status' => 'ACCEPTED']), 100));
    }
}
