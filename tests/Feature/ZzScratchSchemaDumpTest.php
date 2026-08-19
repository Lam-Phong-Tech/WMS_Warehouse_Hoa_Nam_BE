<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ZzScratchSchemaDumpTest extends TestCase
{
    use RefreshDatabase;

    public function test_dump(): void
    {
        $tables = ['sku', 'skus', 'products', 'inventory_balances', 'pv_product_profile', 'pv_product_profiles',
            'pv_industry', 'pv_industries', 'pv_filter_tag', 'pv_tags', 'pv_product_industry', 'pv_product_industries',
            'pv_product_tag', 'pv_product_tags', 'pv_recommendation_rule', 'pv_recommendation_rules',
            'pv_product_images', 'brands', 'product_categories', 'power_sources'];
        $out = [];
        foreach ($tables as $t) {
            $kind = Schema::hasTable($t) ? 'table' : (Schema::hasView($t) ? 'view' : 'MISSING');
            $cols = $kind === 'MISSING' ? [] : Schema::getColumnListing($t);
            $out[] = $t.' ['.$kind.'] = '.implode(',', $cols);
        }
        fwrite(STDERR, "\n".implode("\n", $out)."\n");
        $this->assertTrue(true);
    }
}
