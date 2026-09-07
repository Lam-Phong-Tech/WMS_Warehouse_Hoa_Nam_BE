<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Support\PvRelations;
use OpenApi\Attributes as OA;

/**
 * WWHN-252 / REQ-SRS-033 — Annex C listPvAdminResources / createPvAdminResource /
 * updatePvAdminResource trên /pv-admin/resources/{resource_type}.
 *
 * Phạm vi đúng bằng REQ-SRS-033: "Quản lý PV Industry, Tag, Product Mapping và
 * Recommendation Rule". Mapping many-to-many được quản lý như thuộc tính của hồ
 * sơ sản phẩm (`product-profiles`) vì đó là đơn vị mà UI SCR-PV-ADM-01 thao tác.
 *
 * Ranh giới quyền (đây là gốc của FE252-P1-03): endpoint này chỉ nhận
 * `pv.master.manage`. Bật/tắt publish là hành động RIÊNG với quyền RIÊNG
 * `pv.publish` tại PATCH /pv/products/{sku_id}/publish — vì vậy `published`
 * ở đây là trường CHỈ ĐỌC và payload gửi kèm sẽ bị từ chối.
 *
 * §8.2: PATCH bắt buộc If-Match (thiếu = 428 PRECONDITION_REQUIRED, lệch = 412
 * STALE_VERSION). Cột `version` của pv_industry/pv_filter_tag/pv_recommendation_rule
 * do migration 2026_08_19_000100 bổ sung.
 */
