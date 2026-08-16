<?php

declare(strict_types=1);

/**
 * WWHN-219 immutable evidence manifest.
 *
 * Produces the single document that acceptance is read from. Everything it
 * records is derived from the build that actually ran: the exact SHA, the CI run,
 * the live permission catalog and its digest, the executed full-suite counts and
 * the executed runtime concurrency result.
 *
 * Deliberately records PR #4 (`dev219-p1-hardening-20260815`) as CLOSED / UNMERGED
 * with `acceptance_evidence: NO`, and pins the legacy sources that must never be
 * cited for m1 acceptance.
 */

require __DIR__.'/../vendor/autoload.php';

$evidenceDir = $argv[1] ?? (getenv('WWHN219_EVIDENCE_DIR') ?: 'storage/app/wwhn-219-evidence');
if (! is_dir($evidenceDir)) {
    fwrite(STDERR, "Evidence directory not found: {$evidenceDir}\n");
    exit(2);
}

function readJson(string $path): ?array
{
    if (! is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

function readTrimmed(string $path): ?string
{
    return is_file($path) ? trim((string) file_get_contents($path)) : null;
}

/** Parse PHPUnit JUnit XML into executed/passed counts. */
function junitCounts(string $path): array
{
    if (! is_file($path)) {
        return ['available' => false];
    }

    $xml = @simplexml_load_file($path);
    if ($xml === false) {
        return ['available' => false];
    }

    $tests = 0;
    $failures = 0;
    $errors = 0;
    $skipped = 0;
    foreach ($xml->xpath('//testsuite') ?: [] as $suite) {
        // Only the top-level suites carry the roll-up; nested ones would double count.
        if ($suite->xpath('..') !== [] && $suite->xpath('..')[0]->getName() === 'testsuite') {
            continue;
        }
        $tests += (int) $suite['tests'];
        $failures += (int) $suite['failures'];
        $errors += (int) $suite['errors'];
        $skipped += (int) $suite['skipped'];
    }

    return [
        'available' => true,
        'total' => $tests,
        'failures' => $failures,
        'errors' => $errors,
        'skipped' => $skipped,
        'passed' => $tests - $failures - $errors - $skipped,
        // PHPUnit counts errors separately from failures. Reading only `failures`
        // understates how red a run is, so publish the combined number too.
        'red' => $failures + $errors,
        'green' => $failures === 0 && $errors === 0,
    ];
}

$permissionSummary = readJson($evidenceDir.'/permission-summary.json');
$concurrency = readJson($evidenceDir.'/runtime-concurrency-summary.json');
$fullSuite = junitCounts($evidenceDir.'/phpunit-full-suite.xml');
$regression = junitCounts($evidenceDir.'/phpunit-wwhn219-regression.xml');

$finalSha = getenv('GITHUB_SHA') ?: readTrimmed($evidenceDir.'/EXACT_GIT_SHA');
$runId = getenv('GITHUB_RUN_ID') ?: readTrimmed($evidenceDir.'/GITHUB_RUN_ID');

$manifest = [
    'schema_version' => '1.0.0',
    'ticket' => 'WWHN-219',
    'generated_at' => gmdate('c'),

    'canonical_source' => [
        'repository' => 'Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE',
        'branch' => 'm1',
        'final_sha' => $finalSha,
        'final_sha_is_40_char' => is_string($finalSha) && preg_match('/^[0-9a-f]{40}$/', $finalSha) === 1,
    ],

    'historical_pr_4' => [
        'title' => 'fix(DEV219): close audit, PostgreSQL and reconciliation P1 gaps',
        'head_branch' => 'dev219-p1-hardening-20260815',
        'status' => 'CLOSED',
        'merged' => false,
        'acceptance_evidence' => 'NO',
        'note' => 'PR #4 was never merged. Its SHA, CI runs and artifacts are HISTORICAL only and must not be cited as evidence that m1 passes.',
    ],

    'legacy_sources_excluded' => [
        'Lam-Phong-Tech/Hoa_Nam_Backend',
        'm1-be-2',
        'dev219-p1-hardening-20260815',
    ],

    'ci' => [
        'run_id' => $runId,
        'run_attempt' => readTrimmed($evidenceDir.'/GITHUB_RUN_ATTEMPT'),
        'workflow' => 'WWHN-219 Final Gate',
        'postgres' => readTrimmed($evidenceDir.'/POSTGRES_VERSION'),
        'database_prepared_by' => 'php artisan migrate:fresh --seed --force',
    ],

    'full_test_suite' => $fullSuite,
    'wwhn219_regression_suite' => $regression,

    'runtime_concurrency' => [
        'artifact' => 'runtime-concurrency-summary.json',
        'evidence_type' => 'EXECUTED_MULTI_PROCESS_POSTGRESQL',
        'status' => $concurrency['status'] ?? null,
        'scenarios' => $concurrency === null ? null : array_map(
            static fn (array $s): array => [
                'passed' => $s['passed'],
                'distinct_backend_pids' => $s['distinct_backend_pids'] ?? null,
                'observed_pg_lock_wait' => $s['observed_pg_lock_wait'] ?? null,
                'max_worker_lock_wait_ms' => $s['max_worker_lock_wait_ms'] ?? null,
            ],
            $concurrency['scenarios'] ?? []
        ),
        'supersedes' => 'StockDocumentConcurrencyGuardTest is a source/architecture guard only and is NOT concurrency evidence.',
    ],

    'permission_catalog' => [
        'artifact' => 'permission-catalog.json',
        'csv_artifact' => 'permission-catalog.csv',
        'generated_from' => 'LIVE_DATABASE_AFTER_MIGRATE_FRESH_SEED',
        'count' => $permissionSummary['permission_count'] ?? null,
        'sha256' => $permissionSummary['permission_catalog_sha256'] ?? null,
        'stale_31_snapshot_rejected' => $permissionSummary['stale_snapshot_31_rejected'] ?? null,
        'note' => 'AuthDatabaseSeeder chains WmsPermissionCompatibilitySeeder, which keeps upserting FE alias permissions, so any hand-counted constant is wrong by construction.',
    ],

    'apply_adjustment_disposition' => [
        'disposition_id' => 'DISP-ADJ-001',
        'option' => 'B — business action name mapped to append-only Reversal/Correction; no independent endpoint',
        'record' => 'docs/decision-records/DISPOSITION-APPLY-ADJUSTMENT.md',
        'locked_by_test' => 'tests/Feature/Wwhn219ApplyAdjustmentDispositionTest.php',
        'ba_signoff_comment_url' => getenv('WWHN219_BA_SIGNOFF_URL') ?: null,
        'status' => getenv('WWHN219_BA_SIGNOFF_URL') ? 'ACCEPTED' : 'PENDING_BA_ACCEPT',
    ],

    'human_signoff' => [
        'qa1_retest_comment_url' => getenv('WWHN219_QA1_SIGNOFF_URL') ?: null,
        'qa1_status' => getenv('WWHN219_QA1_SIGNOFF_URL') ? 'RETESTED_ON_FINAL_SHA' : 'PENDING_QA1_RETEST',
        'tech_review_comment_url' => getenv('WWHN219_TECH_SIGNOFF_URL') ?: null,
        'tech_status' => getenv('WWHN219_TECH_SIGNOFF_URL') ? 'REVIEWED' : 'PENDING_TECH_REVIEW',
        'rule' => 'QA1 must retest this exact FINAL_SHA and this exact CI artifact. Any commit after evidence collection voids the manifest and requires a fresh run.',
    ],

    'open_p1' => (int) (getenv('WWHN219_OPEN_P1') ?: 0),
];

// Machine gates: everything that can be decided from the build itself.
$machineGates = [
    'final_sha_pinned' => $manifest['canonical_source']['final_sha_is_40_char'],
    'ci_run_recorded' => $runId !== null && $runId !== '',
    'full_suite_executed' => ($fullSuite['available'] ?? false) === true,
    'full_suite_green' => ($fullSuite['green'] ?? false) === true,
    'regression_suite_executed' => ($regression['available'] ?? false) === true,
    'regression_suite_green' => ($regression['green'] ?? false) === true,
    'runtime_concurrency_pass' => ($concurrency['status'] ?? null) === 'PASS',
    'permission_catalog_generated' => ($permissionSummary['permission_count'] ?? 0) > 0,
    'permission_catalog_not_stale_31' => ($permissionSummary['stale_snapshot_31_rejected'] ?? false) === true,
    'no_adjustment_mutation_route' => ($permissionSummary['apply_adjustment_or_stocktake_mutation_routes'] ?? null) === [],
    'no_open_p1' => $manifest['open_p1'] === 0,
];
$manifest['machine_gates'] = $machineGates;
$manifest['machine_gate_status'] = in_array(false, $machineGates, true) ? 'FAIL' : 'PASS';

// Human gates are reported, never auto-satisfied.
$manifest['human_gate_status'] = (
    $manifest['apply_adjustment_disposition']['status'] === 'ACCEPTED'
    && $manifest['human_signoff']['qa1_status'] === 'RETESTED_ON_FINAL_SHA'
    && $manifest['human_signoff']['tech_status'] === 'REVIEWED'
) ? 'COMPLETE' : 'PENDING';

$manifest['acceptance_ready'] = $manifest['machine_gate_status'] === 'PASS'
    && $manifest['human_gate_status'] === 'COMPLETE';

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
file_put_contents($evidenceDir.'/WWHN-219-EVIDENCE-MANIFEST.json', $json);

echo $json;

// The manifest generator fails the build only on machine gates. Human sign-off is
// reported as PENDING so the build stays honest without blocking evidence capture.
exit($manifest['machine_gate_status'] === 'PASS' ? 0 : 1);
