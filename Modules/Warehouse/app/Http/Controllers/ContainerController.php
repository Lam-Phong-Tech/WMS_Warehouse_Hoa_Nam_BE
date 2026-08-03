<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Services\ContainerService;

class ContainerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ContainerService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
        $query = DB::table('container')->whereNull('deleted_at')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', strtoupper(trim((string) $request->input('status'))));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('keyword')) {
            $keyword = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->input('keyword'))).'%';
            $query->where(function ($nested) use ($keyword): void {
                $nested->where('container_code', 'like', $keyword)
                    ->orWhere('container_type', 'like', $keyword)
                    ->orWhere('zone_code', 'like', $keyword);
            });
        }

        return $this->respondPaginated($query->paginate($perPage));
    }

    public function show(string $id): JsonResponse
    {
        $data = $this->service->snapshot($id);
        if ($data === []) {
            return $this->respondNotFound('Không tìm thấy container.');
        }

        return $this->respondSuccess($data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, false);

        return $this->idempotent(
            $request,
            'container_create',
            fn (): array => [
                201,
                $this->envelope(
                    'Tạo container thành công.',
                    $this->service->create($validated, $this->actorId(), $this->requestId($request))
                ),
            ]
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $this->validatePayload($request, true);
        $expectedVersion = $this->expectedVersion($request);

        return $this->idempotent(
            $request,
            "container_update_{$id}",
            fn (): array => [
                200,
                $this->envelope(
                    'Cập nhật container thành công.',
                    $this->service->update(
                        $id,
                        $validated,
                        $expectedVersion,
                        $this->actorId(),
                        $this->requestId($request)
                    )
                ),
            ]
        );
    }

    public function seal(Request $request, string $id): JsonResponse
    {
        $request->validate(['version' => ['sometimes', 'integer', 'min:1']]);
        $expectedVersion = $this->expectedVersion($request);

        return $this->idempotent(
            $request,
            "container_seal_{$id}",
            fn (): array => [
                200,
                $this->envelope(
                    'Seal container thành công.',
                    $this->service->seal(
                        $id,
                        $expectedVersion,
                        $this->actorId(),
                        $this->requestId($request)
                    )
                ),
            ]
        );
    }

    private function validatePayload(Request $request, bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'container_code' => [$required, 'string', 'max:150'],
            'container_type' => [$required, Rule::in(['PALLET', 'CARTON', 'BOX', 'TRAY', 'BIN', 'BAG', 'OTHER'])],
            'parent_container_id' => ['sometimes', 'nullable', 'uuid'],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid', 'exists:warehouses,id'],
            'primary_product_id' => ['sometimes', 'nullable', 'uuid', 'exists:products,id'],
            'physical_code_id' => ['sometimes', 'nullable', 'uuid', 'exists:physical_code,physical_code_id'],
            'zone_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'location_hint' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
            'version' => ['sometimes', 'integer', 'min:1'],
            'items' => [$partial ? 'sometimes' : 'present', 'array', 'max:1000'],
            'items.*.inventory_serial_id' => ['nullable', 'uuid'],
            'items.*.product_id' => ['nullable', 'uuid'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);
    }

    private function idempotent(Request $request, string $operation, callable $callback): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc.');
        }
        if (mb_strlen($key) > 128) {
            ApiError::abort(422, 'INVALID_IDEMPOTENCY_KEY', 'Idempotency-Key tối đa 128 ký tự.');
        }

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            $operation,
            $this->actorId(),
            IdempotencyStore::requestHash($request),
            $callback
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header !== '') {
            $header = preg_replace('/^W\//', '', $header) ?? $header;
            $header = trim($header, '" ');
            if (ctype_digit($header) && (int) $header > 0) {
                return (int) $header;
            }
            ApiError::abort(422, 'INVALID_IF_MATCH', 'If-Match phải chứa version số nguyên dương.');
        }

        $version = $request->input('version');
        if (is_numeric($version) && (int) $version > 0) {
            return (int) $version;
        }

        ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match hoặc field version là bắt buộc.');
    }

    private function actorId(): ?string
    {
        $id = auth('api')->id();

        return $id === null ? null : (string) $id;
    }

    private function requestId(Request $request): ?string
    {
        $id = $request->attributes->get('request_id');

        return is_string($id) ? $id : null;
    }

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'request_id' => request()->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
