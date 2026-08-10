<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CurrentBaselineMasterController extends Controller
{
    use ApiResponse;

    public function listBrands(Request $request)
    {
        return $this->page($request, 'brands');
    }

    public function listProductCategories(Request $request)
    {
        return $this->page($request, 'product_categories');
    }

    public function listPowerSources(Request $request)
    {
        return $this->page($request, 'power_sources');
    }

    public function listProductModels(Request $request)
    {
        return $this->page($request, 'product_models');
    }

    public function listPackageVariants(Request $request)
    {
        return $this->page($request, 'package_variants');
    }

    public function storeBrand(Request $request)
    {
        return $this->storeSimpleMaster($request, 'brands', 'brand_code', 'brand_name');
    }

    public function storeProductCategory(Request $request)
    {
        return $this->storeSimpleMaster($request, 'product_categories', 'category_code', 'category_name');
    }

    public function storePowerSource(Request $request)
    {
        return $this->storeSimpleMaster($request, 'power_sources', 'power_source_code', 'power_source_name');
    }

    public function storeProductModel(Request $request)
    {
        $validated = $request->validate([
            'model_code' => ['required', 'string', 'max:80', 'unique:product_models,model_code'],
            'model_name' => ['required', 'string', 'max:200'],
            'brand_id' => ['nullable', 'uuid', 'exists:brands,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! empty($validated['brand_id'])) {
            $active = DB::table('brands')->where('id', $validated['brand_id'])->where('status', 'ACTIVE')->exists();
            if (! $active) {
                ApiError::abort(422, 'BRAND_NOT_ACTIVE', 'Product model chỉ được tham chiếu Brand ACTIVE.');
            }
        }

        return $this->insert('product_models', $validated);
    }

    public function storePackageVariant(Request $request)
    {
        $validated = $request->validate([
            'package_code' => ['required', 'string', 'max:80', 'unique:package_variants,package_code'],
            'package_name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (Schema::hasColumn('package_variants', 'variant_code')) {
            $validated['variant_code'] = $validated['package_code'];
        }
        if (Schema::hasColumn('package_variants', 'variant_name')) {
            $validated['variant_name'] = $validated['package_name'];
        }

        return $this->insert('package_variants', $validated);
    }

    private function storeSimpleMaster(Request $request, string $table, string $code, string $name)
    {
        $validated = $request->validate([
            $code => ['required', 'string', 'max:80', "unique:{$table},{$code}"],
            $name => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->insert($table, $validated);
    }

    private function insert(string $table, array $data)
    {
        $id = (string) Str::uuid();
        $row = [
            'id' => $id,
            ...$data,
            'status' => $data['status'] ?? 'ACTIVE',
            'sort_order' => $data['sort_order'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        foreach (['created_by', 'updated_by', 'version'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $row[$column] = $column === 'version' ? 0 : $this->actorId();
            }
        }
        DB::table($table)->insert($row);

        return $this->respondCreated(DB::table($table)->where('id', $id)->first(), 'Created.');
    }

    private function page(Request $request, string $table)
    {
        $query = DB::table($table);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->respondPaginated($query->orderBy('sort_order')->orderBy('created_at')->paginate((int) $request->input('per_page', 50)));
    }

    private function actorId(): ?string
    {
        $id = auth('api')->id();

        return $id === null ? null : (string) $id;
    }
}
