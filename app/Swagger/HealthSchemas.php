<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/**
 * WWHN-295 / DEV295-P1-02 — contract công khai của `GET /api/v1/health`.
 *
 * LÝ DO TỒN TẠI: runtime đã trả `data.build.{sha,ref,built_at}` (W295-P1-02) nhưng
 * OpenAPI chỉ khai `200 Ready` / `503 Degraded` trỏ vào envelope chung — không schema
 * nào mô tả `data`. Hệ quả cụ thể, không phải hình thức:
 *
 *  - DevOps/QA phải đọc ngược PHP mới biết SHA đã deploy nằm ở khoá nào, nên mọi gate
 *    exact-SHA (WWHN-273/290/296/300) rơi về "tin lời khai" — đúng thứ WWHN-295 sinh ra
 *    để chặn;
 *  - FE/monitor không biết 503 đặt payload health ở đâu. Runtime dùng
 *    `respondError($message, $payload, 503, 'HEALTH_CHECK_DEGRADED')` nên payload nằm ở
 *    `errors` và `error.details`, KHÔNG ở `data`. Đọc nhầm chỗ này là alert im lặng
 *    đúng lúc hệ thống degraded.
 *
 * Nguồn sự thật runtime: App\Http\Controllers\HealthController::show() + config/health.php.
 * Test khoá contract↔runtime: tests/Feature/Wwhn295HealthContractTest.php — mọi khoá
 * runtime phải có trong schema và ngược lại, nên spec không lệch âm thầm được.
 */
