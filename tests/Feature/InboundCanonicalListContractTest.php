<?php

namespace Tests\Feature;

use Tests\TestCase;

class InboundCanonicalListContractTest extends TestCase
{
    public function test_inbound_list_uses_server_keyword_search_and_legacy_transitions_are_absent(): void
    {
        $concern = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/Concerns/InteractsWithStockDocuments.php'));
        $routes = (string) file_get_contents(base_path('Modules/Warehouse/routes/api.php'));
        $openApi = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php'));

        $this->assertStringContainsString("['doc_no', 'source_name', 'note']", $concern);
        $this->assertStringContainsString("name: 'keyword'", $openApi);
        $this->assertStringContainsString("name: 'page'", $openApi);
        $this->assertStringContainsString("name: 'per_page'", $openApi);

        foreach (['submit', 'approve', 'reject', 'closeRemainder'] as $legacyAction) {
            $this->assertStringNotContainsString("public function {$legacyAction}(", $concern);
        }

        foreach (['submit', 'approve', 'reject', 'close-remainder'] as $legacyRoute) {
            $this->assertStringNotContainsString("inbound-documents/{id}/{$legacyRoute}", $routes);
            $this->assertStringNotContainsString("/api/v1/inbound-documents/{id}/{$legacyRoute}", $openApi);
        }
    }
}
