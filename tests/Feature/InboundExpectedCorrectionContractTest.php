<?php

namespace Tests\Feature;

use Tests\TestCase;

class InboundExpectedCorrectionContractTest extends TestCase
{
    public function test_scanning_inbound_expected_correction_preserves_scan_evidence_contract(): void
    {
        $source = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php'));

        $this->assertStringContainsString("'lines.*.id' => ['nullable', 'uuid']", $source);
        $this->assertStringContainsString('updateScannedInboundLines', $source);
        $this->assertStringContainsString('PRESERVE_SCAN_EVIDENCE', $source);
        $this->assertStringContainsString('SCANNED_LINE_STRUCTURE_IMMUTABLE', $source);
        $this->assertStringContainsString('EXPECTED_QTY_BELOW_SCANNED', $source);
    }
}
