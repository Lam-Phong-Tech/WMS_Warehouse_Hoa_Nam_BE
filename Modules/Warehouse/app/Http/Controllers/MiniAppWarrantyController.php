<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Services\ProductIdentityResolver;
use Modules\Warehouse\Services\ProductQrPayloadParser;

/**
 * Mini App warranty adapter.
 *
 * Warranty case state remains canonical. This controller only adds the mobile
 * preflight resolve endpoint and delegates case mutations/reads to the WMS
 * warranty controllers so Mini App does not own a second warranty lifecycle.
 */
class MiniAppWarrantyController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    /**
     * Máy quét đọc — TTL ngắn nhất và bám `stock:version` như hai adapter Mini App kia.
     */
    private const CACHE_VERSION_NS = 'stock:version';

    public function __construct(
        private readonly ProductQrPayloadParser $parser,
        private readonly ProductIdentityResolver $identityResolver,
        private readonly CanonicalMvpController $canonical,
        private readonly CurrentBaselineWarrantyController $warranty,
    ) {}

    public function resolveCode(Request $request)
    {
        $validated = $request->validate([
            'raw_code' => ['required', 'string', 'min:1', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
        ]);

        $rawCode = trim((string) $validated['raw_code']);

        // Endpoint POST nhưng thuần ĐỌC (stock_effect=NONE, không tạo bản ghi nào). Vì là
        // POST, `raw_code` nằm trong body chứ không phải query string nên PHẢI đưa vào
        // khoá thủ công — dùng readFiltersWithPaging() ở đây là để hai mã khác nhau dùng
        // chung một entry. TTL 'stock_short' (20s) + `stock:version`: item_status đổi qua
        // mutation tồn kho (đã bump), còn hồ sơ bảo hành tạo/sửa từ chính controller này
        // cũng bump. Đây chỉ là gợi ý tiền kiểm — lệnh tạo hồ sơ thật vẫn validate lại
        // trên dữ liệu tươi ở CurrentBaselineWarrantyController.
        $payload = $this->cachedReadPayload(
            'miniapp:warranty:resolve-code',
            ['raw_code' => $rawCode],
            fn (): array => $this->buildResolveCodePayload($rawCode),
            'stock_short',
            self::CACHE_VERSION_NS,
        );

        return $this->respondSuccess($payload, $payload['eligible_for_warranty']
            ? 'Mã hợp lệ để tiếp nhận bảo hành định danh; chưa tạo hồ sơ WMS.'
            : 'Không đủ điều kiện định danh tự động; Mini App có thể đi nhánh TEMP_ONLY nếu nghiệp vụ cho phép.');
    }

    /** @return array<string, mixed> */
    private function buildResolveCodePayload(string $rawCode): array
    {
        $item = $this->resolveItem($rawCode);
        $sku = $item !== null ? DB::table('sku')->where('sku_id', $item->sku_id)->first() : null;
        $activeWarrantyExists = $item !== null && DB::table('warranty_case')
            ->where('item_id', $item->item_id)
            ->whereNotIn('status', ['RETURNED', 'CANCELLED'])
            ->exists();
        $eligible = $item !== null
            && in_array((string) $item->item_status, ['ISSUED', 'RETURNED_TO_CUSTOMER'], true)
            && ! $activeWarrantyExists;

        return [
            'raw_code' => $rawCode,
            'resolved' => $item !== null,
            'item_id' => $item?->item_id,
            'item_code' => $item?->serial_number,
            'serial_number' => $item?->serial_number,
            'item_status' => $item?->item_status,
            'sku_id' => $sku?->sku_id,
            'sku_code' => $sku?->sku_code,
            'sku_name' => $sku?->sku_name,
            'eligible_for_warranty' => $eligible,
            'eligibility_code' => $this->eligibilityCode($item, $activeWarrantyExists),
            'active_warranty_exists' => $activeWarrantyExists,
            'recommended_flow' => $eligible ? 'IDENTIFIED' : 'TEMP_ONLY',
            'stock_effect' => 'NONE',
            'movement_created' => false,
            'wms_case_created' => false,
        ];
    }

    /**
     * KHÔNG cache ở đây: bốn phương thức dưới chỉ uỷ quyền và nhận về một JsonResponse đã
     * đóng gói envelope. Cache một response object là vi phạm quy tắc "chỉ cache mảng dữ
     * liệu, không cache response" (request_id/timestamp sẽ bị đóng băng theo), và muốn
     * cache đúng chỗ thì phải sửa CanonicalMvpController/CurrentBaselineWarrantyController
     * — hai file nằm ngoài phạm vi thay đổi này.
     */
    public function index(Request $request)
    {
        return $this->canonical->listWarrantyCases($request);
    }

    public function store(Request $request)
    {
        $response = $this->warranty->storeWarrantyCase($request);
        // Hồ sơ mới làm `active_warranty_exists` của resolveCode() đổi ngay lập tức.
        $this->invalidateAfterWrite();

        return $response;
    }

    public function show(Request $request, string $id)
    {
        return $this->warranty->showWarrantyCase($request, $id);
    }

    public function update(Request $request, string $id)
    {
        $response = $this->warranty->updateWarrantyCase($request, $id);
        // Đổi status hồ sơ (vd. sang RETURNED/CANCELLED) làm đổi kết quả tiền kiểm.
        $this->invalidateAfterWrite();

        return $response;
    }

    public function events(Request $request, string $id)
    {
        return $this->canonical->listWarrantyEvents($request, $id);
    }

    /**
     * Lệnh uỷ quyền đã commit khi trả về nên callback của DB::afterCommit chạy ngay; nếu
     * còn transaction mở thì nó tự chờ commit — không bao giờ nạp lại cache từ dữ liệu
     * chưa commit.
     */
    private function invalidateAfterWrite(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));
    }

    private function resolveItem(string $rawCode): ?object
    {
        if ($this->parser->looksComposite($rawCode)) {
            $payload = $this->parser->parse($rawCode);
            if (! $payload->isValid() || $payload->skuCode === null || $payload->itemUnique === null) {
                return null;
            }
            $sku = $this->identityResolver->requireActiveSkuByCode($payload->skuCode);
            $item = $this->identityResolver->findItemByUnique($payload->itemUnique);
            if ($item === null) {
                return null;
            }
            $this->identityResolver->assertItemMatchesSku($item, $sku, $payload);

            return $item;
        }

        $normalized = strtoupper($rawCode);
        $item = DB::table('item_instances')->where('serial_number', $rawCode)->first()
            ?? DB::table('item_instances')->where('serial_number', $normalized)->first();
        if ($item !== null) {
            return $item;
        }

        $physical = DB::table('physical_codes')
            ->where(function ($query) use ($rawCode, $normalized): void {
                $query->where('code_value', $rawCode)->orWhere('code_value', $normalized);
            })
            ->where('status', 'ACTIVE')
            ->first();
        if ($physical === null) {
            return null;
        }

        $itemId = ($physical->assigned_object_type ?? null) === 'ITEM' && ! empty($physical->assigned_object_id)
            ? (string) $physical->assigned_object_id
            : DB::table('item_instances')->where('physical_code_id', $physical->physical_code_id)->value('item_id');

        return $itemId !== null ? DB::table('item_instances')->where('item_id', $itemId)->first() : null;
    }

    private function eligibilityCode(?object $item, bool $activeWarrantyExists): string
    {
        if ($item === null) {
            return 'ITEM_NOT_FOUND';
        }
        if ($activeWarrantyExists) {
            return 'ACTIVE_WARRANTY_EXISTS';
        }
        if (! in_array((string) $item->item_status, ['ISSUED', 'RETURNED_TO_CUSTOMER'], true)) {
            return 'ITEM_NOT_ISSUED';
        }

        return 'ELIGIBLE';
    }
}
