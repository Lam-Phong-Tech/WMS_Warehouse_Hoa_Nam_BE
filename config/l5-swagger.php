<?php

use App\Http\Middleware\EnsureApiDocsEnabled;
use App\Swagger\Processors\RouteSyncProcessor;
use L5Swagger\CustomGeneratorInterface;
use L5Swagger\Generator;
use OA\Post;
use OpenApi\Processors\AugmentTags;
use OpenApi\scan;

return [
    'default' => 'default',
    'documentations' => [
        'default' => [
            'api' => [
                'title' => 'WMS_HoaNam API',
            ],

            'routes' => [
                /*
                 * Route for accessing api documentation interface
                 */
                'api' => 'api/documentation',
            ],
            'paths' => [
                /*
                 * Edit to include full URL in ui for assets
                 */
                'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),

                /*
                * Edit to set path where swagger ui assets should be stored
                */
                'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),

                /*
                 * File name of the generated json documentation file
                 */
                'docs_json' => 'api-docs.json',

                /*
                 * File name of the generated YAML documentation file
                 */
                'docs_yaml' => 'api-docs.yaml',

                /*
                 * Set this to `json` or `yaml` to determine which documentation file to use in UI
                 */
                'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),

                /*
                 * Absolute paths to directory containing the swagger annotations are stored.
                 */
                'annotations' => [
                    base_path('app/Swagger'),
                    base_path('app/Http/Controllers'),
                    base_path('Modules/Auth/app/Http/Controllers'),
                    base_path('Modules/AuditLog/app/Http/Controllers'),
                    // Module Notification (26/08): thư mục controller mới, có annotation
                    // #[OA\...] thật (NotificationController) nhưng chưa từng nằm trong
                    // danh sách scan — cùng lỗi lớp với các mục HN-BE-API-005 bên dưới,
                    // route thật publish dạng skeleton generic thay vì contract thật.
                    base_path('Modules/Notification/app/Http/Controllers'),
                    // BaM1* là handler THẬT đang chạy cho inbound/outbound/warranty GĐ1
                    // (đăng ký sau trong Flow06AlignmentServiceProvider nên ghi đè route
                    // cùng path+method của InboundDocumentController/OutboundDocumentController
                    // — 2 file đó bị loại có chủ ý ở scanOptions.exclude bên dưới vì mô tả
                    // sai hợp đồng thật). Các file BaM1* dưới đây hiện CHƯA có annotation
                    // #[OA\...] nào — thêm vào scan trước để annotation được ghi trong
                    // tương lai lập tức có hiệu lực, tránh lặp lại việc quên thêm dòng scan
                    // như toàn bộ lịch sử HN-BE-API-005 phía trên.
                    base_path('Modules/Warehouse/app/Http/Controllers/BaM1InboundDocumentController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/BaM1InboundComponentController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/BaM1InboundScanCorrectionController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/BaM1OutboundFlowController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/BaM1RecipientController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/BaM1ReportController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/BaM1WarrantyController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/WarehouseController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/ImportController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/ImportReportController.php'),
                    // WWHN-184/DEV184-P1-01R (sửa lại 26/08): file này từng khai 8 annotation
                    // #[OA\...] xếp CHỒNG ở class-level, trong đó có 5 lần cùng loại #[OA\Post] —
                    // swagger-php không merge được nhiều attribute CÙNG LOẠI tại cùng một context
                    // và ném "Unable to merge @OA\Post()", làm l5-swagger:generate CRASH. Vì .env
                    // có L5_SWAGGER_GENERATE_ALWAYS=true, mọi request tới /docs đều trigger
                    // generate nên Swagger UI ('/api/documentation') luôn nhận 404 "Failed to
                    // load API definition". Đã tách mỗi annotation ra một private stub method
                    // riêng trong chính controller (xem docContractList/.../docContractReverse) —
                    // giữ nguyên toàn bộ nội dung/operationId (SwaggerRouteSyncTest pin
                    // 'stock_in_cancel'...) nhưng mỗi attribute giờ có context riêng, không còn
                    // xung đột merge. KHÔNG được bỏ dòng dưới, và KHÔNG được gộp nhiều operation
                    // cùng loại HTTP verb trở lại chung một method/class trong file này.
                    base_path('Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php'),
                    // Flow 06 · Báo cáo: contract (filter §13.10 + schema Annex C) được
                    // khai ngay trên controller thật. KHÔNG được bỏ 4 dòng dưới, nếu không
                    // Swagger publish toàn bộ endpoint báo cáo mà không có query parameter.
                    base_path('Modules/Warehouse/app/Http/Controllers/OperationalReportController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/AlignedImportMatchReportController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/AlignedLabelLogReportController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php'),
                    // WWHN-240/DEV240-P1-02C: cùng lỗi lớp với WWHN-184 — annotation
                    // trace-source (correction chain + net effect) đã có sẵn trên controller
                    // nhưng file không nằm trong danh sách scan, nên RouteSyncProcessor sinh
                    // skeleton chung chung và FE/QA không có schema để khoá contract.
                    // KHÔNG được bỏ dòng dưới.
                    base_path('Modules/Warehouse/app/Http/Controllers/StockMovementController.php'),
                    // WWHN-244/DEV244-P1-05: ma tran trang thai + guard cua Flow 04 duoc
                    // khai ngay tren controller that (transitionWarrantyStatus). Thieu dong
                    // duoi, RouteSyncProcessor tu sinh skeleton requestBody {} rong nen FE/QA
                    // khong doc duoc DEC-WAR-STATE-001 tu OpenAPI. KHONG duoc bo dong duoi.
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php'),
                    // WWHN-252/FE252-P1-02: cùng lỗi lớp với WWHN-184 và WWHN-240 —
                    // annotation của nhóm App PV (listPvProducts / getPvProduct /
                    // listPvIndustries + listPvAdminResources / create / update) nằm
                    // ngay trên controller thật. Thiếu hai dòng dưới thì
                    // RouteSyncProcessor sinh summary skeleton dạng
                    // "GET /api/v1/... - Modules Warehouse Http Controllers ..." và FE
                    // không có param/schema nào để wire. KHÔNG được bỏ hai dòng dưới.
                    base_path('Modules/Warehouse/app/Http/Controllers/PvPublicCatalogController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/PvAdminResourceController.php'),
                    // Cùng lỗi lớp HN-BE-API-005 ở trên: PvCatalogImportController có
                    // annotation #[OA\Post] thật (POST /pv-admin/catalog-import, batch
                    // items[]/dry_run) nhưng nếu thiếu dòng dưới, RouteSyncProcessor tự sinh
                    // skeleton "POST ... - Modules Warehouse Http Controllers ..." với
                    // requestBody rỗng — FE App PV sẽ không đọc được schema items[] để build
                    // gói import. KHÔNG được bỏ dòng dưới.
                    base_path('Modules/Warehouse/app/Http/Controllers/PvCatalogImportController.php'),
                    // Mặt storefront công khai `/api/v1/public/*` của App PV — CÙNG LỖI LỚP
                    // với WWHN-184/240/244/252 ở trên, chỉ là chưa ai phát hiện: cả ba
                    // controller trong PublicApi/ đều đã có annotation OpenAPI thật (9
                    // operation, kể cả schema `items[]` của POST /quote-requests) nhưng thư
                    // mục chưa bao giờ nằm trong danh sách scan, nên OpenAPI publish ra 9
                    // skeleton "GET /api/v1/public/... - Modules Warehouse Http Controllers
                    // ..." với requestBody `{additionalProperties: true}`. Đội App PV vì thế
                    // không có hợp đồng chính thức nào để wire, và đó chính là chỗ hở đẻ ra
                    // những cách lách phía FE (gửi N request, lấy sản phẩm đầu tiên).
                    // KHÔNG được bỏ dòng dưới.
                    base_path('Modules/Warehouse/app/Http/Controllers/PublicApi'),
                    // WWHN-153/WWHN-180/WWHN-152: cùng lỗi lớp với WWHN-184/240/244/252 ở trên
                    // — annotation TEMP_ONLY (requestBody temporary_item_code/missing_code_reason/
                    // manual_product_description/condition_status, permission warranty.temp_only)
                    // đã có sẵn trên WarrantyController::tempOnly() nhưng file này chưa từng có
                    // trong danh sách scan, nên RouteSyncProcessor tự sinh skeleton
                    // `{type: object, additionalProperties: true}` rỗng — đúng như QA1 WWHN-152
                    // ghi "typed schema still generic" và FE1 WWHN-180 ghi TEMP_ONLY requestBody
                    // thiếu trong artifact. KHÔNG được bỏ dòng dưới.
                    base_path('Modules/Warehouse/app/Http/Controllers/WarrantyController.php'),
                    // DEV handover QA 2026-08-20 (HN-BE-API-005): cùng lỗi lớp với toàn bộ
                    // các mục trên — 9 controller dưới đây có annotation #[OA\...] thật
                    // (request body schema/query/header đầy đủ) nhưng chưa từng nằm trong
                    // danh sách scan, khiến 90/112 operation mutation trong api-docs.json
                    // chỉ có placeholder `{type: object, additionalProperties: true}` rỗng
                    // (ví dụ đã verify: POST /api/v1/agencies có schema agency_code/agency_name
                    // trong source nhưng "agency_code" không xuất hiện trong artifact).
                    // RouteSyncProcessor tự loại annotation không khớp route table nên việc
                    // thêm file chỉ làm giàu schema, không thể sinh endpoint ma.
                    // LƯU Ý: TransferController/StockRequestController/
                    // DocumentStatusTransitionController KHÔNG được thêm — chúng nằm trong
                    // scanOptions.exclude có chủ ý (bề mặt superseded). KHÔNG được bỏ các dòng dưới.
                    base_path('Modules/Warehouse/app/Http/Controllers/AgencyController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/ContainerController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/DefectCatalogController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/ImportBatchController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/ItemInstanceController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/PhysicalCodeController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/ProductController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/ProductMasterController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/PutawayController.php'),
                    // DEV handover QA 2026-08-20 (HN-BE-API-005, đợt 2): 14 file dưới
                    // vừa được bổ sung annotation #[OA\...] mới (request body theo đúng
                    // validation rules trong code) cho các operation mutation trước đó
                    // chỉ có skeleton rỗng. LƯU Ý pin: CurrentBaselineInboundBoxController
                    // phải giữ operationId `post_api_v1_inbound__documents_id_boxes` và
                    // CurrentBaselineStockFlowController giữ các operationId
                    // `post_api_v1_inbound__documents_id_{reversals,scans}` — bị pin bởi
                    // Wwhn184InboundContractParityTest. KHÔNG được bỏ các dòng dưới.
                    base_path('Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php'),
                    // Staging của màn "Xuất linh kiện" Mini App: annotation ở đây mang
                    // hợp đồng thật (body items[], mã lỗi theo dòng) nên phải được quét,
                    // nếu không Swagger chỉ có summary máy sinh.
                    base_path('Modules/Warehouse/app/Http/Controllers/MiniAppComponentIssueStagingController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineItemIdentityController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineMasterController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyAttachmentController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php'),
                    // ScanController đã có annotation WWHN-150 đầy đủ (requestBody
                    // raw_code/context/warehouse_id, x-permission, response schema) từ
                    // trước nhưng file chưa từng nằm trong danh sách scan — cùng lỗi lớp
                    // với các dòng trên. scripts/verify-wwhn150-contract.mjs đọc
                    // storage/api-docs/api-docs.json để đối chiếu x-permission nên KHÔNG
                    // được bỏ dòng dưới.
                    base_path('Modules/Warehouse/app/Http/Controllers/ScanController.php'),
                    // DEV handover QA 2026-08-20 (HN-BE-API-005, đợt 3): cùng lỗi lớp
                    // với toàn bộ các mục trên — 3 controller dưới đây có annotation
                    // #[OA\...] thật (query parameter/response schema) trên chính
                    // controller nhưng chưa từng nằm trong danh sách scan, nên
                    // RouteSyncProcessor tự sinh skeleton generic (operationId
                    // "get_api_v1_inventory", parameters: []) thay vì contract thật.
                    // KHÔNG được bỏ 3 dòng dưới.
                    base_path('Modules/Warehouse/app/Http/Controllers/InventoryBalanceController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/OutboundRequestController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/DocumentStatusHistoryController.php'),
                    // DEV handover QA 2026-08-20 (HN-BE-API-005, đợt 4): 6 controller dưới
                    // đây trước đó KHÔNG có annotation #[OA\...] nào (khác lớp lỗi ở trên —
                    // đây là annotation mới viết, không phải annotation có sẵn bị bỏ sót),
                    // nên mọi route của chúng publish dạng skeleton generic hoàn toàn.
                    // CurrentBaselineContainerFlowController đặc biệt quan trọng: các
                    // endpoint container này nằm trong ma trận 58 endpoint bắt buộc
                    // If-Match. KHÔNG được bỏ các dòng dưới.
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/AlignedOutboundDocumentController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CanonicalMvpController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentIssueQueryController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportReadController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/ProductStatusCompatibilityController.php'),
                    // Cùng lỗi lớp HN-BE-API-005: hai controller dưới có annotation #[OA\...]
                    // thật nhưng chưa từng nằm trong danh sách scan, nên upload chứng từ đính
                    // kèm bị publish thành "Tạo phiếu nhập mới"/"Tạo phiếu xuất mới" và
                    // pending-evidence/download/delete + ảnh App PV ra summary máy sinh.
                    base_path('Modules/Warehouse/app/Http/Controllers/DocumentAttachmentController.php'),
                    base_path('Modules/Warehouse/app/Http/Controllers/PvProductImageController.php'),
                    // Tra cứu SKU của Mini App: annotation mới, mang bộ lọc thật của index().
                    base_path('Modules/Warehouse/app/Http/Controllers/MiniAppProductController.php'),
                ],
            ],
        ],
    ],
    'defaults' => [
        'routes' => [
            /*
             * Route for accessing parsed swagger annotations.
             */
            'docs' => 'docs',

            /*
             * Route for Oauth2 authentication callback.
             */
            'oauth2_callback' => 'api/oauth2-callback',

            /*
             * Middleware allows to prevent unexpected access to API documentation
             *
             * WWHN-SEC-04: trước đây cả 4 nhóm đều rỗng nên Swagger UI và tệp
             * OpenAPI (bản đồ đầy đủ 345 endpoint) được phát công khai, không
             * cần đăng nhập, ở mọi môi trường kể cả production.
             * EnsureApiDocsEnabled tắt chúng trên production và giữ nguyên hành
             * vi hiện tại trên DEV/UAT/QA.
             */
            'middleware' => [
                'api' => [EnsureApiDocsEnabled::class],
                'asset' => [EnsureApiDocsEnabled::class],
                'docs' => [EnsureApiDocsEnabled::class],
                'oauth2_callback' => [EnsureApiDocsEnabled::class],
            ],

            /*
             * Route Group options
             */
            'group_options' => [],
        ],

        'paths' => [
            /*
             * Absolute path to location where parsed annotations will be stored
             */
            'docs' => storage_path('api-docs'),

            /*
             * Absolute path to directory where to export views
             */
            'views' => base_path('resources/views/vendor/l5-swagger'),

            /*
             * Edit to set the api's base path
             */
            'base' => env('L5_SWAGGER_BASE_PATH', null),

            /*
             * Absolute path to directories that should be excluded from scanning
             * @deprecated Please use `scanOptions.exclude`
             * `scanOptions.exclude` overwrites this
             */
            'excludes' => [],
        ],

        'scanOptions' => [
            /**
             * Optional CustomGeneratorInterface implementation that creates an OpenApi\Generator instance.
             * Use this to provide a custom pre-configured generator.
             * Accepts an instance or a class name (FQCN) implementing the interface.
             *
             * @see CustomGeneratorInterface
             */
            'generator_factory' => null,

            /**
             * Configuration for default processors. Allows to pass processors configuration to swagger-php.
             *
             * @link https://zircote.github.io/swagger-php/reference/processors.html
             */
            'default_processors_configuration' => [
            /** Example */
            /**
             * 'operationId.hash' => true,
             * 'pathFilter' => [
             * 'tags' => [
             * '/pets/',
             * '/store/',
             * ],
             * ],.
             */
            ],

            /**
             * analyser: defaults to \OpenApi\StaticAnalyser .
             *
             * @see scan
             */
            'analyser' => null,

            /**
             * analysis: defaults to a new \OpenApi\Analysis .
             *
             * @see scan
             */
            'analysis' => null,

            /**
             * Custom processors.
             *
             * Each entry can be:
             * - A class name or instance (inserted after BuildPaths by default)
             * - An array with 'class' and 'after' keys for precise positioning:
             *   ['class' => MyProcessor::class, 'after' => SomeProcessor::class]
             *
             * @link https://github.com/zircote/swagger-php/tree/master/Examples/processors/schema-query-parameter
             * @see scan
             */
            'processors' => [
                // \App\SwaggerProcessors\SchemaQueryParameter::class,
                // ['class' => \App\SwaggerProcessors\Custom::class, 'after' => \OpenApi\Processors\AugmentSchemas::class],
                ['class' => RouteSyncProcessor::class, 'after' => AugmentTags::class],
            ],

            /**
             * pattern: string       $pattern File pattern(s) to scan (default: *.php) .
             *
             * @see scan
             */
            'pattern' => null,

            /*
             * Absolute path to directories that should be excluded from scanning
             * @note This option overwrites `paths.excludes`
             * @see \OpenApi\scan
             */
            'exclude' => [
                base_path('Modules/Warehouse/app/Http/Controllers/DocumentStatusTransitionController.php'),
                base_path('Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php'),
                base_path('Modules/Warehouse/app/Http/Controllers/InventoryReservationController.php'),
                base_path('Modules/Warehouse/app/Http/Controllers/InventorySnapshotController.php'),
                base_path('Modules/Warehouse/app/Http/Controllers/OutboundDocumentController.php'),
                base_path('Modules/Warehouse/app/Http/Controllers/SafeStockAlertController.php'),
                base_path('Modules/Warehouse/app/Http/Controllers/SafeStockConfigController.php'),
                base_path('Modules/Warehouse/app/Http/Controllers/StockRequestController.php'),
                base_path('Modules/Warehouse/app/Http/Controllers/TransferController.php'),
                base_path('Modules/Warehouse/app/Http/Controllers/WmsContractController.php'),
            ],

            /*
             * Allows to generate specs either for OpenAPI 3.0.0 or OpenAPI 3.1.0.
             * By default the spec will be in version 3.0.0
             */
            'open_api_spec_version' => env('L5_SWAGGER_OPEN_API_SPEC_VERSION', Generator::OPEN_API_DEFAULT_SPEC_VERSION),
        ],

        /*
         * API security definitions. Will be generated into documentation file.
        */
        'securityDefinitions' => [
            'securitySchemes' => [
                /*
                 * Examples of Security schemes
                 */
                /*
                'api_key_security_example' => [ // Unique name of security
                    'type' => 'apiKey', // The type of the security scheme. Valid values are "basic", "apiKey" or "oauth2".
                    'description' => 'A short description for security scheme',
                    'name' => 'api_key', // The name of the header or query parameter to be used.
                    'in' => 'header', // The location of the API key. Valid values are "query" or "header".
                ],
                'oauth2_security_example' => [ // Unique name of security
                    'type' => 'oauth2', // The type of the security scheme. Valid values are "basic", "apiKey" or "oauth2".
                    'description' => 'A short description for oauth2 security scheme.',
                    'flow' => 'implicit', // The flow used by the OAuth2 security scheme. Valid values are "implicit", "password", "application" or "accessCode".
                    'authorizationUrl' => 'http://example.com/auth', // The authorization URL to be used for (implicit/accessCode)
                    //'tokenUrl' => 'http://example.com/auth' // The authorization URL to be used for (password/application/accessCode)
                    'scopes' => [
                        'read:projects' => 'read your projects',
                        'write:projects' => 'modify projects in your account',
                    ]
                ],
                */

                /* Open API 3.0 support
                'passport' => [ // Unique name of security
                    'type' => 'oauth2', // The type of the security scheme. Valid values are "basic", "apiKey" or "oauth2".
                    'description' => 'Laravel passport oauth2 security.',
                    'in' => 'header',
                    'scheme' => 'https',
                    'flows' => [
                        "password" => [
                            "authorizationUrl" => config('app.url') . '/oauth/authorize',
                            "tokenUrl" => config('app.url') . '/oauth/token',
                            "refreshUrl" => config('app.url') . '/token/refresh',
                            "scopes" => []
                        ],
                    ],
                ],
                'sanctum' => [ // Unique name of security
                    'type' => 'apiKey', // Valid values are "basic", "apiKey" or "oauth2".
                    'description' => 'Enter token in format (Bearer <token>)',
                    'name' => 'Authorization', // The name of the header or query parameter to be used.
                    'in' => 'header', // The location of the API key. Valid values are "query" or "header".
                ],
                */
            ],
            'security' => [
                /*
                 * Examples of Securities
                 */
                [
                    /*
                    'oauth2_security_example' => [
                        'read',
                        'write'
                    ],

                    'passport' => []
                    */
                ],
            ],
        ],

        /*
         * Set this to `true` in development mode so that docs would be regenerated on each request
         * Set this to `false` to disable swagger generation on production
         */
        'generate_always' => env('L5_SWAGGER_GENERATE_ALWAYS', false),

        /*
         * Set this to `true` to generate a copy of documentation in yaml format
         */
        'generate_yaml_copy' => env('L5_SWAGGER_GENERATE_YAML_COPY', false),

        /*
         * Edit to trust the proxy's ip address - needed for AWS Load Balancer
         * string[]
         */
        'proxy' => false,

        /*
         * Configs plugin allows to fetch external configs instead of passing them to SwaggerUIBundle.
         * See more at: https://github.com/swagger-api/swagger-ui#configs-plugin
         */
        'additional_config_url' => null,

        /*
         * Apply a sort to the operation list of each API. It can be 'alpha' (sort by paths alphanumerically),
         * 'method' (sort by HTTP method).
         * Default is the order returned by the server unchanged.
         */
        'operations_sort' => env('L5_SWAGGER_OPERATIONS_SORT', null),

        /*
         * Pass the validatorUrl parameter to SwaggerUi init on the JS side.
         * A null value here disables validation.
         */
        'validator_url' => null,

        /*
         * Swagger UI configuration parameters
         */
        'ui' => [
            'display' => [
                'dark_mode' => env('L5_SWAGGER_UI_DARK_MODE', false),
                /*
                 * Controls the default expansion setting for the operations and tags. It can be :
                 * 'list' (expands only the tags),
                 * 'full' (expands the tags and operations),
                 * 'none' (expands nothing).
                 */
                'doc_expansion' => env('L5_SWAGGER_UI_DOC_EXPANSION', 'none'),

                /**
                 * If set, enables filtering. The top bar will show an edit box that
                 * you can use to filter the tagged operations that are shown. Can be
                 * Boolean to enable or disable, or a string, in which case filtering
                 * will be enabled using that string as the filter expression. Filtering
                 * is case-sensitive matching the filter expression anywhere inside
                 * the tag.
                 */
                'filter' => env('L5_SWAGGER_UI_FILTERS', true), // true | false
            ],

            'authorization' => [
                /*
                 * If set to true, it persists authorization data, and it would not be lost on browser close/refresh
                 */
                'persist_authorization' => env('L5_SWAGGER_UI_PERSIST_AUTHORIZATION', false),

                'oauth2' => [
                    /*
                     * If set to true, adds PKCE to AuthorizationCodeGrant flow
                     */
                    'use_pkce_with_authorization_code_grant' => false,
                ],
            ],
        ],
        /*
         * Constants which can be used in annotations
         */
        'constants' => [
            'L5_SWAGGER_CONST_HOST' => env('L5_SWAGGER_CONST_HOST', env('APP_URL', 'http://localhost:8080')),
        ],
    ],
];
