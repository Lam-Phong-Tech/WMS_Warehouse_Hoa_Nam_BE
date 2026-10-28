<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\P06CatalogController;
use Tests\TestCase;

final class P06CatalogOpenApiTest extends TestCase
{
    public function test_generated_openapi_matches_nine_existing_read_routes_and_card_revision(): void
    {
        $folder = storage_path('framework/testing/p06-card-master-openapi');
        config(['l5-swagger.defaults.paths.docs' => $folder]);
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));
        $doc = json_decode(file_get_contents($folder.'/api-docs.json'), true, flags: JSON_THROW_ON_ERROR);
        $paths = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/mini-app/catalog/')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
                $path = '/'.$route->uri();
                $paths[] = $path;
                $this->assertArrayHasKey('get', $doc['paths'][$path]);
                foreach (['post', 'put', 'patch', 'delete'] as $write) {
                    $this->assertArrayNotHasKey($write, $doc['paths'][$path]);
                }
                $this->assertArrayNotHasKey('requestBody', $doc['paths'][$path]['get']);
                $this->assertContains('#/components/parameters/P06CardRevision', array_column($doc['paths'][$path]['get']['parameters'], '$ref'));
                $this->assertContains('auth:api', $route->gatherMiddleware());
            }
        }
        $this->assertCount(9, $paths);
        $schema = $doc['components']['schemas']['P06CardMetadataR1'];
        foreach (['category_name', 'brand_name', 'description'] as $field) {
            $this->assertSame('string', $schema['properties'][$field]['type']);
            $this->assertTrue($schema['properties'][$field]['nullable']);
        }
        $this->assertSame([P06CatalogController::CARD_METADATA_REVISION, P06CatalogController::DESCRIPTION_METADATA_REVISION], $doc['components']['schemas']['P06MetadataRevision']['properties']['card_metadata_revision']['enum']);
        $contract = json_decode(file_get_contents(base_path('docs/contracts/p06-card-master-r1.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(P06CatalogController::CONTRACT, $contract['contract']);
        $this->assertSame(P06CatalogController::CARD_METADATA_REVISION, $contract['revision']);
        $this->assertFalse($contract['migration_required']);
        $this->assertSame($contract['additive_card_fields']['metadata_status']['label_status_values'], $schema['properties']['metadata_status']['properties']['brand_name']['enum']);
        $r2 = json_decode(file_get_contents(base_path('docs/contracts/p06-card-master-r2.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(P06CatalogController::DESCRIPTION_METADATA_REVISION, $r2['revision']);
        $this->assertSame($r2['description']['status_values'], $doc['components']['schemas']['P06CardMetadataR2']['properties']['metadata_status']['properties']['description']['enum']);
        $this->assertSame(P06CatalogController::METADATA_REVISION_HEADER, $doc['components']['parameters']['P06CardRevision']['name']);
        $this->assertFalse($r2['migration_required']);
    }
}
