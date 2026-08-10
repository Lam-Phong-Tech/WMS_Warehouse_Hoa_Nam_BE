<?php

namespace App\Swagger\Processors;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Undefined;

class RouteSyncProcessor
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

    private const ERROR_RESPONSES = [
        400 => 'Malformed request',
        401 => 'Unauthenticated',
        403 => 'Forbidden',
        404 => 'Resource not found or outside scope',
        409 => 'State, business, idempotency or concurrency conflict',
        412 => 'If-Match precondition failed',
        422 => 'Validation or business-rule error',
        428 => 'Required If-Match precondition missing',
        500 => 'Unexpected server error; transaction rolled back',
    ];

    public function __invoke(Analysis $analysis): void
    {
        $openApi = $analysis->openapi;
        if ($openApi === null) {
            return;
        }

        $routes = $this->apiRoutes();
        $paths = [];

        foreach (Undefined::isDefault($openApi->paths) ? [] : $openApi->paths as $pathItem) {
            if (Undefined::isDefault($pathItem->path)) {
                continue;
            }

            $path = (string) $pathItem->path;
            foreach (self::METHODS as $method) {
                if (Undefined::isDefault($pathItem->{$method})) {
                    continue;
                }

                if (! isset($routes[$this->routeKey($method, $path)])) {
                    $pathItem->{$method} = Undefined::UNDEFINED;
                }
            }

            if ($this->hasOperations($pathItem)) {
                $paths[$path] = $pathItem;
            }
        }

        foreach ($routes as $route) {
            $path = $route['path'];
            $method = $route['method'];

            if (! isset($paths[$path])) {
                $paths[$path] = new OA\PathItem([
                    'path' => $path,
                    '_context' => $this->context(),
                ]);
            }

            if (Undefined::isDefault($paths[$path]->{$method})) {
                $paths[$path]->{$method} = $this->operation($route);
            }
        }

        ksort($paths);
        $openApi->paths = array_values($paths);
    }

    /**
     * @return array<string, array{method: string, path: string, uri: string, action: string, middleware: array<int, string>}>
     */
    private function apiRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $method = strtolower($method);
                if ($method === 'head' || ! in_array($method, self::METHODS, true)) {
                    continue;
                }

                $path = '/'.$route->uri();
                $routes[$this->routeKey($method, $path)] = [
                    'method' => $method,
                    'path' => $path,
                    'uri' => $route->uri(),
                    'action' => $this->routeAction($route),
                    'middleware' => $route->gatherMiddleware(),
                ];
            }
        }

        ksort($routes);

        return $routes;
    }

    /**
     * @param array{method: string, path: string, uri: string, action: string, middleware: array<int, string>} $route
     */
    private function operation(array $route): OA\Operation
    {
        $class = match ($route['method']) {
            'get' => OA\Get::class,
            'post' => OA\Post::class,
            'put' => OA\Put::class,
            'patch' => OA\Patch::class,
            'delete' => OA\Delete::class,
            'options' => OA\Options::class,
            'head' => OA\Head::class,
            'trace' => OA\Trace::class,
        };

        $responses = [
            new OA\Response([
                'response' => 200,
                'description' => 'OK',
                '_context' => $this->context(),
            ]),
        ];
        foreach (self::ERROR_RESPONSES as $status => $description) {
            $responses[] = new OA\Response([
                'response' => $status,
                'description' => $description,
                '_context' => $this->context(),
            ]);
        }

        return new $class([
            'path' => $route['path'],
            'operationId' => $this->operationId($route),
            'summary' => $this->summary($route),
            'tags' => [$this->tag($route['uri'])],
            'security' => $this->requiresAuth($route['middleware']) ? [['bearerAuth' => []]] : [],
            'parameters' => $this->pathParameters($route['path']),
            'responses' => $responses,
            '_context' => $this->context(),
        ]);
    }

    /** @return array<int, OA\Parameter> */
    private function pathParameters(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        return array_map(fn (string $name): OA\Parameter => new OA\Parameter([
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'schema' => new OA\Schema([
                'type' => 'string',
                '_context' => $this->context(),
            ]),
            '_context' => $this->context(),
        ]), $matches[1] ?? []);
    }

    private function hasOperations(OA\PathItem $pathItem): bool
    {
        foreach (self::METHODS as $method) {
            if (! Undefined::isDefault($pathItem->{$method})) {
                return true;
            }
        }

        return false;
    }

    private function routeAction(LaravelRoute $route): string
    {
        $action = $route->getActionName();

        return $action === 'Closure' ? $route->uri() : $action;
    }

    /** @param array{method: string, uri: string, action: string} $route */
    private function operationId(array $route): string
    {
        $base = strtolower($route['method'].'_'.$route['uri']);
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? $base;

        return trim($base, '_');
    }

    /** @param array{method: string, uri: string, action: string} $route */
    private function summary(array $route): string
    {
        $action = str_replace(['\\', '@'], [' ', ' '], $route['action']);
        $action = preg_replace('/\s+/', ' ', $action) ?? $action;

        return strtoupper($route['method']).' /'.$route['uri'].' - '.$action;
    }

    private function tag(string $uri): string
    {
        $parts = explode('/', $uri);
        $resource = $parts[2] ?? 'platform';

        return match ($resource) {
            'auth' => '01. Authentication',
            'users' => '02. Users',
            'roles' => '03. Roles',
            'permissions' => '04. Permissions',
            'products', 'skus', 'brands', 'product-categories', 'product-models', 'power-sources', 'package-variants' => '05. Product',
            'warehouses' => '06. Warehouse',
            'inventory', 'stock-movements', 'document-status-history', 'scan' => '07. Inventory',
            'inbound-documents' => '09. Stock In (Inbound)',
            'outbound-documents', 'outbound-request-imports' => '10. Stock Out (Outbound)',
            'reports' => '12. Dashboard / Report',
            'audit-logs' => '15. Audit Log',
            'agencies' => '16. Agency',
            'defect-catalogs' => '17. Defect Catalog',
            'containers', 'item-instances', 'physical-codes', 'import-batches' => '18. Physical Inventory',
            'label-snapshots', 'printer-configs' => '19. Label / Printer',
            'warranty-cases', 'component-issue-documents' => '20. Warranty',
            'pv' => '21. Public Catalog',
            default => '00. Platform',
        };
    }

    /** @param array<int, string> $middleware */
    private function requiresAuth(array $middleware): bool
    {
        return collect($middleware)->contains(fn (string $item): bool => str_starts_with($item, 'auth:'));
    }

    private function routeKey(string $method, string $path): string
    {
        return strtolower($method).' '.$path;
    }

    private function context(): Context
    {
        return new Context(['generated' => true]);
    }
}
