<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Services\ComponentIssueNumber;
use Modules\Warehouse\Services\WarrantyCaseNumber;
use Tests\TestCase;

class WarrantyAndComponentNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_warranty_numbers_use_vietnam_daily_sequence_and_reset_at_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03T16:59:50Z')); // 23:59:50 Asia/Ho_Chi_Minh
        $service = app(WarrantyCaseNumber::class);

        $this->assertSame('WC-20261103-0001', $service->next());
        $this->assertSame('WC-20261103-0002', $service->next());

        $this->travelTo(Carbon::parse('2026-11-03T17:00:01Z')); // 00:00:01 next Vietnam day
        $this->assertSame('WC-20261104-0001', $service->next());
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
}