class PvAdminResourceController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    /**
     * Master App PV: đọc rất nhiều (mọi màn PV Admin và form gán ngành/tag đều load),
     * ghi chỉ qua store()/update() ngay trong controller này. Namespace riêng
     * `master:version` dùng chung với các danh mục khác.
     */
    private const CACHE_VERSION_NS = 'master:version';

    private const RESOURCE_INDUSTRIES = 'industries';

    private const RESOURCE_TAGS = 'tags';

    private const RESOURCE_RULES = 'recommendation-rules';

    private const RESOURCE_PROFILES = 'product-profiles';

    /** Trần số ảnh mỗi hồ sơ sản phẩm — FE (PvProductImageField) khoá cùng số này. */
    private const PROFILE_IMAGE_MAX = 8;

    private const RESOURCE_TYPES = [
        self::RESOURCE_INDUSTRIES,
        self::RESOURCE_TAGS,
        self::RESOURCE_RULES,
        self::RESOURCE_PROFILES,
    ];

    #[OA\Get(
        path: '/api/v1/pv-admin/resources/{resourceType}',
        operationId: 'listPvAdminResources',
        summary: 'PV Admin — danh sách master App PV (industry/tag/rule/profile)',
        security: [['bearerAuth' => []]],
        tags: ['12. App PV'],
        parameters: [
            new OA\Parameter(name: 'resourceType', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: self::RESOURCE_TYPES)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PVAdminResourcePage'),
            new OA\Response(response: 403, description: 'Không có quyền pv.master.manage'),
            new OA\Response(response: 404, description: 'resource_type không hợp lệ'),
        ]
    )]
    public function index(Request $request, string $resourceType): JsonResponse
    {
        // resource_type sai phải trả 404 NGAY, không phụ thuộc cache.
        $this->assertKnownResource($resourceType);

        $perPage = $this->adminPageSize($request);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        // TTL 'master' (1800s): danh mục App PV gần như tĩnh; store()/update() bên dưới
        // bump `master:version` nên danh sách đổi ngay khi có ghi. resourceType nằm
        // trong namespace, phân trang chạy TRONG SQL nên page/per_page nằm trong khoá.
        $cached = $this->cachedReadPayload(
            'warehouse:pv-admin:'.$resourceType,
            $this->readFiltersWithPaging($request),
            function () use ($request, $resourceType): array {
                $page = match ($resourceType) {
                    self::RESOURCE_INDUSTRIES => $this->listSimpleMaster($request, PvRelations::industry(), 'industry_code', 'industry_name'),
                    self::RESOURCE_TAGS => $this->listSimpleMaster($request, PvRelations::tag(), 'tag_code', 'tag_name'),
                    self::RESOURCE_RULES => $this->listRules($request),
                    default => $this->listProfiles($request),
                };

                return ['items' => $page->items(), 'total' => $page->total()];
            },
            'master',
            self::CACHE_VERSION_NS,
        );

        // Dựng lại paginator với đúng path/pageName mà ->paginate() vẫn dùng, để envelope
        // (meta phân trang + links) giữ nguyên hình dạng cũ.
        return $this->respondPaginated(new LengthAwarePaginator(
            $cached['items'],
            $cached['total'],
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        ));
    }

    #[OA\Post(
        path: '/api/v1/pv-admin/resources/{resourceType}',
        operationId: 'createPvAdminResource',
        summary: 'PV Admin — tạo master App PV',
        description: 'Field body thay đổi theo resourceType: industries/tags cần industry_code|tag_code, '
            .'industry_name|tag_name, status, sort_order (tags có thêm tag_type); rules cần industry_id, priority, '
            .'status (kèm tuỳ chọn sku_id/category_id/tag_id); profiles cần sku_id, display_name, industry_ids[], '
            .'tag_ids[], sort_order (không nhận published).',
        security: [['bearerAuth' => []]],
        tags: ['12. App PV'],
        parameters: [
            new OA\Parameter(name: 'resourceType', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: self::RESOURCE_TYPES)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'industry_code', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'tag_code', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'industry_name', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'tag_name', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'tag_type', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, default: 'ACTIVE'),
                    new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, maximum: 2147483647, nullable: true),
                    new OA\Property(property: 'industry_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'tag_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'priority', type: 'integer', minimum: 0, maximum: 2147483647, nullable: true),
                    new OA\Property(property: 'display_name', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'industry_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
                    new OA\Property(property: 'tag_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'PVAdminResource'),
            new OA\Response(response: 403, description: 'Không có quyền pv.master.manage'),
            new OA\Response(response: 409, description: 'STATE_CONFLICT (priority trùng trong ngành)'),
            new OA\Response(response: 422, description: 'VALIDATION_ERROR / BUSINESS_RULE_VIOLATION'),
        ]
    )]
    public function store(Request $request, string $resourceType): JsonResponse
    {
        $this->assertKnownResource($resourceType);

        $created = DB::transaction(fn (): array => match ($resourceType) {
            self::RESOURCE_INDUSTRIES => $this->createIndustry($request),
            self::RESOURCE_TAGS => $this->createTag($request),
            self::RESOURCE_RULES => $this->createRule($request),
            default => $this->createProfile($request),
        });

        $this->audit($request, 'CREATE', $resourceType, (string) $created['id'], null, $created);

        // Bump SAU commit (DB::transaction ở trên đã đóng): danh sách phải thấy bản ghi
        // mới ngay, không chờ hết TTL 'master'.
        DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));
        $this->invalidateProductReadCache();

        return $this->respondCreated($created, 'Tạo dữ liệu App PV thành công.');
    }

    #[OA\Patch(
        path: '/api/v1/pv-admin/resources/{resourceType}/{id}',
        operationId: 'updatePvAdminResource',
        summary: 'PV Admin — cập nhật master App PV (bắt buộc If-Match)',
        description: 'Field body thay đổi theo resourceType — xem mô tả tại POST /pv-admin/resources/{resourceType}.',
        security: [['bearerAuth' => []]],
        tags: ['12. App PV'],
        parameters: [
            new OA\Parameter(name: 'resourceType', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: self::RESOURCE_TYPES)),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object', description: 'Field tuỳ resourceType, tất cả tuỳ chọn — xem POST cùng path.')
        ),
        responses: [
            new OA\Response(response: 200, description: 'PVAdminResource'),
            new OA\Response(response: 403, description: 'Không có quyền pv.master.manage'),
            new OA\Response(response: 404, description: 'RESOURCE_NOT_FOUND'),
            new OA\Response(response: 409, description: 'STATE_CONFLICT'),
            new OA\Response(response: 412, description: 'STALE_VERSION'),
            new OA\Response(response: 422, description: 'VALIDATION_ERROR / BUSINESS_RULE_VIOLATION'),
            new OA\Response(response: 428, description: 'PRECONDITION_REQUIRED (thiếu If-Match)'),
        ]
    )]
    public function update(Request $request, string $resourceType, string $id): JsonResponse
    {
        $this->assertKnownResource($resourceType);

        [$before, $after] = DB::transaction(fn (): array => match ($resourceType) {
            self::RESOURCE_INDUSTRIES => $this->updateSimpleMaster($request, $id, PvRelations::industry(), 'industry_code', 'industry_name'),
            self::RESOURCE_TAGS => $this->updateSimpleMaster($request, $id, PvRelations::tag(), 'tag_code', 'tag_name'),
            self::RESOURCE_RULES => $this->updateRule($request, $id),
            default => $this->updateProfile($request, $id),
        });

        $this->audit($request, 'UPDATE', $resourceType, $id, $before, $after);

        // Cùng namespace với index(): sửa xong là danh sách đọc ra bản mới.
        DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));
        $this->invalidateProductReadCache();

        return $this->respondSuccess($after, 'Cập nhật dữ liệu App PV thành công.');
    }

    /**
     * Hồ sơ App PV mang description/display_name mà danh sách + chi tiết sản phẩm của
     * máy quét join vào, và các read đó khoá theo 'stock:version' chứ không phải
     * 'master:version'. Bump cả hai: master data sửa rất hiếm nên một lần bump thừa
     * cho industries/tags/rules rẻ hơn nhiều so với việc máy quét đọc mô tả cũ.
     */
    private function invalidateProductReadCache(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));
    }

    // ------------------------------------------------------------------
    // industries / tags — cùng hình dạng code + name + status + sort_order
    // ------------------------------------------------------------------

    /**
     * @param  array{table: string, key: string}  $relation
     */
    private function listSimpleMaster(Request $request, array $relation, string $codeColumn, string $nameColumn)
    {
        $query = DB::table($relation['table']);
        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->query('status')));
        }
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '') {
            $like = PvRelations::isPgsql() ? 'ilike' : 'like';
            $query->where(function ($nested) use ($like, $keyword, $codeColumn, $nameColumn): void {
                $nested->where($codeColumn, $like, '%'.$keyword.'%')
                    ->orWhere($nameColumn, $like, '%'.$keyword.'%');
            });
        }

        return $query->orderBy('sort_order')->orderBy($nameColumn)
            ->paginate($this->adminPageSize($request))
            ->through(fn ($row): array => $this->presentSimpleMaster($row, $relation['key'], $codeColumn, $nameColumn));
    }

    /**
     * @return array<string, mixed>
     */
    private function createIndustry(Request $request): array
    {
        $relation = PvRelations::industry();
        $payload = $this->validateSimpleMaster($request, $relation, 'industry_code', 'industry_name', null);

        $id = (string) Str::uuid();
        DB::table($relation['table'])->insert([
            $relation['key'] => $id,
            'industry_code' => $payload['code'],
            'industry_name' => $payload['name'],
            'status' => $payload['status'],
            'sort_order' => $payload['sort_order'],
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->findSimpleMaster($relation, $id, 'industry_code', 'industry_name');
    }

    /**
     * @return array<string, mixed>
     */
    private function createTag(Request $request): array
    {
        $relation = PvRelations::tag();
        $payload = $this->validateSimpleMaster($request, $relation, 'tag_code', 'tag_name', null);

        $id = (string) Str::uuid();
        $row = [
            $relation['key'] => $id,
            'tag_code' => $payload['code'],
            'tag_name' => $payload['name'],
            'status' => $payload['status'],
            'sort_order' => $payload['sort_order'],
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $tagType = $request->input('tag_type');
        if (is_string($tagType) && trim($tagType) !== '') {
            $row['tag_type'] = trim($tagType);
        }
        DB::table($relation['table'])->insert($row);

        return $this->findSimpleMaster($relation, $id, 'tag_code', 'tag_name');
    }

    /**
     * @param  array{table: string, key: string}  $relation
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function updateSimpleMaster(Request $request, string $id, array $relation, string $codeColumn, string $nameColumn): array
    {
        $current = DB::table($relation['table'])->where($relation['key'], $id)->first();
        if ($current === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy dữ liệu App PV.');
        }

        $nextVersion = $this->assertIfMatch($request, (int) ($current->version ?? 1));
        $payload = $this->validateSimpleMaster($request, $relation, $codeColumn, $nameColumn, $id);

        $update = [
            $codeColumn => $payload['code'],
            $nameColumn => $payload['name'],
            'status' => $payload['status'],
            'sort_order' => $payload['sort_order'],
            'version' => $nextVersion,
            'updated_at' => now(),
        ];
        if ($relation['table'] === 'pv_filter_tag' && $request->exists('tag_type')) {
            $tagType = $request->input('tag_type');
            $update['tag_type'] = is_string($tagType) && trim($tagType) !== '' ? trim($tagType) : null;
        }

        $before = $this->presentSimpleMaster($current, $relation['key'], $codeColumn, $nameColumn);
        DB::table($relation['table'])->where($relation['key'], $id)->update($update);

        return [$before, $this->findSimpleMaster($relation, $id, $codeColumn, $nameColumn)];
    }

    /**
     * BR-PV-MST-01..03: code là định danh nghiệp vụ duy nhất; đổi sang code đã
     * tồn tại phải bị chặn ở tầng ứng dụng chứ không để rơi xuống unique index
     * (sẽ thành 500 với thông điệp SQL lộ tên ràng buộc).
     *
     * @param  array{table: string, key: string}  $relation
     * @return array{code: string, name: string, status: string, sort_order: int}
     */
    private function validateSimpleMaster(Request $request, array $relation, string $codeColumn, string $nameColumn, ?string $ignoreId): array
    {
        $errors = [];
        $code = trim((string) $request->input($codeColumn, ''));
        $name = trim((string) $request->input($nameColumn, ''));
        $status = strtoupper(trim((string) $request->input('status', 'ACTIVE')));
        $sortOrderRaw = $request->input('sort_order', 0);

        if ($code === '' || mb_strlen($code) > 80) {
            $errors[$codeColumn] = ['Mã bắt buộc và tối đa 80 ký tự.'];
        }
        if ($name === '' || mb_strlen($name) > 200) {
            $errors[$nameColumn] = ['Tên bắt buộc và tối đa 200 ký tự.'];
        }
        if (! in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            $errors['status'] = ['status chỉ nhận ACTIVE hoặc INACTIVE.'];
        }
        if (! is_numeric($sortOrderRaw) || (int) $sortOrderRaw < 0 || (int) $sortOrderRaw > 2147483647) {
            $errors['sort_order'] = ['sort_order phải là số nguyên không âm, tối đa 2147483647.'];
        }

        if ($errors === [] && $code !== '') {
            $duplicate = DB::table($relation['table'])->where($codeColumn, $code)
                ->when($ignoreId !== null, fn ($q) => $q->where($relation['key'], '!=', $ignoreId))
                ->exists();
            if ($duplicate) {
                $errors[$codeColumn] = ['Mã đã tồn tại.'];
            }
        }

        if ($errors !== []) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Dữ liệu App PV không hợp lệ.', $errors);
        }

        return ['code' => $code, 'name' => $name, 'status' => $status, 'sort_order' => (int) $sortOrderRaw];
    }

    /**
     * @param  array{table: string, key: string}  $relation
     * @return array<string, mixed>
     */
    private function findSimpleMaster(array $relation, string $id, string $codeColumn, string $nameColumn): array
    {
        $row = DB::table($relation['table'])->where($relation['key'], $id)->first();
        if ($row === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy dữ liệu App PV.');
        }

        return $this->presentSimpleMaster($row, $relation['key'], $codeColumn, $nameColumn);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSimpleMaster(object $row, string $keyColumn, string $codeColumn, string $nameColumn): array
    {
        $presented = [
            'id' => (string) $row->{$keyColumn},
            'code' => (string) $row->{$codeColumn},
            'name' => (string) $row->{$nameColumn},
            'status' => (string) $row->status,
            'sort_order' => (int) ($row->sort_order ?? 0),
            'version' => (int) ($row->version ?? 1),
        ];
        if (property_exists($row, 'tag_type')) {
            $presented['tag_type'] = $row->tag_type !== null ? (string) $row->tag_type : null;
        }

        return $presented;
    }

    // ------------------------------------------------------------------
    // recommendation-rules
    // ------------------------------------------------------------------

    private function listRules(Request $request)
    {
        $relation = PvRelations::rule();
        $query = DB::table($relation['table'].' as r')
            ->leftJoin(PvRelations::READ_INDUSTRY.' as i', 'i.industry_id', '=', 'r.industry_id');

        if ($request->filled('status')) {
            $query->where('r.status', strtoupper((string) $request->query('status')));
        }
        if ($request->filled('industry_id')) {
            $query->where('r.industry_id', $request->query('industry_id'));
        }

        return $query->orderBy('r.priority')
            ->paginate($this->adminPageSize($request), [
                'r.'.$relation['key'].' as rule_key',
                'r.industry_id', 'r.'.$relation['sku'].' as sku_ref', 'r.category_id', 'r.tag_id',
                'r.priority', 'r.status', 'r.version',
                'i.industry_name',
            ])
            ->through(fn ($row): array => [
                'id' => (string) $row->rule_key,
                'industry_id' => (string) $row->industry_id,
                'industry_name' => $row->industry_name !== null ? (string) $row->industry_name : null,
                'sku_id' => $row->sku_ref !== null ? (string) $row->sku_ref : null,
                'category_id' => $row->category_id !== null ? (string) $row->category_id : null,
                'tag_id' => $row->tag_id !== null ? (string) $row->tag_id : null,
                'priority' => (int) $row->priority,
                'status' => (string) $row->status,
                'version' => (int) ($row->version ?? 1),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createRule(Request $request): array
    {
        $relation = PvRelations::rule();
        $payload = $this->validateRule($request, null);

        $id = (string) Str::uuid();
        DB::table($relation['table'])->insert([
            $relation['key'] => $id,
            'industry_id' => $payload['industry_id'],
            $relation['sku'] => $payload['sku_id'],
            'category_id' => $payload['category_id'],
            'tag_id' => $payload['tag_id'],
            'priority' => $payload['priority'],
            'status' => $payload['status'],
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->findRule($id);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function updateRule(Request $request, string $id): array
    {
        $relation = PvRelations::rule();
        $current = DB::table($relation['table'])->where($relation['key'], $id)->first();
        if ($current === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy rule gợi ý App PV.');
        }

        $nextVersion = $this->assertIfMatch($request, (int) ($current->version ?? 1));
        $payload = $this->validateRule($request, $id);
        $before = $this->findRule($id);

        DB::table($relation['table'])->where($relation['key'], $id)->update([
            'industry_id' => $payload['industry_id'],
            $relation['sku'] => $payload['sku_id'],
            'category_id' => $payload['category_id'],
            'tag_id' => $payload['tag_id'],
            'priority' => $payload['priority'],
            'status' => $payload['status'],
            'version' => $nextVersion,
            'updated_at' => now(),
        ]);

        return [$before, $this->findRule($id)];
    }

    /**
     * ERD bảng 57: "Ít nhất một selector sku/category/tag; sản phẩm cuối phải
     * active+published; priority unique trong scope đã chọn."
     *
     * @return array{industry_id: string, sku_id: ?string, category_id: ?string, tag_id: ?string, priority: int, status: string}
     */
    private function validateRule(Request $request, ?string $ignoreId): array
    {
        $errors = [];
        $industryId = $this->nullableUuidInput($request, 'industry_id', $errors);
        $skuId = $this->nullableUuidInput($request, 'sku_id', $errors);
        $categoryId = $this->nullableUuidInput($request, 'category_id', $errors);
        $tagId = $this->nullableUuidInput($request, 'tag_id', $errors);
        $status = strtoupper(trim((string) $request->input('status', 'ACTIVE')));
        $priorityRaw = $request->input('priority');

        if ($industryId === null && ! isset($errors['industry_id'])) {
            $errors['industry_id'] = ['industry_id là bắt buộc.'];
        }
        if (! in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            $errors['status'] = ['status chỉ nhận ACTIVE hoặc INACTIVE.'];
        }
        if (! is_numeric($priorityRaw) || (int) $priorityRaw < 0 || (int) $priorityRaw > 2147483647) {
            $errors['priority'] = ['priority phải là số nguyên không âm, tối đa 2147483647.'];
        }
        if ($errors !== []) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Dữ liệu rule gợi ý không hợp lệ.', $errors);
        }

        $priority = (int) $priorityRaw;

        if ($skuId === null && $categoryId === null && $tagId === null) {
            ApiError::abort(422, 'BUSINESS_RULE_VIOLATION', 'Rule phải có ít nhất một selector: sku_id, category_id hoặc tag_id.');
        }

        $industryActive = DB::table(PvRelations::READ_INDUSTRY)
            ->where('industry_id', $industryId)->where('status', 'ACTIVE')->exists();
        if (! $industryActive) {
            ApiError::abort(422, 'BUSINESS_RULE_VIOLATION', 'Ngành nghề không tồn tại hoặc đã INACTIVE.');
        }
        if ($tagId !== null && ! DB::table(PvRelations::READ_TAG)->where('tag_id', $tagId)->where('status', 'ACTIVE')->exists()) {
            ApiError::abort(422, 'BUSINESS_RULE_VIOLATION', 'Tag không tồn tại hoặc đã INACTIVE.');
        }
        if ($skuId !== null && ! DB::table('sku')->where('sku_id', $skuId)->where('status', 'ACTIVE')->exists()) {
            ApiError::abort(422, 'BUSINESS_RULE_VIOLATION', 'SKU không tồn tại hoặc không ACTIVE.');
        }
        if ($categoryId !== null && ! DB::table('product_categories')->where('id', $categoryId)->exists()) {
            ApiError::abort(422, 'BUSINESS_RULE_VIOLATION', 'Nhóm hàng không tồn tại.');
        }

        if ($status === 'ACTIVE') {
            $relation = PvRelations::rule();
            $duplicate = DB::table($relation['table'])
                ->where('industry_id', $industryId)
                ->where('priority', $priority)
                ->where('status', 'ACTIVE')
                ->when($ignoreId !== null, fn ($q) => $q->where($relation['key'], '!=', $ignoreId))
                ->exists();
            if ($duplicate) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Priority đã được dùng bởi một rule ACTIVE khác trong cùng ngành nghề.');
            }
        }

        return [
            'industry_id' => (string) $industryId,
            'sku_id' => $skuId,
            'category_id' => $categoryId,
            'tag_id' => $tagId,
            'priority' => $priority,
            'status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findRule(string $id): array
    {
        $relation = PvRelations::rule();
        $row = DB::table($relation['table'])->where($relation['key'], $id)->first();
        if ($row === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy rule gợi ý App PV.');
        }

        return [
            'id' => (string) $row->{$relation['key']},
            'industry_id' => (string) $row->industry_id,
            'sku_id' => $row->{$relation['sku']} !== null ? (string) $row->{$relation['sku']} : null,
            'category_id' => $row->category_id !== null ? (string) $row->category_id : null,
            'tag_id' => $row->tag_id !== null ? (string) $row->tag_id : null,
            'priority' => (int) $row->priority,
            'status' => (string) $row->status,
            'version' => (int) ($row->version ?? 1),
        ];
    }

    // ------------------------------------------------------------------
    // product-profiles (kèm mapping ngành nghề / tag)
    // ------------------------------------------------------------------

    private function listProfiles(Request $request)
    {
        $relation = PvRelations::profile();
        $query = DB::table($relation['table'].' as p')
            ->join('sku as s', 's.sku_id', '=', 'p.'.$relation['sku']);

        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '') {
            $like = PvRelations::isPgsql() ? 'ilike' : 'like';
            $query->where(function ($nested) use ($like, $keyword): void {
                $nested->where('p.display_name', $like, '%'.$keyword.'%')
                    ->orWhere('s.sku_code', $like, '%'.$keyword.'%')
                    ->orWhere('s.sku_name', $like, '%'.$keyword.'%');
            });
        }
        if ($request->filled('published')) {
            $query->where('s.published_to_app', filter_var($request->query('published'), FILTER_VALIDATE_BOOLEAN));
        }

        $page = $query->orderBy('p.sort_order')->orderBy('s.sku_name')
            ->paginate($this->adminPageSize($request), [
                'p.'.$relation['key'].' as profile_key',
                'p.'.$relation['sku'].' as sku_ref',
                'p.display_name', 'p.description', 'p.sort_order', 'p.version',
                's.sku_code', 's.sku_name', 's.published_to_app', 's.status as sku_status',
            ]);

        $profileIds = collect($page->items())->pluck('profile_key')->map(fn ($id): string => (string) $id)->all();
        $industryIds = $this->mappedIds(PvRelations::productIndustryTable(), 'industry_id', $profileIds);
        $tagIds = $this->mappedIds(PvRelations::productTagTable(), 'tag_id', $profileIds);
        $images = $this->imagesByProfile($profileIds);

        return $page->through(fn ($row): array => [
            'id' => (string) $row->profile_key,
            'sku_id' => (string) $row->sku_ref,
            'sku_code' => (string) $row->sku_code,
            'sku_name' => (string) $row->sku_name,
            'sku_status' => (string) $row->sku_status,
            'display_name' => (string) $row->display_name,
            'description' => $row->description !== null ? (string) $row->description : null,
            'sort_order' => (int) ($row->sort_order ?? 0),
            // Chỉ đọc: bật/tắt publish là quyền pv.publish ở endpoint riêng.
            'published' => (bool) $row->published_to_app,
            'industry_ids' => $industryIds[(string) $row->profile_key] ?? [],
            'tag_ids' => $tagIds[(string) $row->profile_key] ?? [],
            'images' => $images[(string) $row->profile_key] ?? [],
            'version' => (int) ($row->version ?? 1),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createProfile(Request $request): array
    {
        $relation = PvRelations::profile();
        $payload = $this->validateProfile($request, null);

        $existing = DB::table($relation['table'])->where($relation['sku'], $payload['sku_id'])->first();
        if ($existing !== null) {
            ApiError::abort(409, 'STATE_CONFLICT', 'SKU đã có hồ sơ App PV; hãy cập nhật hồ sơ hiện có.');
        }

        $id = (string) Str::uuid();
        DB::table($relation['table'])->insert([
            $relation['key'] => $id,
            $relation['sku'] => $payload['sku_id'],
            'display_name' => $payload['display_name'],
            'description' => $payload['description'],
            'sort_order' => $payload['sort_order'],
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->syncProfileMappings($id, $payload['industry_ids'], $payload['tag_ids']);
        $this->syncProfileImages($request, $id, $payload['images']);

        return $this->findProfile($id);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function updateProfile(Request $request, string $id): array
    {
        $relation = PvRelations::profile();
        $current = DB::table($relation['table'])->where($relation['key'], $id)->first();
        if ($current === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hồ sơ App PV.');
        }

        $nextVersion = $this->assertIfMatch($request, (int) ($current->version ?? 1));
        $payload = $this->validateProfile($request, (string) $current->{$relation['sku']});
        $before = $this->findProfile($id);

        DB::table($relation['table'])->where($relation['key'], $id)->update([
            'display_name' => $payload['display_name'],
            'description' => $payload['description'],
            'sort_order' => $payload['sort_order'],
            'version' => $nextVersion,
            'updated_at' => now(),
        ]);

        $this->syncProfileMappings($id, $payload['industry_ids'], $payload['tag_ids']);
        $this->syncProfileImages($request, $id, $payload['images']);

        return [$before, $this->findProfile($id)];
    }

    /**
     * @return array{sku_id: string, display_name: string, description: ?string, sort_order: int, industry_ids: array<int, string>, tag_ids: array<int, string>, images: ?array<int, array{url: string, alt_text: ?string, is_primary: bool}>}
     */
    private function validateProfile(Request $request, ?string $lockedSkuId): array
    {
        $errors = [];

        if ($request->exists('published')) {
            // FE252-P1-03: pv.master.manage KHÔNG được publish. Từ chối tường minh
            // thay vì bỏ qua im lặng để client biết phải gọi endpoint pv.publish.
            ApiError::abort(422, 'BUSINESS_RULE_VIOLATION', 'Publish/unpublish dùng PATCH /pv/products/{sku_id}/publish với quyền pv.publish.');
        }

        $skuId = $lockedSkuId;
        if ($lockedSkuId === null) {
            $skuId = $this->nullableUuidInput($request, 'sku_id', $errors);
            if ($skuId === null && ! isset($errors['sku_id'])) {
                $errors['sku_id'] = ['sku_id là bắt buộc.'];
            }
        }

        $displayName = trim((string) $request->input('display_name', ''));
        if ($displayName === '' || mb_strlen($displayName) > 255) {
            $errors['display_name'] = ['display_name bắt buộc và tối đa 255 ký tự.'];
        }

        $sortOrderRaw = $request->input('sort_order', 0);
        if (! is_numeric($sortOrderRaw) || (int) $sortOrderRaw < 0 || (int) $sortOrderRaw > 2147483647) {
            $errors['sort_order'] = ['sort_order phải là số nguyên không âm, tối đa 2147483647.'];
        }

        $industryIds = $this->uuidListInput($request, 'industry_ids', $errors);
        $tagIds = $this->uuidListInput($request, 'tag_ids', $errors);
        $images = $this->imageListInput($request, $errors);

        if ($errors !== []) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Dữ liệu hồ sơ App PV không hợp lệ.', $errors);
        }

        if (! DB::table('sku')->where('sku_id', $skuId)->where('status', 'ACTIVE')->exists()) {
            ApiError::abort(422, 'BUSINESS_RULE_VIOLATION', 'SKU không tồn tại hoặc không ACTIVE.');
        }

        // BR-PV-MST: mapping tới master INACTIVE bị loại khỏi mặt công khai nên
        // không cho tạo mới ngay từ đầu.
        $this->assertAllActive(PvRelations::READ_INDUSTRY, 'industry_id', $industryIds, 'Ngành nghề');
        $this->assertAllActive(PvRelations::READ_TAG, 'tag_id', $tagIds, 'Tag');

        $description = $request->input('description');

        return [
            'sku_id' => (string) $skuId,
            'display_name' => $displayName,
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'sort_order' => (int) $sortOrderRaw,
            'industry_ids' => $industryIds,
            'tag_ids' => $tagIds,
            'images' => $images,
        ];
    }

    /**
     * Ảnh sản phẩm App PV do client gửi lên dưới dạng URL ĐÃ upload sẵn (Cloudinary
     * hoặc CDN khác) — BE KHÔNG nhận binary ở endpoint này.
     *
     * Khoá `images` VẮNG MẶT nghĩa là "không đụng tới ảnh" (trả `null`), khác hẳn
     * `images: []` nghĩa là "xoá hết ảnh". Phân biệt này là bắt buộc: nếu coi cả
     * hai như nhau thì mọi PATCH cũ (SCR-PV-ADM-01 chỉ sửa tên/mô tả, không gửi
     * `images`) sẽ âm thầm xoá sạch ảnh của hồ sơ.
     *
     * @param  array<string, array<int, string>>  $errors
     * @return array<int, array{url: string, alt_text: ?string, is_primary: bool}>|null
     */
    private function imageListInput(Request $request, array &$errors): ?array
    {
        if (! $request->exists('images')) {
            return null;
        }

        $raw = $request->input('images');
        if ($raw === null || $raw === '') {
            return [];
        }
        if (! is_array($raw)) {
            $errors['images'] = ['images phải là mảng.'];

            return null;
        }
        if (count($raw) > self::PROFILE_IMAGE_MAX) {
            $errors['images'] = ['Tối đa '.self::PROFILE_IMAGE_MAX.' ảnh cho mỗi sản phẩm.'];

            return null;
        }

        $images = [];
        $seen = [];
        $primaryCount = 0;
        foreach (array_values($raw) as $index => $entry) {
            if (! is_array($entry)) {
                $errors["images.{$index}"] = ['Mỗi phần tử images phải là object {url, alt_text, is_primary}.'];

                continue;
            }

            $url = is_string($entry['url'] ?? null) ? trim($entry['url']) : '';
            if ($url === '' || mb_strlen($url) > 500) {
                $errors["images.{$index}.url"] = ['url ảnh bắt buộc và tối đa 500 ký tự.'];

                continue;
            }
            // Chỉ nhận http(s) tuyệt đối: `javascript:` / `data:` lọt xuống DB là
            // lỗ XSS ở CHÍNH mặt công khai (App PV render thẳng vào <img src>).
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
                $errors["images.{$index}.url"] = ['url ảnh phải là địa chỉ http(s) đầy đủ.'];

                continue;
            }
            if (isset($seen[$url])) {
                $errors["images.{$index}.url"] = ['Ảnh bị trùng trong danh sách.'];

                continue;
            }
            $seen[$url] = true;

            $altRaw = $entry['alt_text'] ?? null;
            $alt = is_string($altRaw) && trim($altRaw) !== '' ? trim($altRaw) : null;
            if ($alt !== null && mb_strlen($alt) > 255) {
                $errors["images.{$index}.alt_text"] = ['alt_text tối đa 255 ký tự.'];

                continue;
            }

            $isPrimary = filter_var($entry['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isPrimary) {
                $primaryCount++;
            }

            $images[] = ['url' => $url, 'alt_text' => $alt, 'is_primary' => $isPrimary];
        }

        if ($errors !== []) {
            return null;
        }

        // ux_erd_pv_primary_image là UNIQUE partial index trên (profile_id) WHERE
        // is_primary — hai ảnh chính cùng hồ sơ sẽ nổ 23505 ở tầng DB. Chặn ở đây
        // để client nhận 422 có field thay vì 500.
        if ($primaryCount > 1) {
            $errors['images'] = ['Chỉ được chọn một ảnh đại diện.'];

            return null;
        }
        // Không chọn ảnh nào làm đại diện thì lấy ảnh đầu: mặt công khai luôn cần
        // đúng một ảnh để hiển thị trên thẻ sản phẩm.
        if ($primaryCount === 0 && $images !== []) {
            $images[0]['is_primary'] = true;
        }

        return $images;
    }

    /**
     * Ghi lại toàn bộ ảnh của hồ sơ theo đúng thứ tự client gửi (index = sort_order).
     *
     * Xoá-rồi-chèn thay vì upsert từng dòng: thứ tự và cờ `is_primary` là thuộc
     * tính của CẢ danh sách, và index UNIQUE `ux_erd_pv_primary_image` sẽ chặn
     * bước trung gian nếu ảnh chính mới được chèn trước khi ảnh chính cũ bị hạ cờ.
     *
     * @param  array<int, array{url: string, alt_text: ?string, is_primary: bool}>|null  $images
     */
    private function syncProfileImages(Request $request, string $profileId, ?array $images): void
    {
        if ($images === null) {
            return;
        }

        $actorId = $request->user()?->user_id;
        DB::table('pv_product_images')->where('profile_id', $profileId)->delete();
        foreach ($images as $index => $image) {
            DB::table('pv_product_images')->insert([
                'id' => (string) Str::uuid(),
                'profile_id' => $profileId,
                'image_url' => $image['url'],
                'storage_url' => $image['url'],
                'alt_text' => $image['alt_text'],
                'is_primary' => $image['is_primary'],
                'sort_order' => $index,
                'status' => 'ACTIVE',
                'version' => 1,
                'created_at' => now(),
                'created_by' => $actorId,
                'updated_at' => now(),
                'updated_by' => $actorId,
            ]);
        }
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<int, array{url: string, alt_text: ?string, is_primary: bool}>>
     */
    private function imagesByProfile(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $rows = DB::table('pv_product_images')
            ->whereIn('profile_id', $profileIds)
            ->where(function ($nested): void {
                $nested->whereNull('status')->orWhere('status', 'ACTIVE');
            })
            ->orderBy('sort_order')
            ->get(['profile_id', 'image_url', 'storage_url', 'alt_text', 'is_primary']);

        $grouped = [];
        foreach ($rows as $row) {
            $url = $row->image_url ?: $row->storage_url;
            if (! $url) {
                continue;
            }
            $grouped[(string) $row->profile_id][] = [
                'url' => (string) $url,
                'alt_text' => $row->alt_text !== null ? (string) $row->alt_text : null,
                'is_primary' => (bool) $row->is_primary,
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<int, string>  $industryIds
     * @param  array<int, string>  $tagIds
     */
    private function syncProfileMappings(string $profileId, array $industryIds, array $tagIds): void
    {
        $industryTable = PvRelations::productIndustryTable();
        DB::table($industryTable)->where('profile_id', $profileId)->delete();
        foreach (array_values($industryIds) as $index => $industryId) {
            DB::table($industryTable)->insert([
                'profile_id' => $profileId,
                'industry_id' => $industryId,
                'priority' => $index + 1,
                'created_at' => now(),
            ]);
        }

        $tagTable = PvRelations::productTagTable();
        DB::table($tagTable)->where('profile_id', $profileId)->delete();
        foreach ($tagIds as $tagId) {
            // pv_product_tags (PostgreSQL) không có cột priority — chỉ ghi các cột
            // tồn tại trên CẢ hai driver.
            DB::table($tagTable)->insert([
                'profile_id' => $profileId,
                'tag_id' => $tagId,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findProfile(string $id): array
    {
        $relation = PvRelations::profile();
        $row = DB::table($relation['table'].' as p')
            ->join('sku as s', 's.sku_id', '=', 'p.'.$relation['sku'])
            ->where('p.'.$relation['key'], $id)
            ->first([
                'p.'.$relation['key'].' as profile_key',
                'p.'.$relation['sku'].' as sku_ref',
                'p.display_name', 'p.description', 'p.sort_order', 'p.version',
                's.sku_code', 's.sku_name', 's.published_to_app', 's.status as sku_status',
            ]);
        if ($row === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hồ sơ App PV.');
        }

        return [
            'id' => (string) $row->profile_key,
            'sku_id' => (string) $row->sku_ref,
            'sku_code' => (string) $row->sku_code,
            'sku_name' => (string) $row->sku_name,
            'sku_status' => (string) $row->sku_status,
            'display_name' => (string) $row->display_name,
            'description' => $row->description !== null ? (string) $row->description : null,
            'sort_order' => (int) ($row->sort_order ?? 0),
            'published' => (bool) $row->published_to_app,
            'industry_ids' => $this->mappedIds(PvRelations::productIndustryTable(), 'industry_id', [$id])[$id] ?? [],
            'tag_ids' => $this->mappedIds(PvRelations::productTagTable(), 'tag_id', [$id])[$id] ?? [],
            'images' => $this->imagesByProfile([$id])[$id] ?? [],
            'version' => (int) ($row->version ?? 1),
        ];
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<int, string>>
     */
    private function mappedIds(string $table, string $column, array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $grouped = [];
        foreach (DB::table($table)->whereIn('profile_id', $profileIds)->get(['profile_id', $column]) as $row) {
            $grouped[(string) $row->profile_id][] = (string) $row->{$column};
        }

        return $grouped;
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    private function assertKnownResource(string $resourceType): void
    {
        if (! in_array($resourceType, self::RESOURCE_TYPES, true)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'resource_type không hợp lệ.', [
                'supported' => self::RESOURCE_TYPES,
            ]);
        }
    }

    /**
     * §8.2: 428 khi thiếu precondition bắt buộc, 412 khi version lệch. Endpoint
     * này mới nên KHÔNG có client cũ để nới lỏng — If-Match là bắt buộc.
     *
     * VERSION 0 LÀ HỢP LỆ. Trước đây chỗ này đòi `>= 1` và điều đó khoá cứng
     * PATCH product-profiles trên gần như toàn bộ dữ liệu thật: hồ sơ App PV
     * KHÔNG chỉ sinh ra từ `createProfile` (đặt version = 1) mà chủ yếu do
     * trigger `hn_legacy_sku_write` tạo lúc publish SKU, và trigger dùng default
     * của cột — `version = 0`. Client đọc đúng 0 từ GET, gửi lại đúng 0, rồi
     * nhận 422 INVALID_VERSION: không có số nào gửi được. `ProductController::
     * assertPvProfileVersionMatches` (cùng cụm App PV) vốn đã nhận "số nguyên
     * KHÔNG ÂM" — hai guard cùng một tính năng nay nói cùng một luật.
     *
     * Khoá lạc quan không bị nới: header vẫn bắt buộc và vẫn phải TRÙNG version
     * hiện tại, chỉ bỏ đúng chặn "0 không phải số hợp lệ".
     */
    private function assertIfMatch(Request $request, int $currentVersion): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'PATCH yêu cầu header If-Match chứa version hiện tại.');
        }

        $header = trim(preg_replace('/^W\//', '', $header) ?? $header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }
        if ((int) $header !== $currentVersion) {
            ApiError::abort(412, 'STALE_VERSION', 'Dữ liệu đã được cập nhật bởi phiên khác; tải lại trước khi lưu.', [
                'expected_version' => (int) $header,
                'current_version' => $currentVersion,
            ]);
        }

        return $currentVersion + 1;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function nullableUuidInput(Request $request, string $key, array &$errors): ?string
    {
        $raw = $request->input($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_string($raw) || ! $this->looksLikeUuid($raw)) {
            $errors[$key] = ["{$key} phải là UUID."];

            return null;
        }

        return $raw;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<int, string>
     */
    private function uuidListInput(Request $request, string $key, array &$errors): array
    {
        $raw = $request->input($key, []);
        if ($raw === null || $raw === '') {
            return [];
        }
        if (! is_array($raw)) {
            $errors[$key] = ["{$key} phải là mảng UUID."];

            return [];
        }

        $result = [];
        foreach ($raw as $value) {
            if (! is_string($value) || ! $this->looksLikeUuid($value)) {
                $errors[$key] = ["{$key} phải là mảng UUID."];

                return [];
            }
            $result[$value] = $value;
        }

        return array_values($result);
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function assertAllActive(string $table, string $keyColumn, array $ids, string $label): void
    {
        if ($ids === []) {
            return;
        }

        $activeCount = DB::table($table)->whereIn($keyColumn, $ids)->where('status', 'ACTIVE')->count();
        if ($activeCount !== count($ids)) {
            ApiError::abort(422, 'BUSINESS_RULE_VIOLATION', "{$label} được gán phải tồn tại và đang ACTIVE.");
        }
    }

    private function adminPageSize(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 10);

        return max(1, min($perPage, 100));
    }

    private function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(Request $request, string $action, string $resourceType, string $objectId, ?array $before, ?array $after): void
    {
        AuditLogger::record(
            action: $action,
            objectType: 'pv_'.str_replace('-', '_', $resourceType),
            objectId: $objectId,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $before ?? [],
            afterData: $after ?? [],
        );
    }
}
