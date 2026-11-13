<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;
use OpenApi\Attributes as OA;

/**
 * GĐ1 recipient master backed by historical agencies table.
 * Exactly four business recipient classes are allowed.
 */
class BaM1RecipientController extends Controller
{
    use CachesReadPayload;

    public const TYPES = ['DISTRIBUTOR', 'AGENCY', 'PROJECT_CUSTOMER', 'RETAIL_CUSTOMER'];

    /**
     * Nơi nhận hàng là master data (mọi form tạo phiếu xuất đều load; ghi chỉ qua
     * store()/update() ngay trong controller này). Namespace riêng `master:version`
     * dùng chung với các danh mục khác — bump ở đây làm mới cả nhóm danh mục.
     */
    private const CACHE_VERSION_NS = 'master:version';

    #[OA\Get(
        path: '/api/v1/recipients',
        operationId: 'recipient_list',
        summary: 'Danh sách nơi nhận hàng (phân trang, filter recipient_type/market/region/status/keyword; alias /api/v1/agencies dùng chung handler)',
        security: [['bearerAuth' => []]],
        tags: ['25. Recipients'],
        parameters: [
            new OA\Parameter(name: 'recipient_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['DISTRIBUTOR', 'AGENCY', 'PROJECT_CUSTOMER', 'RETAIL_CUSTOMER'])),
            new OA\Parameter(name: 'market', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'region', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách nơi nhận hàng'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền agency.view'),
        ]
    )]
    public function index(Request $request)
    {
        $validated = $request->validate([
            'recipient_type' => ['nullable', Rule::in(self::TYPES)],
            'market' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'keyword' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        // TTL 'master' (1800s): danh mục nơi nhận thay đổi cực hiếm và mọi đường ghi đều
        // nằm trong chính controller này (store/update bump `master:version`), nên danh
        // sách không bao giờ cũ quá một nhịp ghi. Phân trang chạy TRONG SQL (forPage) nên
        // page/per_page BẮT BUỘC nằm trong khoá.
        $cached = $this->cachedReadPayload(
            'warehouse:recipient:list',
            $this->readFiltersWithPaging($request),
            function () use ($validated, $perPage, $page): array {
                $query = DB::table('agencies');
                foreach (['recipient_type', 'market', 'region', 'status'] as $field) {
                    if (! empty($validated[$field])) {
                        $query->where($field, $validated[$field]);
                    }
                }
                $term = trim((string) ($validated['keyword'] ?? ''));
                if ($term !== '') {
                    $keyword = '%'.mb_strtolower($term).'%';
                    $query->where(function ($nested) use ($keyword): void {
                        $nested->whereRaw('LOWER(agency_code) LIKE ?', [$keyword])
                            ->orWhereRaw('LOWER(agency_name) LIKE ?', [$keyword])
                            ->orWhereRaw("LOWER(COALESCE(contact_name, '')) LIKE ?", [$keyword])
                            ->orWhereRaw("LOWER(COALESCE(phone, '')) LIKE ?", [$keyword])
                            ->orWhereRaw("LOWER(COALESCE(market, '')) LIKE ?", [$keyword])
                            ->orWhereRaw("LOWER(COALESCE(region, '')) LIKE ?", [$keyword]);
                    });
                }

                return [
                    'total' => (clone $query)->count(),
                    'rows' => $query->orderBy('agency_name')->forPage($page, $perPage)->get()->all(),
                ];
            },
            'master',
            self::CACHE_VERSION_NS,
        );

        $total = (int) $cached['total'];

        // `meta.request_id` dựng NGOÀI cache để log truy vết còn dùng được.
        return response()->json([
            'success' => true,
            'data' => $cached['rows'],
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'recipient_types' => self::TYPES,
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/recipients',
        operationId: 'recipient_create',
        summary: 'Tạo nơi nhận hàng mới (alias /api/v1/agencies dùng chung handler)',
        security: [['bearerAuth' => []]],
        tags: ['25. Recipients'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['agency_code', 'agency_name', 'recipient_type'],
                properties: [
                    new OA\Property(property: 'agency_code', type: 'string', maxLength: 50, example: 'HN-DIST-001'),
                    new OA\Property(property: 'agency_name', type: 'string', maxLength: 255, example: 'Nhà phân phối Hoa Nam Miền Bắc'),
                    new OA\Property(property: 'recipient_type', type: 'string', enum: ['DISTRIBUTOR', 'AGENCY', 'PROJECT_CUSTOMER', 'RETAIL_CUSTOMER']),
                    new OA\Property(property: 'market', type: 'string', maxLength: 120, nullable: true),
                    new OA\Property(property: 'region', type: 'string', maxLength: 120, nullable: true),
                    new OA\Property(property: 'contact_name', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, pattern: '^0\\d{9}$'),
                    new OA\Property(property: 'address', type: 'string', maxLength: 500, nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, default: 'ACTIVE'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo nơi nhận hàng thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền agency.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: agency_code đã tồn tại, phone sai định dạng)'),
        ]
    )]
    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, null, false);
        $id = (string) Str::uuid();
        $now = now();
        DB::table('agencies')->insert([
            'agency_id' => $id,
            'agency_code' => $validated['agency_code'],
            'agency_name' => $validated['agency_name'],
            'recipient_type' => $validated['recipient_type'],
            'market' => $validated['market'] ?? null,
            'region' => $validated['region'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'status' => $validated['status'] ?? 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $after = DB::table('agencies')->where('agency_id', $id)->first();
        $this->audit('RECIPIENT_CREATE', $id, $request, null, $after);

        // Bump SAU commit: danh sách/chi tiết nơi nhận phải thấy bản ghi mới ngay.
        DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));
        $this->invalidateReportReadCache();

        return response()->json(['success' => true, 'data' => $after], 201);
    }

    #[OA\Get(
        path: '/api/v1/recipients/{id}',
        operationId: 'recipient_detail',
        summary: 'Chi tiết nơi nhận hàng (alias /api/v1/agencies/{id} dùng chung handler)',
        security: [['bearerAuth' => []]],
        tags: ['25. Recipients'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết nơi nhận hàng'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy đối tượng nhận hàng'),
        ]
    )]
    public function show(string $id)
    {
        // Cùng TTL/namespace với index(): id nằm trong khoá thay cho query string vì
        // route param không đi qua $request->query(). 404 cũng được cache — an toàn vì
        // store() bump `master:version` nên bản ghi mới tạo hiện ra ngay.
        $cached = $this->cachedReadPayload(
            'warehouse:recipient:detail',
            ['agency_id' => $id],
            fn (): array => ['row' => DB::table('agencies')->where('agency_id', $id)->first()],
            'master',
            self::CACHE_VERSION_NS,
        );

        if (($cached['row'] ?? null) === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy đối tượng nhận hàng.');
        }

        return response()->json(['success' => true, 'data' => $cached['row']]);
    }

    #[OA\Patch(
        path: '/api/v1/recipients/{id}',
        operationId: 'recipient_update',
        summary: 'Cập nhật nơi nhận hàng (alias /api/v1/agencies/{id} dùng chung handler)',
        description: 'Header `If-Match` (version) là optimistic locking TÙY CHỌN — chỉ được kiểm tra khi client có '
            .'gửi header này. `agency_code` là bất biến một khi đã dùng trên chứng từ (422 AGENCY_CODE_IMMUTABLE).',
        security: [['bearerAuth' => []]],
        tags: ['25. Recipients'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'agency_code', type: 'string', maxLength: 50),
                    new OA\Property(property: 'agency_name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'recipient_type', type: 'string', enum: ['DISTRIBUTOR', 'AGENCY', 'PROJECT_CUSTOMER', 'RETAIL_CUSTOMER']),
                    new OA\Property(property: 'market', type: 'string', maxLength: 120, nullable: true),
                    new OA\Property(property: 'region', type: 'string', maxLength: 120, nullable: true),
                    new OA\Property(property: 'contact_name', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, pattern: '^0\\d{9}$'),
                    new OA\Property(property: 'address', type: 'string', maxLength: 500, nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật nơi nhận hàng thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền agency.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy đối tượng nhận hàng'),
            new OA\Response(response: 412, description: 'STALE_VERSION — If-Match không khớp version hiện tại'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ, hoặc đổi agency_code đã dùng trên chứng từ (AGENCY_CODE_IMMUTABLE)'),
        ]
    )]
    public function update(Request $request, string $id)
    {
        $before = DB::table('agencies')->where('agency_id', $id)->first();
        if ($before === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy đối tượng nhận hàng.');
        }
        $validated = $this->validatePayload($request, $id, true);
        // Annex C updateAgency + ERD agencies.version: If-Match là optimistic locking,
        // chỉ enforce khi client gửi header (parity với AgencyController để việc chuyển
        // handler runtime không làm mất guard).
        $this->assertVersionIfProvided($request, $before);
        $this->assertCodeImmutableAfterUse($id, $validated, $before);
        if ($validated !== []) {
            $validated['version'] = (int) ($before->version ?? 0) + 1;
            $validated['updated_at'] = now();
            DB::table('agencies')->where('agency_id', $id)->update($validated);
        }
        $after = DB::table('agencies')->where('agency_id', $id)->first();
        $this->audit('RECIPIENT_UPDATE', $id, $request, $before, $after);

        // Cùng namespace với index()/show(): sửa xong là đọc ra bản mới, không chờ TTL.
        DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));
        $this->invalidateReportReadCache();

        return response()->json(['success' => true, 'data' => $after]);
    }

    /**
     * agency_name/agency_code của nơi nhận còn nằm trong payload của các read cache
     * khoá theo 'stock:version' (báo cáo xuất theo người nhận, nhật ký nhãn/tem),
     * không chỉ riêng cache master của chính danh mục này.
     */
    private function invalidateReportReadCache(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));
    }

    private function assertVersionIfProvided(Request $request, object $current): void
    {
        $header = (string) $request->header('If-Match', '');
        if ($header === '') {
            return;
        }
        $header = trim(preg_replace('/^W\//', '', $header) ?? $header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên.');
        }
        if ((int) $header !== (int) ($current->version ?? 0)) {
            ApiError::abort(412, 'STALE_VERSION', 'Đối tượng nhận hàng đã được cập nhật bởi phiên khác; tải lại trước khi lưu.', [
                'expected_version' => (int) $header,
                'current_version' => (int) ($current->version ?? 0),
            ]);
        }
    }

    /**
     * BR-AGY: mã nơi nhận đã xuất hiện trên chứng từ là bất biến — đổi mã sẽ làm
     * lịch sử phiếu xuất trỏ sai định danh.
     */
    private function assertCodeImmutableAfterUse(string $id, array $validated, object $before): void
    {
        $newCode = $validated['agency_code'] ?? null;
        if ($newCode === null || (string) $newCode === (string) $before->agency_code) {
            return;
        }
        $used = DB::table('stock_documents')->where('agency_id', $id)->exists()
            || DB::table('outbound_requests')->where('agency_id', $id)->exists();
        if ($used) {
            ApiError::abort(422, 'AGENCY_CODE_IMMUTABLE', 'Không thể đổi mã nơi nhận đã được dùng trên chứng từ.');
        }
    }

    private function validatePayload(Request $request, ?string $id, bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'agency_code' => [
                $required, 'string', 'max:50',
                Rule::unique('agencies', 'agency_code')->ignore($id, 'agency_id'),
            ],
            'agency_name' => [$required, 'string', 'max:255'],
            'recipient_type' => [$required, Rule::in(self::TYPES)],
            'market' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^0\d{9}$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
    }

    private function audit(string $action, string $id, Request $request, mixed $before, mixed $after): void
    {
        AuditLogger::record(
            action: $action,
            objectType: 'recipient',
            objectId: $id,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            beforeData: $before === null ? [] : (array) $before,
            afterData: $after === null ? [] : (array) $after,
        );
    }
}
