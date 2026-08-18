<?php

namespace Tests\Feature;

use Tests\TestCase;

class Wwhn290ClosureEvidenceTest extends TestCase
{
    public function test_closure_register_pins_all_review_items_and_fail_closed_input(): void
    {
        $path = base_path('docs/evidence/wwhn-290/WWHN-290_DEFECT_CLOSURE_REGISTER.md');

        $this->assertFileExists($path);

        $evidence = file_get_contents($path);

        foreach ([
            'DEV290-P1-01',
            'DEV290-P1-02',
            'DEV290-P1-03',
            'DEV290-P1-04',
            'DEV290-P1-05',
            'NO-VERIFIED-UAT-DEFECT-INPUT',
            'FAIL-CLOSED / NEEDS OWNER DISPOSITION',
            'FE1 confirms consumer compatibility once',
            'QA1 must execute/verify WWHN-291',
            'm1` only',
            '2.0.0',
            'c262a0d3c1525807e50ec88e436d8b5459e8ac0d',
        ] as $marker) {
            $this->assertStringContainsString($marker, $evidence, "Missing WWHN-290 closure marker: {$marker}");
        }
    }

    public function test_openapi_target_is_exactly_version_2_0_0(): void
    {
        $path = base_path('storage/api-docs/api-docs.json');

        $this->assertFileExists($path);

        $document = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.0.0', $document['openapi'] ?? null);
        $this->assertSame('2.0.0', $document['info']['version'] ?? null);
        $this->assertNotEmpty($document['paths'] ?? []);
    }

    public function test_closure_package_does_not_claim_independent_qa_pass(): void
    {
        $evidence = file_get_contents(base_path('docs/evidence/wwhn-290/WWHN-290_DEFECT_CLOSURE_REGISTER.md'));

        $this->assertStringContainsString('does **not** claim QA1 PASS', $evidence);
        $this->assertStringNotContainsString('QA1 PASS — VERIFIED', $evidence);
    }
}
