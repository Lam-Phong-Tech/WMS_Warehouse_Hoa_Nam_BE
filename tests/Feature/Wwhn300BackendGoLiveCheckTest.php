<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * WWHN-300 — "Backend GoLive check" (subtask of WWHN-80 cutover/rollback control).
 *
 * The command is the reviewable output of this ticket, so the contract that
 * matters is: it runs against a migrated+seeded database, it reports every
 * readiness dimension the cutover runbook asks about, and it fails loudly
 * rather than reporting an unknown state as ready.
 */
class Wwhn300BackendGoLiveCheckTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_CHECKS = [
        'migrations.applied',
        'migrations.reversible',
        'schema.canonical_relations',
        'seed.identity_markers',
        'security.runtime_config',
        'security.route_default_deny',
        'core.idempotency_store',
        'core.audit_backlog',
        'core.reconciliation_backlog',
    ];

    /**
     * The rest of the suite deliberately builds its own fixtures instead of
     * seeding, and RefreshDatabase shares one in-memory database across the
     * whole process — so `protected $seed` only takes effect when this class
     * happens to run first. Seed on demand instead, or the readiness gate would
     * silently pass/fail depending on test ordering.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! User::query()->where('username', 'admin')->exists()) {
            $this->artisan('db:seed', ['--force' => true])->run();
        }
    }

    public function test_golive_check_passes_on_a_migrated_and_seeded_backend(): void
    {
        $this->artisan('wms:golive-check')->assertSuccessful();
    }

    public function test_golive_check_emits_machine_readable_evidence_with_every_dimension(): void
    {
        $path = storage_path('app/testing/wwhn-300-golive-check.json');
        File::delete($path);

        $this->artisan('wms:golive-check', ['--json' => $path])->assertSuccessful();

        $this->assertFileExists($path);
        $payload = json_decode((string) File::get($path), true);

        $this->assertSame('WWHN-300', $payload['jira']);
        $this->assertSame('Backend GoLive check', $payload['output']);
        $this->assertSame('pass', $payload['overall']);
        $this->assertSame(0, $payload['failed_count']);

        foreach (self::EXPECTED_CHECKS as $check) {
            $this->assertArrayHasKey($check, $payload['checks'], "GoLive check is missing dimension {$check}");
            $this->assertContains($payload['checks'][$check]['status'], ['ok', 'warn'], "Dimension {$check} did not pass");
        }

        File::delete($path);
    }

    /**
     * SRS v1.12 §8.1 default-deny. A route added without auth/permission
     * middleware passes every positive-path test but is an open endpoint in
     * production, so the readiness gate asserts the surface itself.
     */
    public function test_protected_api_surface_is_default_deny(): void
    {
        $path = storage_path('app/testing/wwhn-300-route-guard.json');
        File::delete($path);

        $this->artisan('wms:golive-check', ['--json' => $path])->assertSuccessful();
        $detail = json_decode((string) File::get($path), true)['checks']['security.route_default_deny']['detail'];

        $this->assertSame([], $detail['unauthenticated_non_public']);
        $this->assertSame([], $detail['authenticated_without_permission']);

        File::delete($path);
    }

    /**
     * The cutover abort path depends on down() actually doing something. This
     * pins the fail-forward set that is a governed decision, so a NEW empty
     * down() shows up as a review finding instead of silently making
     * migrate:rollback a partial no-op.
     */
    public function test_fail_forward_migrations_stay_an_explicit_known_set(): void
    {
        $path = storage_path('app/testing/wwhn-300-reversible.json');
        File::delete($path);

        $this->artisan('wms:golive-check', ['--json' => $path])->assertSuccessful();
        $irreversible = json_decode((string) File::get($path), true)['checks']['migrations.reversible']['detail']['irreversible'];

        // Baseline measured on m1 at WWHN-300 closure. The gate is "must not
        // grow": the existing fail-forward steps are a governed schema-contract
        // decision, a NEW one silently shrinks the cutover abort path further.
        $this->assertLessThanOrEqual(
            75,
            count($irreversible),
            'A new migration was added with an empty down(); implement the inverse or record a disposition: '.implode(', ', array_keys($irreversible)),
        );

        File::delete($path);
    }
}
