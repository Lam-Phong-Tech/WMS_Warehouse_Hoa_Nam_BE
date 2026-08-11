<?php

namespace App\Repositories;

use App\Repositories\Contracts\RepositoryInterface;
use App\Support\ApiError;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

abstract class BaseRepository implements RepositoryInterface
{
    /**
     * Các cột cho phép tìm kiếm với tham số `keyword` (LIKE).
     *
     * @var array<int, string>
     */
    protected array $searchable = [];

    /**
     * Các cột cho phép lọc bằng so khớp chính xác (vd: status, warehouse_id).
     *
     * @var array<int, string>
     */
    protected array $filterable = [];

    /**
     * Quan hệ eager-load mặc định.
     *
     * @var array<int, string>
     */
    protected array $with = [];

    public function __construct(protected Model $model) {}

    public function all(): Collection
    {
        return $this->query()->get();
    }

    /**
     * Nguồn truy vấn DUY NHẤT cho model này — mọi thao tác đọc/ghi phải đi qua đây,
     * không gọi thẳng `Model::where()`/`$this->model->` ở nơi khác (bài học từ ERP-Backend
     * gốc: bug thật khi 1 chỗ code quên áp dụng cùng 1 nguồn truy vấn).
     */
    public function query(): Builder
    {
        return $this->model->newQuery();
    }

    public function paginate(array $filters = [], int $perPage = 15, ?\Closure $scope = null): LengthAwarePaginator
    {
        $query = $this->query();
        if ($scope !== null) {
            $query = $scope($query);
        }

        return $this->applyFilters($query, $filters)->paginate(min(max($perPage, 1), 100));
    }

    public function trashed(array $filters = [], int $perPage = 15, ?\Closure $scope = null): LengthAwarePaginator
    {
        $query = $this->query()->onlyTrashed();
        if ($scope !== null) {
            $query = $scope($query);
        }

        return $this->applyFilters($query, $filters)->paginate(min(max($perPage, 1), 100));
    }

    public function find(int|string $id): ?Model
    {
        $query = $this->query();

        if (! empty($this->with)) {
            $query->with($this->with);
        }

        return $query->find($id);
    }

    public function create(array $data): Model
    {
        [$data, $stripped] = $this->dropNullsForNonNullable($data);

        $record = $this->model->create($data);

        // Nếu đã bỏ field null cho cột NOT NULL → nạp lại để response phản ánh đúng default DB.
        if ($stripped) {
            $record->refresh();
        }

        return $record;
    }

    public function update(int|string $id, array $data): ?Model
    {
        $record = $this->query()->find($id);

        if (! $record) {
            return null;
        }

        // Bỏ null cho cột NOT NULL → giữ nguyên giá trị cũ thay vì gây not-null violation.
        [$data] = $this->dropNullsForNonNullable($data);

        $record->update($data);

        return $record;
    }

    /**
     * Bỏ các giá trị `null` thuộc cột NOT NULL để DB tự điền default. Cột nullable giữ
     * nguyên null. Tránh lỗi "not-null violation" khi client gửi null tường minh.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: bool} [dữ liệu đã làm sạch, có bỏ field nào không]
     */
    protected function dropNullsForNonNullable(array $data): array
    {
        $stripped = false;

        foreach ($this->nonNullableColumns() as $column) {
            if (array_key_exists($column, $data) && $data[$column] === null) {
                unset($data[$column]);
                $stripped = true;
            }
        }

        return [$data, $stripped];
    }

    /**
     * Danh sách cột NOT NULL của bảng (cache theo bảng trong vòng đời request).
     *
     * @return array<int, string>
     */
    protected function nonNullableColumns(): array
    {
        static $cache = [];

        $table = $this->model->getTable();

        if (! array_key_exists($table, $cache)) {
            $cache[$table] = collect(
                Schema::connection($this->model->getConnectionName())->getColumns($table)
            )->reject(fn ($col) => $col['nullable'])->pluck('name')->all();
        }

        return $cache[$table];
    }

    public function delete(int|string $id): bool
    {
        $record = $this->query()->find($id);

        return $record ? (bool) $record->delete() : false;
    }

    public function restore(int|string $id): bool
    {
        $record = $this->query()->onlyTrashed()->find($id);

        return $record ? (bool) $record->restore() : false;
    }

    public function forceDelete(int|string $id): bool
    {
        $record = $this->query()->withTrashed()->find($id);

        return $record ? (bool) $record->forceDelete() : false;
    }

    /**
     * Toán tử LIKE không phân biệt hoa/thường, chọn theo driver đang chạy: `ilike` trên
     * Postgres, `like` trên các driver khác (dùng cho test sqlite).
     */
    protected function likeOperator(Builder $query): string
    {
        return $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * Áp dụng eager-load, tìm kiếm và lọc vào query.
     *
     * Chuẩn duy nhất: `keyword` (search), `sort_by`+`sort_order`, `page`/`per_page`.
     * Không hỗ trợ alias tương thích ngược (`q`, `limit`, `sort=-field`).
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($this->with)) {
            $query->with($this->with);
        }

        $search = $filters['keyword'] ?? null;
        if ($search !== null && $search !== '' && ! empty($this->searchable)) {
            $op = $this->likeOperator($query);
            $query->where(function (Builder $q) use ($search, $op) {
                foreach ($this->searchable as $column) {
                    $q->orWhere($column, $op, "%{$search}%");
                }
            });
        }

        foreach ($this->filterable as $column) {
            if (array_key_exists($column, $filters) && $filters[$column] !== null && $filters[$column] !== '' && $filters[$column] !== 'all') {
                $value = $filters[$column];

                if (($column === 'id' || str_ends_with($column, '_id')) && ! is_numeric($value)) {
                    ApiError::abort(422, 'VALIDATION_ERROR', "Giá trị lọc '{$column}' phải là số nguyên.");
                }

                $query->where($column, $value);
            }
        }

        $columns = $this->getTableColumns();

        if (! empty($filters['sort_by']) && in_array($filters['sort_by'], $columns, true)) {
            $dir = strtolower($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $query->orderBy($filters['sort_by'], $dir);
        } elseif (in_array('id', $columns, true)) {
            $query->orderBy($this->model->getTable().'.id', 'desc');
        } elseif (in_array('created_at', $columns, true)) {
            $query->orderBy($this->model->getTable().'.created_at', 'desc');
        }

        return $query;
    }

    /**
     * Lấy danh sách cột của bảng để kiểm tra tính hợp lệ trước khi order.
     *
     * @return array<int, string>
     */
    protected function getTableColumns(): array
    {
        static $columnsCache = [];
        $table = $this->model->getTable();

        if (! isset($columnsCache[$table])) {
            $columnsCache[$table] = Schema::connection($this->model->getConnectionName())->getColumnListing($table);
        }

        return $columnsCache[$table];
    }
}
