<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
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
        $item = $this->resolveItem($rawCode);
        $sku = $item !== null ? DB::table('sku')->where('sku_id', $item->sku_id)->first() : null;
        $activeWarrantyExists = $item !== null && DB::table('warranty_case')
            ->where('item_id', $item->item_id)
            ->whereNotIn('status', ['RETURNED', 'CANCELLED'])
            ->exists();
        $eligible = $item !== null
            && in_array((string) $item->item_status, ['ISSUED', 'RETURNED_TO_CUSTOMER'], true)
            && ! $activeWarrantyExists;

        return $this->respondSuccess([
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
        ], $eligible
            ? 'Mã hợp lệ để tiếp nhận bảo hành định danh; chưa tạo hồ sơ WMS.'
            : 'Không đủ điều kiện định danh tự động; Mini App có thể đi nhánh TEMP_ONLY nếu nghiệp vụ cho phép.');
    }

    public function index(Request $request)
    {
        return $this->canonical->listWarrantyCases($request);
    }

    public function store(Request $request)
    {
        return $this->warranty->storeWarrantyCase($request);
    }

    public function show(Request $request, string $id)
    {
        return $this->warranty->showWarrantyCase($request, $id);
    }

    public function update(Request $request, string $id)
    {
        return $this->warranty->updateWarrantyCase($request, $id);
    }

    public function events(Request $request, string $id)
    {
        return $this->canonical->listWarrantyEvents($request, $id);
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
