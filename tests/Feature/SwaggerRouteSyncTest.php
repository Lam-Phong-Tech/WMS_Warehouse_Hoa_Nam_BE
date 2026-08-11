<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SwaggerRouteSyncTest extends TestCase
{
    public function test_generated_swagger_matches_real_api_v1_routes(): void
    {
        Artisan::call('l5-swagger:generate');

        $swagger = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);

        $routeOperations = $this->routeOperations();
        $swaggerOperations = $this->swaggerOperations($swagger);

        sort($routeOperations);
        sort($swaggerOperations);

        $this->assertSame($routeOperations, $swaggerOperations);
    }

    public function test_generated_swagger_summaries_are_human_readable(): void
    {
        Artisan::call('l5-swagger:generate');

        $swagger = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $badSummaries = [];

        foreach ($swagger['paths'] ?? [] as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'] as $method) {
                if (! isset($pathItem[$method]['summary'])) {
                    continue;
                }

                $summary = (string) $pathItem[$method]['summary'];
                if (preg_match('/^[A-Z]+ \/api\/v1\/.* - /', $summary) || str_contains($summary, 'Http Controllers')) {
                    $badSummaries[] = strtoupper($method).' '.$path.' => '.$summary;
                }
            }
        }

        $this->assertSame([], $badSummaries);
    }

    /**
     * @return array<int, string>
     */
    private function routeOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $operations[] = strtoupper($method).' /'.$route->uri();
            }
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $swagger
     * @return array<int, string>
     */
    private function swaggerOperations(array $swagger): array
    {
        $operations = [];
        $methods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

        foreach ($swagger['paths'] ?? [] as $path => $pathItem) {
            foreach ($methods as $method) {
                if (array_key_exists($method, $pathItem)) {
                    $operations[] = strtoupper($method).' '.$path;
                }
            }
        }

        return $operations;
    }
}
