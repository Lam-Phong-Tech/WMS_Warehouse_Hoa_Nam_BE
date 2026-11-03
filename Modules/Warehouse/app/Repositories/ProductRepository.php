<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;

class ProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    protected array $searchable = ['sku_code', 'sku_name'];

    protected array $filterable = ['status', 'sku_type', 'brand_id'];

    protected bool $cacheEnabled = true;

    protected string $cacheDomain = 'warehouse.sku';

    protected string $cacheTtlPolicy = 'sku';

    public function __construct(Sku $model)
    {
        parent::__construct($model);
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        unset($filters['keyword']);

        $query = parent::applyFilters($query, $filters);

        if ($keyword !== '') {
            $operator = $this->likeOperator($query);
            $query->where(function (Builder $skuQuery) use ($keyword, $operator) {
                $skuQuery->where('sku_code', $operator, "%{$keyword}%")
                    ->orWhere('sku_name', $operator, "%{$keyword}%")
                    ->orWhereHas('brand', function (Builder $brandQuery) use ($keyword, $operator) {
                        $brandQuery->where('brand_name', $operator, "%{$keyword}%");
                    });
            });
        }

        return $query;
    }
}
