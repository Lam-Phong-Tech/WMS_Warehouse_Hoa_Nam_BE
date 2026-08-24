<?php

namespace Tests\Feature;

use Tests\TestCase;

final class DocumentMirrorRegressionTest extends TestCase
{
    public function test_current_baseline_schema_materializes_document_runtime_columns(): void
    {
        $migration = $this->source(
            'Modules/Warehouse/database/migrations/2026_08_10_000660_finalize_current_baseline_contract.php'
        );

        foreach ([
            "source_name",
            "outbound_request_id",
            "agency_id",
            "recipient_name",
            "recipient_address",
            "version",
            "outbound_request_line_id",
            "import_batch_id",
            "note",
        ] as $column) {
            $this->assertStringContainsString("'{$column}'", $migration, $column);
        }
    }

    public function test_receipt_writes_are_isolated_from_outbound_projection_guards(): void
    {
        $migration = $this->source(
            'Modules/Warehouse/database/migrations/2026_08_24_110000_scope_outbound_document_mirror_to_changed_issue_document.php'
        );

        $this->assertStringContainsString('FOR EACH ROW', $migration);
        $this->assertStringContainsString("NEW.doc_type IS DISTINCT FROM 'issue'", $migration);
        $this->assertStringContainsString('WHERE "id" = v_document_id', $migration);
        $this->assertStringContainsString('OLD.doc_type IS NOT DISTINCT FROM \'issue\'', $migration);
    }

    public function test_outbound_agency_guard_supports_both_runtime_and_erd_identifiers(): void
    {
        $migration = $this->source(
            'Modules/Warehouse/database/migrations/2026_08_24_110000_scope_outbound_document_mirror_to_changed_issue_document.php'
        );

        $this->assertStringContainsString('WHERE id = NEW.agency_id', $migration);
        $this->assertStringContainsString('OR agency_id = NEW.agency_id', $migration);
        $this->assertStringContainsString("agency_status IS DISTINCT FROM 'ACTIVE'", $migration);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
