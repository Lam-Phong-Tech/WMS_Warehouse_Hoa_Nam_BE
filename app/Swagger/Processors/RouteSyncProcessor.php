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
        400 => 'Yêu cầu không đúng định dạng',
        401 => 'Chưa xác thực',
        403 => 'Không có quyền truy cập',
        404 => 'Không tìm thấy tài nguyên hoặc nằm ngoài phạm vi dữ liệu',
        409 => 'Xung đột trạng thái, nghiệp vụ, idempotency hoặc thao tác đồng thời',
        412 => 'Điều kiện If-Match không đạt',
        422 => 'Dữ liệu không hợp lệ hoặc vi phạm quy tắc nghiệp vụ',
        428 => 'Thiếu điều kiện If-Match bắt buộc',
        500 => 'Lỗi hệ thống ngoài dự kiến; giao dịch đã được rollback',
    ];

    private const TAGS = [
        '00. Platform' => 'Các endpoint kiểm tra sức khỏe hệ thống và cấp nền tảng.',
        '01. Authentication' => 'Xác thực JWT và ngữ cảnh người dùng hiện tại.',
        '02. Users' => 'Quản lý người dùng IAM.',
        '03. Roles' => 'Quản lý vai trò IAM và phân quyền theo vai trò.',
        '04. Permissions' => 'Danh mục quyền IAM.',
        '05. Product' => 'Các endpoint SKU và sản phẩm.',
        '06. Warehouse' => 'Thông tin master một kho Hoa Nam.',
        '07. Inventory' => 'Số dư tồn kho, truy vết biến động và đối soát.',
        '08. Catalog Master' => 'Dữ liệu master tham chiếu của sản phẩm.',
        '09. Stock In (Inbound)' => 'Luồng nhập kho đầy đủ đến bước ghi nhận.',
        '10. Stock Out (Outbound)' => 'Luồng xuất kho gồm import, khớp dữ liệu và ghi nhận đầy đủ.',
        '11. Scan' => 'Các endpoint phân loại mã quét.',
        '12. Dashboard / Report' => 'Báo cáo tồn kho và dữ liệu dashboard.',
        '13. Warranty' => 'Hồ sơ bảo hành và sự kiện bảo hành.',
        '14. Component Issue' => 'Chứng từ xuất linh kiện chỉ dùng cho bảo hành.',
        '15. Audit Log' => 'Bằng chứng audit dạng chỉ ghi thêm.',
        '16. Agency' => 'Các endpoint master bên nhận.',
        '17. Defect Catalog' => 'Danh mục lỗi bảo hành.',
        '18. Physical Inventory' => 'Đối tượng hàng hóa, mã vật lý, lô import và container.',
        '19. Label / Printer' => 'Ảnh chụp nhãn, in nhãn và bằng chứng in lại.',
        '20. System Operation' => 'Dự phòng cho các endpoint quản trị vận hành hệ thống.',
        '21. Public Catalog' => 'Các endpoint danh mục và gợi ý PV chỉ đọc.',
        '24. Putaway' => 'Xếp BOX linh kiện đã nhận vào TRAY; toàn bộ thao tác stock-neutral.',
    ];

    private const ROUTE_SUMMARIES = [
        'get api/v1/pv/catalog' => 'Danh mục PV công khai',
        'get api/v1/pv/industries' => 'Danh sách ngành hàng PV',
        'get api/v1/pv/tags' => 'Danh sách tag PV',
        'get api/v1/pv/recommendations' => 'Gợi ý PV theo tiêu chí lọc',
        'post api/v1/inbound-documents/{id}/scan' => 'Quét hàng cho phiếu nhập',
        'post api/v1/inbound-documents/{id}/post-receipt' => 'Ghi nhận nhập kho cho phiếu nhập',
        'post api/v1/inbound-documents/{id}/reverse' => 'Hoàn tác nhập kho cho phiếu nhập',
        'get api/v1/putaway/tasks' => 'Danh sách BOX chờ xếp khay',
        'post api/v1/putaway/tasks' => 'Tạo tác vụ xếp BOX vào TRAY',
        'post api/v1/putaway/tasks/{id}/confirm' => 'Xác nhận xếp BOX vào TRAY',
        'post api/v1/putaway/tasks/{id}/cancel' => 'Huỷ tác vụ xếp BOX vào TRAY',
        'get api/v1/putaway/history' => 'Lịch sử xếp BOX vào TRAY',
        'post api/v1/outbound-documents/{id}/scan' => 'Quét hàng cho phiếu xuất',
        'post api/v1/outbound-documents/{id}/post-issue' => 'Ghi nhận xuất kho cho phiếu xuất',
        'post api/v1/outbound-documents/{id}/reverse' => 'Hoàn tác xuất kho cho phiếu xuất',
        'get api/v1/inventory/balances' => 'Tồn kho hiện tại theo kho và SKU',
        'get api/v1/stock-movements/{id}/trace-source' => 'Truy vết nguồn phát sinh giao dịch kho',
        'get api/v1/reports/inventory-xnt' => 'Báo cáo nhập xuất tồn',
        'get api/v1/reports/inventory-xnt/export-excel' => 'Xuất Excel báo cáo nhập xuất tồn',
        'get api/v1/reports/dashboard-quick' => 'Số liệu nhanh cho dashboard',
        'get api/v1/reports/inventory-reconciliation' => 'Đối soát tồn kho với sổ giao dịch',
        'post api/v1/containers/{id}/items' => 'Thêm hàng vào container',
        'delete api/v1/containers/{id}/items/{containerItemId}' => 'Xoá hàng khỏi container',
        'get api/v1/item-instances/{id}/trace' => 'Truy vết vòng đời item instance',
        'post api/v1/brands' => 'Tạo thương hiệu mới',
        'post api/v1/product-categories' => 'Tạo danh mục sản phẩm mới',
        'post api/v1/power-sources' => 'Tạo nguồn điện mới',
        'post api/v1/product-models' => 'Tạo model sản phẩm mới',
        'post api/v1/package-variants' => 'Tạo quy cách đóng gói mới',
        'post api/v1/outbound-request-imports/{id}/consume' => 'Tiêu thụ dữ liệu import yêu cầu xuất',
        'post api/v1/label-snapshots/{id}/print' => 'In nhãn từ snapshot',
        'post api/v1/label-snapshots/{id}/reprint' => 'In lại nhãn từ snapshot',
        'post api/v1/component-issue-documents/{id}/scan' => 'Quét linh kiện cho phiếu xuất bảo hành',
        'post api/v1/component-issue-documents/{id}/cancel' => 'Huỷ phiếu xuất linh kiện bảo hành',
        'post api/v1/component-issue-documents/{id}/post' => 'Ghi nhận phiếu xuất linh kiện bảo hành',
        'get api/v1/warranty-cases/{id}/events' => 'Lịch sử sự kiện của hồ sơ bảo hành',
        'post api/v1/warranty-cases/{id}/temp-only' => 'Đánh dấu hồ sơ bảo hành chỉ xử lý tạm',
        'post api/v1/scan/classify' => 'Phân loại mã quét',
        'get api/v1/skus/import-template' => 'Tải file mẫu import SKU',
        'post api/v1/skus/import-excel' => 'Import SKU từ Excel',
        'post api/v1/skus/{id}/physical-codes' => 'Gán mã vật lý cho SKU',
        'delete api/v1/skus/{id}/physical-codes/{physicalCodeId}' => 'Thu hồi mã vật lý khỏi SKU',
    ];

    private const RESOURCE_LABELS = [
        'agencies' => 'đại lý',
        'brands' => 'thương hiệu',
        'component-issue-documents' => 'phiếu xuất linh kiện bảo hành',
        'containers' => 'container',
        'defect-catalogs' => 'danh mục lỗi bảo hành',
        'document-status-history' => 'lịch sử trạng thái chứng từ',
        'import-batches' => 'lô import',
        'inbound-documents' => 'phiếu nhập',
        'item-instances' => 'item instance',
        'label-snapshots' => 'snapshot nhãn',
        'outbound-documents' => 'phiếu xuất',
        'outbound-request-imports' => 'import yêu cầu xuất',
        'package-variants' => 'quy cách đóng gói',
        'physical-codes' => 'mã vật lý',
        'putaway' => 'tác vụ putaway',
        'power-sources' => 'nguồn điện',
        'printer-configs' => 'cấu hình máy in',
        'product-categories' => 'danh mục sản phẩm',
        'product-masters' => 'product master',
        'product-models' => 'model sản phẩm',
        'products' => 'sản phẩm/SKU',
        'skus' => 'SKU',
        'stock-movements' => 'giao dịch kho',
        'warranty-cases' => 'hồ sơ bảo hành',
    ];

    private const COLLECTION_NOUNS = [
        'agencies' => 'đại lý',
        'brands' => 'thương hiệu',
        'component-issue-documents' => 'phiếu xuất linh kiện bảo hành',
        'containers' => 'container',
        'defect-catalogs' => 'lỗi bảo hành',
        'document-status-history' => 'lịch sử trạng thái chứng từ',
        'import-batches' => 'lô import',
        'inbound-documents' => 'phiếu nhập',
        'item-instances' => 'item instance',
        'label-snapshots' => 'snapshot nhãn',
        'outbound-documents' => 'phiếu xuất',
        'outbound-request-imports' => 'import yêu cầu xuất',
        'package-variants' => 'quy cách đóng gói',
        'physical-codes' => 'mã vật lý',
        'putaway' => 'tác vụ putaway',
        'power-sources' => 'nguồn điện',
        'printer-configs' => 'cấu hình máy in',
        'product-categories' => 'danh mục sản phẩm',
        'product-masters' => 'product master',
        'product-models' => 'model sản phẩm',
        'products' => 'sản phẩm/SKU',
        'skus' => 'SKU',
        'stock-movements' => 'giao dịch kho',
        'warranty-cases' => 'hồ sơ bảo hành',
    ];

    private const COLLECTION_QUERY_PARAMETERS = [
        'agencies' => ['page', 'per_page', 'keyword', 'status'],
        'brands' => ['page', 'per_page', 'keyword', 'status'],
        'component-issue-documents' => ['page', 'per_page', 'keyword', 'status'],
        'containers' => ['page', 'per_page', 'keyword', 'container_type', 'status', 'zone_code'],
        'defect-catalogs' => ['page', 'per_page', 'keyword', 'status'],
        'document-status-history' => ['page', 'per_page', 'keyword', 'status', 'stock_document_id'],
        'import-batches' => ['page', 'per_page', 'keyword', 'status'],
        'inbound-documents' => ['page', 'per_page', 'keyword', 'status'],
        'item-instances' => ['page', 'per_page', 'keyword', 'sku_id', 'item_status', 'item_condition', 'container_id'],
        'label-snapshots' => ['page', 'per_page', 'keyword', 'status'],
        'outbound-documents' => ['page', 'per_page', 'keyword', 'status'],
        'package-variants' => ['page', 'per_page', 'keyword', 'status'],
        'physical-codes' => ['page', 'per_page', 'keyword', 'code_type', 'carrier_type', 'status'],
        'putaway' => ['search'],
        'power-sources' => ['page', 'per_page', 'keyword', 'status'],
        'printer-configs' => ['page', 'per_page', 'keyword', 'status'],
        'product-categories' => ['page', 'per_page', 'keyword', 'status'],
        'product-masters' => ['page', 'per_page', 'keyword', 'master_type', 'status'],
        'product-models' => ['page', 'per_page', 'keyword', 'status'],
        'products' => ['page', 'per_page', 'keyword', 'status', 'sku_type'],
        'skus' => ['page', 'per_page', 'keyword', 'status', 'sku_type'],
        'stock-movements' => ['page', 'per_page', 'keyword', 'warehouse_id', 'product_id', 'movement_type'],
        'warranty-cases' => ['page', 'per_page', 'keyword', 'status'],
    ];

    private const PARAMETER_SCHEMAS = [
        'page' => ['type' => 'integer', 'default' => 1],
        'per_page' => ['type' => 'integer', 'default' => 15],
        'keyword' => ['type' => 'string'],
        'status' => ['type' => 'string'],
        'master_type' => ['type' => 'string', 'enum' => ['BRAND', 'CATEGORY', 'MODEL', 'PACKAGE', 'POWER']],
        'sku_type' => ['type' => 'string', 'enum' => ['PRODUCT', 'COMPONENT']],
        'container_type' => ['type' => 'string', 'enum' => ['CARTON', 'BOX', 'TRAY']],
        'warehouse_id' => ['type' => 'string', 'format' => 'uuid'],
        'product_id' => ['type' => 'string', 'format' => 'uuid'],
        'stock_document_id' => ['type' => 'string', 'format' => 'uuid'],
        'sku_id' => ['type' => 'string', 'format' => 'uuid'],
        'container_id' => ['type' => 'string', 'format' => 'uuid'],
        'movement_type' => ['type' => 'string'],
        'code_type' => ['type' => 'string'],
        'carrier_type' => ['type' => 'string'],
        'zone_code' => ['type' => 'string'],
        'item_status' => ['type' => 'string'],
        'item_condition' => ['type' => 'string'],
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
            } else {
                $this->hydrateOperation($paths[$path]->{$method}, $route);
            }
        }

        ksort($paths);
        $openApi->tags = $this->tags();
        $openApi->paths = array_values($paths);
    }

    /** @return array<int, OA\Tag> */
    private function tags(): array
    {
        $tags = [];

        foreach (self::TAGS as $name => $description) {
            $tags[] = new OA\Tag([
                'name' => $name,
                'description' => $description,
                '_context' => $this->context(),
            ]);
        }

        return $tags;
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
                'description' => 'Thành công',
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
            'parameters' => $this->routeParameters($route),
            'requestBody' => $this->requestBody($route['method']),
            'responses' => $responses,
            '_context' => $this->context(),
        ]);
    }

    /**
     * Keep handwritten OpenAPI details such as requestBody/query schemas, while
     * enforcing route-derived grouping/security and baseline error responses.
     *
     * @param array{method: string, path: string, uri: string, action: string, middleware: array<int, string>} $route
     */
    private function hydrateOperation(OA\Operation $operation, array $route): void
    {
        $operation->tags = [$this->tag($route['uri'])];
        $operation->security = $this->requiresAuth($route['middleware']) ? [['bearerAuth' => []]] : [];
        $operation->parameters = $this->mergeRouteParameters($operation->parameters, $route);
        if (Undefined::isDefault($operation->requestBody)) {
            $operation->requestBody = $this->requestBody($route['method']);
        }
        $operation->responses = $this->mergeBaselineResponses($operation->responses);
    }

    private function requestBody(string $method): OA\RequestBody|string
    {
        if (! in_array($method, ['post', 'put', 'patch'], true)) {
            return Undefined::UNDEFINED;
        }

        return new OA\RequestBody([
            'required' => false,
            'content' => [
                new OA\MediaType([
                    'mediaType' => 'application/json',
                    'schema' => new OA\Schema([
                        'type' => 'object',
                        'additionalProperties' => true,
                        'example' => new \stdClass(),
                        '_context' => $this->context(),
                    ]),
                    '_context' => $this->context(),
                ]),
            ],
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

    /**
     * @param array{method: string, path: string, uri: string, action: string, middleware: array<int, string>} $route
     * @return array<int, OA\Parameter>
     */
    private function routeParameters(array $route): array
    {
        return array_merge($this->pathParameters($route['path']), $this->collectionQueryParameters($route));
    }

    /**
     * @param array{method: string, uri: string} $route
     * @return array<int, OA\Parameter>
     */
    private function collectionQueryParameters(array $route): array
    {
        if ($route['method'] !== 'get' || str_contains($route['uri'], '{')) {
            return [];
        }

        $names = self::COLLECTION_QUERY_PARAMETERS[$this->resourceName($route['uri'])] ?? [];

        return array_map(fn (string $name): OA\Parameter => $this->queryParameter($name), $names);
    }

    private function queryParameter(string $name): OA\Parameter
    {
        return new OA\Parameter([
            'name' => $name,
            'in' => 'query',
            'schema' => new OA\Schema((self::PARAMETER_SCHEMAS[$name] ?? ['type' => 'string']) + [
                '_context' => $this->context(),
            ]),
            '_context' => $this->context(),
        ]);
    }

    /** @param mixed $parameters */
    private function mergeRouteParameters(mixed $parameters, array $route): array
    {
        $merged = Undefined::isDefault($parameters) ? [] : (array) $parameters;
        $existing = [];

        foreach ($merged as $parameter) {
            if ($parameter instanceof OA\Parameter && ! Undefined::isDefault($parameter->name) && ! Undefined::isDefault($parameter->in)) {
                $existing[$parameter->in.':'.$parameter->name] = true;
            }
        }

        foreach ($this->routeParameters($route) as $parameter) {
            if (! isset($existing[$parameter->in.':'.$parameter->name])) {
                $merged[] = $parameter;
            }
        }

        return $merged;
    }

    /** @param mixed $responses */
    private function mergeBaselineResponses(mixed $responses): array
    {
        $merged = Undefined::isDefault($responses) ? [] : (array) $responses;
        $existing = [];

        foreach ($merged as $response) {
            if ($response instanceof OA\Response && ! Undefined::isDefault($response->response)) {
                $existing[(string) $response->response] = true;
            }
        }

        foreach ([200 => 'Thành công'] + self::ERROR_RESPONSES as $status => $description) {
            if (! isset($existing[(string) $status])) {
                $merged[] = new OA\Response([
                    'response' => $status,
                    'description' => $description,
                    '_context' => $this->context(),
                ]);
            }
        }

        return $merged;
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
        $key = strtolower($route['method'].' '.$route['uri']);
        if (isset(self::ROUTE_SUMMARIES[$key])) {
            return self::ROUTE_SUMMARIES[$key];
        }

        $friendly = $this->resourceSummary($route['method'], $route['uri']);
        if ($friendly !== null) {
            return $friendly;
        }

        $action = str_replace(['\\', '@'], [' ', ' '], $route['action']);
        $action = preg_replace('/\s+/', ' ', $action) ?? $action;

        return strtoupper($route['method']).' /'.$route['uri'].' - '.$action;
    }

    private function resourceSummary(string $method, string $uri): ?string
    {
        $relativeUri = preg_replace('#^api/v1/#', '', $uri) ?? $uri;
        $parts = explode('/', $relativeUri);
        $resource = $parts[0] ?? '';

        if (! isset(self::RESOURCE_LABELS[$resource])) {
            return null;
        }

        $label = self::RESOURCE_LABELS[$resource];
        $collectionNoun = self::COLLECTION_NOUNS[$resource] ?? $label;
        $last = $parts[array_key_last($parts)] ?? '';
        $hasId = in_array('{id}', $parts, true);

        if ($last === 'cancel') {
            return 'Huỷ '.$label;
        }

        if ($last === 'enable') {
            return 'Kích hoạt '.$label;
        }

        if ($last === 'disable') {
            return 'Ngừng kích hoạt '.$label;
        }

        if ($method === 'get' && $hasId) {
            return 'Chi tiết '.$label;
        }

        return match ($method) {
            'get' => 'Danh sách '.$collectionNoun,
            'post' => 'Tạo '.$label.' mới',
            'patch', 'put' => 'Cập nhật '.$label,
            'delete' => 'Xoá '.$label,
            default => null,
        };
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
            'products', 'skus', 'product-masters' => '05. Product',
            'warehouses' => '06. Warehouse',
            'inventory', 'stock-movements', 'document-status-history' => '07. Inventory',
            'brands', 'product-categories', 'product-models', 'power-sources', 'package-variants' => '08. Catalog Master',
            'inbound-documents' => '09. Stock In (Inbound)',
            'outbound-documents', 'outbound-request-imports' => '10. Stock Out (Outbound)',
            'scan' => '11. Scan',
            'reports' => '12. Dashboard / Report',
            'warranty-cases' => '13. Warranty',
            'component-issue-documents' => '14. Component Issue',
            'audit-logs' => '15. Audit Log',
            'agencies' => '16. Agency',
            'defect-catalogs' => '17. Defect Catalog',
            'containers', 'item-instances', 'physical-codes', 'import-batches' => '18. Physical Inventory',
            'label-snapshots', 'printer-configs' => '19. Label / Printer',
            'pv' => '21. Public Catalog',
            'putaway' => '24. Putaway',
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

    private function resourceName(string $uri): string
    {
        $parts = explode('/', $uri);

        return $parts[2] ?? '';
    }

    private function context(): Context
    {
        return new Context(['generated' => true]);
    }
}