#[OA\Schema(
    schema: 'HealthBuildIdentity',
    title: 'Danh tinh artifact dang chay',
    description: 'Do CI inject lúc build image (APP_BUILD_SHA/REF/TIME). `null` nghĩa là artifact KHÔNG khai được '
        .'danh tính — ngoài local/testing đó là fail của gate `release.build_identity` (wms:golive-check), không phải '
        .'giá trị hợp lệ. Không chứa secret: chỉ commit SHA, ref và thời điểm build.',
    type: 'object',
    required: ['sha', 'ref', 'built_at'],
    properties: [
        new OA\Property(property: 'sha', type: 'string', nullable: true, description: 'Commit SHA của source đã build. Phải khớp exact candidate SHA đã pin trên WWHN-295.', example: '0000000000000000000000000000000000000000'),
        new OA\Property(property: 'ref', type: 'string', nullable: true, description: 'Git ref đã build (branch/tag).', example: 'refs/heads/m1'),
        new OA\Property(property: 'built_at', type: 'string', nullable: true, description: 'Thời điểm build artifact (ISO-8601).', example: '2026-08-19T02:11:07+07:00'),
    ]
)]
#[OA\Schema(
    schema: 'HealthAppCheck',
    title: 'Check tien trinh ung dung',
    type: 'object',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['ok'], description: 'Luôn `ok` khi request tới được controller; check này chứng minh tiến trình PHP phục vụ được.'),
    ]
)]
#[OA\Schema(
    schema: 'HealthDatabaseCheck',
    title: 'Check ket noi PostgreSQL',
    type: 'object',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['ok', 'fail'], description: 'Critical: `fail` kéo readiness xuống 503.'),
        new OA\Property(property: 'driver', type: 'string', nullable: true, description: 'Driver đang dùng; chỉ có khi kết nối thành công.', example: 'pgsql'),
    ]
)]
#[OA\Schema(
    schema: 'HealthMigrationsCheck',
    title: 'Check migration da chay',
    type: 'object',
    required: ['status', 'ran_count'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['ok', 'fail'], description: 'Critical: `ran_count = 0` (schema trống) là `fail`.'),
        new OA\Property(property: 'ran_count', type: 'integer', minimum: 0, description: 'Số bản ghi trong bảng migrations.'),
    ]
)]
#[OA\Schema(
    schema: 'HealthSeedCheck',
    title: 'Check seed baseline',
    description: 'Đọc qua model canonical; VIEW tương thích và cờ trình bày cố ý KHÔNG tính là danh tính seed.',
    type: 'object',
    required: ['status', 'markers'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['ok', 'fail'], description: 'Critical: thiếu bất kỳ marker nào là `fail`.'),
        new OA\Property(
            property: 'markers',
            type: 'object',
            required: ['admin_user', 'baseline_warehouse', 'baseline_product'],
            properties: [
                new OA\Property(property: 'admin_user', type: 'boolean', description: 'Có ít nhất một tài khoản ACTIVE giữ vai trò SUPER_ADMIN (cấp bằng `wms:bootstrap-super-admin`).'),
                new OA\Property(property: 'baseline_warehouse', type: 'boolean', description: 'Kho `KHO-TONG-HN` ACTIVE.'),
                new OA\Property(property: 'baseline_product', type: 'boolean', description: 'SKU nền của bộ seed (`D070106` — Kìm điện đa năng 6 inch, danh mục thật DongCheng) ở trạng thái ACTIVE.'),
            ]
        ),
    ]
)]
#[OA\Schema(
    schema: 'HealthCacheCheck',
    title: 'Chan doan Valkey/Redis (KHONG gate readiness)',
    description: 'Cache là dependency optional có fail-open xuống PostgreSQL (App\Support\Cache\CacheService). '
        .'`degraded` KHÔNG kéo HTTP status xuống 503 — biến nó thành hard gate sẽ gây restart loop liveness/readiness '
        .'dù API vẫn phục vụ được. Monitor phải cảnh báo trên khoá này thay vì trên HTTP status.',
    type: 'object',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['ok', 'degraded', 'skipped'], description: '`skipped` khi CACHE_STORE không phải redis.'),
        new OA\Property(property: 'store', type: 'string', nullable: true, description: 'Cache store đang cấu hình.', example: 'redis'),
        new OA\Property(property: 'ping', type: 'string', nullable: true, description: 'Kết quả PING; chỉ có khi status = ok.', example: 'PONG'),
        new OA\Property(property: 'note', type: 'string', nullable: true, description: 'Chỉ có khi status = degraded.'),
    ]
)]
#[OA\Schema(
    schema: 'HealthChecks',
    title: 'Tap check readiness',
    description: 'Critical checks (app, database, migrations, seed) quyết định HTTP 200/503. `cache` là chẩn đoán, cố ý không critical.',
    type: 'object',
    required: ['app', 'database', 'migrations', 'seed', 'cache'],
    properties: [
        new OA\Property(property: 'app', ref: '#/components/schemas/HealthAppCheck'),
        new OA\Property(property: 'database', ref: '#/components/schemas/HealthDatabaseCheck'),
        new OA\Property(property: 'migrations', ref: '#/components/schemas/HealthMigrationsCheck'),
        new OA\Property(property: 'seed', ref: '#/components/schemas/HealthSeedCheck'),
        new OA\Property(property: 'cache', ref: '#/components/schemas/HealthCacheCheck'),
    ]
)]
#[OA\Schema(
    schema: 'HealthPayload',
    title: 'Payload readiness /api/v1/health',
    description: 'Hình dạng payload GIỐNG HỆT nhau ở 200 và 503; chỉ khác vị trí trong envelope '
        .'(200: `data`; 503: `errors` và `error.details`).',
    type: 'object',
    required: ['status', 'version', 'build', 'environment', 'server_time', 'request_id', 'checks'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['ok', 'degraded'], description: '`ok` tương ứng HTTP 200; `degraded` tương ứng HTTP 503 + error_code HEALTH_CHECK_DEGRADED.'),
        new OA\Property(property: 'version', type: 'string', description: 'Nhãn phát hành do vận hành đặt (APP_VERSION). KHÔNG phải danh tính artifact — dùng `build.sha`.', example: '1.0.0'),
        new OA\Property(property: 'build', ref: '#/components/schemas/HealthBuildIdentity'),
        new OA\Property(property: 'environment', type: 'string', description: 'APP_ENV của tiến trình đang chạy.', example: 'production'),
        new OA\Property(property: 'server_time', type: 'string', format: 'date-time', description: 'Thời điểm server trả lời (ISO-8601, Asia/Ho_Chi_Minh).'),
        new OA\Property(property: 'request_id', type: 'string', description: 'Cùng giá trị với `meta.request_id`; dùng để nối raw response với log/audit.'),
        new OA\Property(property: 'checks', ref: '#/components/schemas/HealthChecks'),
    ]
)]
#[OA\Schema(
    schema: 'HealthReadyEnvelope',
    title: 'Health 200 envelope thanh cong',
    description: 'Envelope chuẩn với `data` = HealthPayload (`status = ok`).',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ApiSuccessEnvelope'),
        new OA\Schema(
            type: 'object',
            required: ['data'],
            properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/HealthPayload'),
            ]
        ),
    ]
)]
#[OA\Schema(
    schema: 'HealthDegradedEnvelope',
    title: 'Health 503 envelope loi',
    description: 'Envelope lỗi chuẩn, `error_code = HEALTH_CHECK_DEGRADED`. CHÚ Ý: payload health nằm ở `errors` '
        .'và `error.details`, KHÔNG có khoá `data` — hệ quả trực tiếp của respondError(). Client đọc `data` ở nhánh '
        .'503 sẽ luôn thấy undefined.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ApiErrorEnvelope'),
        new OA\Schema(
            type: 'object',
            required: ['errors', 'error'],
            properties: [
                new OA\Property(property: 'error_code', type: 'string', enum: ['HEALTH_CHECK_DEGRADED']),
                new OA\Property(property: 'errors', ref: '#/components/schemas/HealthPayload'),
                new OA\Property(
                    property: 'error',
                    type: 'object',
                    required: ['code', 'details'],
                    properties: [
                        new OA\Property(property: 'code', type: 'string', enum: ['HEALTH_CHECK_DEGRADED']),
                        new OA\Property(property: 'details', ref: '#/components/schemas/HealthPayload'),
                    ]
                ),
            ]
        ),
    ]
)]
class HealthSchemas {}
