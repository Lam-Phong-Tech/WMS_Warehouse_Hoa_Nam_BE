<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Locks DISP-STK-230 (docs/decision-records/DISP-STK-230-scope-disposition.md).
 *
 * WWHN-230 / WWHN-58 are parked out of Beta implementation and WWHN-232 becomes an
 * absence & substitution test, because REQ-SRS-037 ("Must") in the adopted SRS/FRD v1.12
 * baseline forbids every Stocktake surface. This class is the machine form of that
 * decision: it proves the surface is absent at route, permission, schema, spec and source
 * level, and that the substitute correction path is still guarded.
 *
 * The guard must never outlive its baseline: if REQ-SRS-037 or the ERD exclusion row
 * disappears from the source documents, these tests fail and force a re-review instead of
 * silently enforcing a rule nobody approved any more.
 */
class Wwhn230StocktakeScopeDispositionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Semantics forbidden by REQ-SRS-037. `variance` is intentionally absent from this
     * list because reconciliation legitimately reports divergence; what is forbidden is a
     * stocktake variance entity, covered by the explicit column/table assertions below.
     */
    private const FORBIDDEN_URI_FRAGMENTS = [
        'stocktake',
        'stock-take',
        'apply-adjustment',
        'inventory-snapshots',
        'stock-adjustments',
    ];

    private const FORBIDDEN_TABLES = [
        'stocktake_session',
        'stocktake_sessions',
        'stocktake_snapshot',
        'stocktake_snapshots',
        'stocktake_count',
        'stocktake_counts',
        'stocktake_variance',
        'stocktake_variances',
        'variance_line',
        'variance_lines',
    ];

    public function test_no_stocktake_route_is_registered(): void
    {
        $offending = [];

        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());
            foreach (self::FORBIDDEN_URI_FRAGMENTS as $fragment) {
                if (str_contains($uri, $fragment)) {
                    $offending[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offending,
            'DISP-STK-230 / REQ-SRS-037 forbid a Stocktake or direct stock-edit route; found: '
                .implode(', ', $offending)
        );
    }

    public function test_no_stocktake_permission_exists(): void
    {
        $offending = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/permission:.*stocktake/i', $middleware) === 1) {
                    $offending[] = $route->uri().' -> '.$middleware;
                }
            }
        }

        $this->assertSame([], $offending, 'No route may be guarded by a stocktake.* permission.');

        foreach ([
            'Modules/Auth/database/seeders/AuthDatabaseSeeder.php',
            'Modules/Auth/database/seeders/WmsPermissionCompatibilitySeeder.php',
        ] as $relative) {
            $path = base_path($relative);
            if (! is_file($path)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/[\'"]stocktake\./',
                (string) file_get_contents($path),
                "{$relative} must not seed a stocktake.* permission."
            );
        }
    }

    public function test_no_stocktake_table_exists_after_migrate(): void
    {
        $objects = array_map('strtolower', $this->databaseObjects());

        foreach (self::FORBIDDEN_TABLES as $table) {
            $this->assertNotContains(
                $table,
                $objects,
                "ERD section 0.1 excludes {$table} from the baseline; migrations must leave no table or view with that name."
            );
        }
    }

    public function test_stock_movements_has_no_stocktake_variance_column(): void
    {
        $columns = array_map('strtolower', $this->columnsOf('stock_movements'));

        $this->assertNotEmpty($columns, 'stock_movements must exist; the ledger is the inventory authority.');
        $this->assertNotContains(
            'stocktake_variance_id',
            $columns,
            'stock_movements.stocktake_variance_id was dropped by the ERD alignment migration and must not return.'
        );
    }

    public function test_no_stocktake_state_machine_or_service_exists(): void
    {
        $offending = [];

        foreach ([
            'Modules/Warehouse/app/Http/Controllers',
            'Modules/Warehouse/app/Services',
            'Modules/Warehouse/app/Models',
        ] as $relative) {
            $directory = base_path($relative);
            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFilesIn($directory) as $file) {
                $name = strtolower(basename($file));
                if (str_contains($name, 'stocktake') || str_contains($name, 'variance')) {
                    $offending[] = $relative.'/'.basename($file);
                }
            }
        }

        $this->assertSame(
            [],
            $offending,
            'No controller, service or model may implement a Stocktake session/count/variance state machine; found: '
                .implode(', ', $offending)
        );
    }

    public function test_generated_openapi_has_no_stocktake_path(): void
    {
        $specPath = base_path('storage/api-docs/api-docs.json');
        if (! is_file($specPath)) {
            $this->markTestSkipped('Generated OpenAPI spec is not present in this checkout.');
        }

        $spec = json_decode((string) file_get_contents($specPath), true, 512, JSON_THROW_ON_ERROR);
        $paths = array_keys($spec['paths'] ?? []);

        $offending = array_values(array_filter(
            $paths,
            static fn (string $path): bool => str_contains(strtolower($path), 'stocktake')
                || str_contains(strtolower($path), 'apply-adjustment')
        ));

        $this->assertSame([], $offending, 'Generated OpenAPI must expose no Stocktake path; found: '.implode(', ', $offending));
    }

    public function test_baseline_contract_still_forbids_stocktake(): void
    {
        $contract = json_decode(
            (string) file_get_contents(base_path('docs/contracts/HoaNam_Current_Baseline_Contract.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $fragments = $this->collectValues($contract, 'forbidden_path_fragments');

        $this->assertContains(
            'stocktake',
            $fragments,
            'The current-baseline contract must keep "stocktake" in forbidden_path_fragments.'
        );
    }

    public function test_canonical_correction_routes_stay_permission_guarded(): void
    {
        $guarded = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if ((string) $middleware === 'permission:inventory.reversal.post') {
                    $guarded[] = $route->uri();
                }
            }
        }

        foreach ([
            'api/v1/inbound-documents/{id}/reverse',
            'api/v1/outbound-documents/{id}/reverse',
            'api/v1/reversal-documents',
        ] as $uri) {
            $this->assertContains(
                $uri,
                $guarded,
                "De-scoping Stocktake only holds if the substitute correction path {$uri} stays guarded by inventory.reversal.post."
            );
        }
    }

    public function test_disposition_record_selects_option_b_and_anchors_the_requirement(): void
    {
        $disposition = (string) file_get_contents(
            base_path('docs/decision-records/DISP-STK-230-scope-disposition.md')
        );

        $this->assertStringContainsString('DISP-STK-230', $disposition);
        $this->assertStringContainsString('REQ-SRS-037', $disposition);
        $this->assertMatchesRegularExpression(
            '/Option chosen\s*\|\s*\*\*B\b|Option chọn\s*\|\s*\*\*B\b/u',
            $disposition,
            'The disposition must record Option B (park/de-scope) explicitly.'
        );

        $approval = json_decode(
            (string) file_get_contents(base_path('docs/decision-records/DISP-STK-230-APPROVAL.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('B', $approval['optionChosen'] ?? null);
        $this->assertSame('REQ-SRS-037', $approval['controllingRequirement'] ?? null);
        $this->assertContains('WWHN-230', $approval['impactedIssues'] ?? []);
        $this->assertContains('WWHN-232', $approval['impactedIssues'] ?? []);

        // The disposition is a scope decision, not an approval of DEC-STK-001.
        $this->assertArrayHasKey('effectiveDate', $approval);
        $this->assertNull(
            $approval['effectiveDate'],
            'effectiveDate stays null until an approver records the sign-off on Jira.'
        );
    }

    public function test_guard_does_not_outlive_its_baseline(): void
    {
        $srs = (string) file_get_contents(base_path('docs/HN_SRS_FRD_v1.12_FULL.md'));

        $this->assertStringContainsString(
            'REQ-SRS-037',
            $srs,
            'REQ-SRS-037 disappeared from the SRS baseline; re-review DISP-STK-230 before enforcing it.'
        );
        $this->assertStringContainsString(
            'Không expose Stocktake/Variance',
            $srs,
            'The SRS no longer states the no-Stocktake requirement; the guard must not outlive it.'
        );

        $erd = (string) file_get_contents(base_path('docs/HoaNam_ERD_Moi_Nhat.md'));
        $this->assertStringContainsString(
            'Không tạo Stocktake_Session',
            $erd,
            'ERD section 0.1 no longer excludes Stocktake entities; re-review DISP-STK-230.'
        );
    }

    public function test_temp_key_register_resolves_the_wwhn_230_dependency(): void
    {
        $register = json_decode(
            (string) file_get_contents(base_path('docs/traceability/TEMP_KEY_CANONICAL_REGISTER.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $byKey = [];
        foreach ($register['entries'] ?? [] as $entry) {
            $byKey[$entry['tempKey']] = $entry;
        }

        $this->assertArrayHasKey('HN-INV-001', $byKey, 'WWHN-230 depends on HN-INV-001; it must be mapped.');
        $this->assertSame('WWHN-48', $byKey['HN-INV-001']['jiraKey']);
        $this->assertSame('CONFIRMED', $byKey['HN-INV-001']['status']);

        foreach ($byKey as $tempKey => $entry) {
            if (($entry['status'] ?? null) !== 'CONFIRMED') {
                continue;
            }

            $evidencePath = base_path((string) $entry['evidenceFile']);
            $this->assertFileExists($evidencePath, "Evidence file for {$tempKey} is missing.");

            $evidence = (string) file_get_contents($evidencePath);
            $this->assertStringContainsString($tempKey, $evidence, "Evidence for {$tempKey} does not mention the temp key.");
            $this->assertStringContainsString(
                (string) $entry['jiraKey'],
                $evidence,
                "Evidence for {$tempKey} does not mention {$entry['jiraKey']}."
            );
        }
    }

    /** @return array<int, string> */
    private function databaseObjects(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("SELECT name FROM sqlite_master WHERE type IN ('table', 'view')"))
                ->pluck('name')
                ->all();
        }

        if ($driver === 'pgsql') {
            return collect(DB::select(<<<'SQL'
                SELECT c.relname AS name
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema()
                  AND c.relkind IN ('r', 'p', 'v')
            SQL))
                ->pluck('name')
                ->all();
        }

        return collect(DB::select('SHOW FULL TABLES'))
            ->map(fn (object $row): string => (string) array_values((array) $row)[0])
            ->all();
    }

    /** @return array<int, string> */
    private function columnsOf(string $table): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA table_info('{$table}')"))->pluck('name')->all();
        }

        return collect(DB::select(
            'SELECT column_name AS name FROM information_schema.columns WHERE table_name = ?',
            [$table]
        ))->pluck('name')->all();
    }

    /** @return array<int, string> */
    private function phpFilesIn(string $directory): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>  $haystack
     * @return array<int, string>
     */
    private function collectValues(array $haystack, string $key): array
    {
        $found = [];

        foreach ($haystack as $current => $value) {
            if ($current === $key && is_array($value)) {
                foreach ($value as $item) {
                    $found[] = (string) $item;
                }
            } elseif (is_array($value)) {
                $found = array_merge($found, $this->collectValues($value, $key));
            }
        }

        return $found;
    }
}
