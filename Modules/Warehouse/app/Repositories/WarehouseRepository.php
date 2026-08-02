<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use App\Support\ApiError;
use Illuminate\Database\Eloquent\Model;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Repositories\Contracts\WarehouseRepositoryInterface;

class WarehouseRepository extends BaseRepository implements WarehouseRepositoryInterface
{
    protected array $searchable = ['code', 'name'];

    protected array $filterable = ['status'];

    public function __construct(Warehouse $model)
    {
        parent::__construct($model);
    }

    /**
     * Guard tầng ứng dụng: chỉ 1 warehouse ACTIVE tại một thời điểm. Bổ sung cho partial
     * unique index ở migration 2026_08_02_000001 (index đó chỉ chạy trên Postgres — SQLite
     * dùng cho test suite không hỗ trợ cùng cú pháp, nên guard phải verify được ở cả 2 nơi).
     */
    public function create(array $data): Model
    {
        $this->guardSingleActive($data);

        return parent::create($data);
    }

    public function update(int|string $id, array $data): ?Model
    {
        $this->guardSingleActive($data, excludeId: $id);

        return parent::update($id, $data);
    }

    protected function guardSingleActive(array $data, int|string|null $excludeId = null): void
    {
        if (($data['status'] ?? null) !== 'ACTIVE') {
            return;
        }

        $exists = $this->query()
            ->where('status', 'ACTIVE')
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ được phép có 1 kho tổng đang ACTIVE.');
        }
    }
}
