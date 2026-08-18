<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * WWHN-290 closure evidence guard.
 *
 * Revision 1 of this guard asserted that hand-copied SHA strings were *present* in the register.
 * That let a pin rot silently: WWHN-328 moved the OpenAPI blob on the shared m1 branch, and the
 * register kept advertising a blob SHA that no longer resolved, while this test stayed green.
 *
 * This revision guards the invariants WWHN-290 can actually keep:
 *  - every pin lives once in WWHN-290_PINS.json and every document quoting it must agree;
 *  - the approved contract target is asserted against the live file, not a frozen blob;
 *  - the package never self-signs a QA1 gate.
 */
class Wwhn290ClosureEvidenceTest extends TestCase
{
    private const EVIDENCE_DIR = 'docs/evidence/wwhn-290';

    private function pins(): array
    {
        $path = base_path(self::EVIDENCE_DIR.'/WWHN-290_PINS.json');

        $this->assertFileExists($path, 'WWHN-290 pins file is the single source of truth and must exist.');

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function evidence(string $file): string
    {
        $path = base_path(self::EVIDENCE_DIR.'/'.$file);

        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_evidence_set_is_complete(): void
    {
        foreach ([
            'WWHN-290_PINS.json',
            'WWHN-290_DEFECT_CLOSURE_REGISTER.md',
            'WWHN-290_BRANCH_CONTRACT_SUPERSESSION.md',
            'WWHN-290_DATA_IMPACT_INDEPENDENT_VERIFICATION.md',
            'WWHN-290_QA1_REGRESSION_HANDOFF.md',
        ] as $file) {
            $path = base_path(self::EVIDENCE_DIR.'/'.$file);
            $this->assertFileExists($path, "Missing WWHN-290 evidence artifact: {$file}");
            $this->assertNotEmpty(trim(file_get_contents($path)), "Empty WWHN-290 evidence artifact: {$file}");
        }

        $this->assertFileExists(
            base_path('scripts/verify-wwhn290-closure.sh'),
            'The independent closure verifier must ship with the evidence.'
        );
    }

    public function test_pins_file_declares_every_required_pin(): void
    {
        $pins = $this->pins();

        $this->assertSame('WWHN-290', $pins['jira'] ?? null);
        $this->assertSame('m1', $pins['canonical_source']['be_branch'] ?? null);
        $this->assertSame('m1', $pins['canonical_source']['fe_branch'] ?? null);
        $this->assertSame('CONSUMER_EVIDENCE_ONLY', $pins['canonical_source']['fe_role'] ?? null);
        $this->assertContains('m1ee', $pins['canonical_source']['nonexistent_refs_seen_in_review'] ?? []);

        $this->assertSame('m1-be-2', $pins['superseded_branch']['name'] ?? null);
        $this->assertSame('STRICT_ANCESTOR', $pins['superseded_branch']['relation_to_m1'] ?? null);
        $this->assertSame(0, $pins['superseded_branch']['m1_commits_behind'] ?? null);
        $this->assertFalse($pins['superseded_branch']['port_or_merge_required'] ?? true);

        $this->assertSame(0, $pins['change_range']['runtime_paths_touched'] ?? null);
        $this->assertSame(0, $pins['change_range']['contract_file_touched'] ?? null);
        $this->assertNotEmpty($pins['change_range']['guarded_paths'] ?? []);

        $this->assertSame('2.0.0', $pins['contract']['approved_openapi_version'] ?? null);
        $this->assertSame('3.0.0', $pins['contract']['openapi_spec_version'] ?? null);
        $this->assertSame('/api/v1', $pins['contract']['namespace'] ?? null);

        $this->assertFalse($pins['defect_input']['speculative_fix_allowed'] ?? true);

        // Exact-SHA evidence means full 40-character git object names, never abbreviations.
        $this->assertSame(40, strlen((string) ($pins['change_range']['base'] ?? '')));
        $this->assertSame(40, strlen((string) ($pins['change_range']['final'] ?? '')));
        $this->assertSame(40, strlen((string) ($pins['superseded_branch']['head'] ?? '')));
        $this->assertSame(40, strlen((string) ($pins['fe_consumer']['revision_reviewed'] ?? '')));

        // m1 is shared, so the ticket's commits are listed explicitly rather than inferred
        // from a contiguous range that other tickets land inside.
        $commits = $pins['change_range']['commits'] ?? [];
        $this->assertNotEmpty($commits, 'The attributed WWHN-290 commit list must not be empty.');
        foreach ($commits as $commit) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                (string) $commit,
                "Attributed commit must be a full 40-character SHA, got: {$commit}"
            );
        }
        $this->assertContains($pins['change_range']['final'], $commits, 'The final candidate must appear in the attributed commit list.');
    }

