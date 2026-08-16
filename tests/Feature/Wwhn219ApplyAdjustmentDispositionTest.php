<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Locks disposition DISP-ADJ-001 (docs/decision-records/DISPOSITION-APPLY-ADJUSTMENT.md).
 *
 * `apply-adjustment` is a business action name that maps onto the append-only
 * Reversal/Correction surface. There must be no independent stock-mutation route,
 * and the canonical reversal routes must stay guarded by `inventory.reversal.post`.
 */
class Wwhn219ApplyAdjustmentDispositionTest extends TestCase
{
    public function test_no_apply_adjustment_or_stocktake_mutation_route_is_registered(): void
    {
        $offending = [];
        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());
            if (str_contains($uri, 'apply-adjustment') || str_contains($uri, 'stocktake')) {
                $offending[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $offending,
            'DISP-ADJ-001 forbids an independent adjustment/stocktake surface; found: '.implode(', ', $offending)
        );
    }

    public function test_canonical_reversal_routes_carry_the_reversal_permission(): void
    {
        $expected = [
            'api/v1/inbound-documents/{id}/reverse',
            'api/v1/outbound-documents/{id}/reverse',
            'api/v1/reversal-documents',
        ];

        $guarded = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if ((string) $middleware === 'permission:inventory.reversal.post') {
                    $guarded[] = $route->uri();
                }
            }
        }

        foreach ($expected as $uri) {
            $this->assertContains(
                $uri,
                $guarded,
                "Canonical adjustment path {$uri} must remain guarded by inventory.reversal.post."
            );
        }
    }

    public function test_disposition_record_exists_and_selects_option_b(): void
    {
        $path = base_path('docs/decision-records/DISPOSITION-APPLY-ADJUSTMENT.md');
        $this->assertFileExists($path, 'WWHN-219 requires a written apply-adjustment disposition.');

        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('DISP-ADJ-001', $contents);
        $this->assertStringContainsString('Option B', $contents);
        $this->assertStringContainsString('REQ-SRS-013', $contents);
        $this->assertStringContainsString('BR-REV-01', $contents);
    }
}