    /**
     * The anti-drift guard: a pin is written once, in the pins file, and every markdown
     * artifact that quotes it must quote the same value.
     */
    public function test_documents_quote_the_same_pins_as_the_pins_file(): void
    {
        $pins = $this->pins();

        $register = $this->evidence('WWHN-290_DEFECT_CLOSURE_REGISTER.md');
        $supersession = $this->evidence('WWHN-290_BRANCH_CONTRACT_SUPERSESSION.md');
        $dataImpact = $this->evidence('WWHN-290_DATA_IMPACT_INDEPENDENT_VERIFICATION.md');

        $base = $pins['change_range']['base'];
        $final = $pins['change_range']['final'];
        $supersededHead = $pins['superseded_branch']['head'];
        $feRevision = $pins['fe_consumer']['revision_reviewed'];

        foreach ([
            ['WWHN-290_DEFECT_CLOSURE_REGISTER.md', $register, $base, 'change-range base'],
            ['WWHN-290_DEFECT_CLOSURE_REGISTER.md', $register, $final, 'change-range final'],
            ['WWHN-290_DEFECT_CLOSURE_REGISTER.md', $register, $feRevision, 'FE consumer revision'],
            ['WWHN-290_BRANCH_CONTRACT_SUPERSESSION.md', $supersession, $supersededHead, 'm1-be-2 HEAD'],
            ['WWHN-290_BRANCH_CONTRACT_SUPERSESSION.md', $supersession, $feRevision, 'FE consumer revision'],
            ['WWHN-290_DATA_IMPACT_INDEPENDENT_VERIFICATION.md', $dataImpact, $base, 'change-range base'],
            ['WWHN-290_DATA_IMPACT_INDEPENDENT_VERIFICATION.md', $dataImpact, $final, 'change-range final'],
        ] as [$file, $content, $value, $label]) {
            $this->assertStringContainsString(
                $value,
                $content,
                "WWHN-290 pin drift: {$file} does not quote the pinned {$label} ({$value}) from WWHN-290_PINS.json."
            );
        }

        // A superseded FE revision must never be presented as the current consumer baseline.
        foreach ($pins['fe_consumer']['superseded_revisions_quoted_in_earlier_artifacts'] ?? [] as $stale) {
            $this->assertStringNotContainsString(
                $stale,
                $register,
                "The register still quotes the superseded FE revision {$stale}."
            );
        }
    }

    public function test_all_review_items_are_dispositioned(): void
    {
        $register = $this->evidence('WWHN-290_DEFECT_CLOSURE_REGISTER.md');

        foreach ([
            'DEV290-P1-01',
            'DEV290-P1-02',
            'DEV290-P1-03',
            'DEV290-P1-04',
            'DEV290-P1-05',
            'DEV290-P2-01',
            'NO-VERIFIED-UAT-DEFECT-INPUT',
            'FAIL-CLOSED / NEEDS OWNER DISPOSITION',
            'NO CODE CHANGE / N/A',
            'QA1 must execute/verify WWHN-291',
            'm1` only',
        ] as $marker) {
            $this->assertStringContainsString($marker, $register, "Missing WWHN-290 closure marker: {$marker}");
        }
    }

    public function test_branch_supersession_is_recorded_with_containment_proof(): void
    {
        $supersession = $this->evidence('WWHN-290_BRANCH_CONTRACT_SUPERSESSION.md');

        foreach ([
            'git merge-base --is-ancestor origin/m1-be-2 m1',
            'strict ancestor',
            'SUPERSEDED BY CONTAINMENT',
            'consumer evidence only',
            'm1ee',
        ] as $marker) {
            $this->assertStringContainsString($marker, $supersession, "Missing supersession marker: {$marker}");
        }
    }

    public function test_data_impact_records_a_measurable_no_change_claim(): void
    {
        $dataImpact = $this->evidence('WWHN-290_DATA_IMPACT_INDEPENDENT_VERIFICATION.md');

        foreach ([
            'scripts/verify-wwhn290-closure.sh',
            'Automatic invalidation clause',
            'Rollback and reconciliation',
        ] as $marker) {
            $this->assertStringContainsString($marker, $dataImpact, "Missing data-impact marker: {$marker}");
        }
    }

    public function test_openapi_target_is_exactly_version_2_0_0(): void
    {
        $pins = $this->pins();
        $path = base_path('storage/api-docs/api-docs.json');

        $this->assertFileExists($path);

        $document = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($pins['contract']['openapi_spec_version'], $document['openapi'] ?? null);
        $this->assertSame($pins['contract']['approved_openapi_version'], $document['info']['version'] ?? null);
        $this->assertNotEmpty($document['paths'] ?? []);
    }

    public function test_closure_package_does_not_claim_independent_qa_pass(): void
    {
        $register = $this->evidence('WWHN-290_DEFECT_CLOSURE_REGISTER.md');
        $qaHandoff = $this->evidence('WWHN-290_QA1_REGRESSION_HANDOFF.md');

        $this->assertStringContainsString('does **not** claim QA1 PASS', $register);
        $this->assertStringNotContainsString('QA1 PASS — VERIFIED', $register);

        $this->assertStringContainsString('DEV does not sign this gate.', $qaHandoff);
        $this->assertStringContainsString('not a QA1 sign-off', $qaHandoff);
    }
}
