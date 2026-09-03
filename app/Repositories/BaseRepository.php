<?php

namespace App\Repositories;

use App\Repositories\Contracts\RepositoryInterface;
use App\Support\ApiError;
use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheService;
use App\Support\Cache\CacheTtl;
use App\Support\Cache\CacheVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

    /**
     * Bật cache cho paginate()/find() của repo con này — mặc định TẮT (false) để
     * không đổi hành vi các repo hiện có (đặc biệt các repo động chạm dữ liệu tồn
     * kho/correctness-sensitive không được opt-in). Chỉ set true ở repo con là
     * master-data/lookup ổn định (mục 6/34 — audit trước khi bật, không cache chỉ vì
     * query chậm).
     */
    protected bool $cacheEnabled = false;

    /** Domain namespace cho cache key, vd 'auth.role', 'warehouse.agency'. */
    protected string $cacheDomain = '';

    /** Policy TTL trong config/wms_cache.php (ttl.*), đọc qua App\Support\Cache\CacheTtl. */
    protected string $cacheTtlPolicy = 'master';

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
        $perPage = min(max($perPage, 1), 100);

        // $scope là closure tuỳ ý (thường mang theo warehouse-scope/user-context không
        // thể băm an toàn thành cache key) — không cache khi có scope để tránh leak dữ
        // liệu chéo scope (mục 22). Caller cần cache theo scope tự làm ở tầng controller
        // với CacheKey::scopeSignature().
        if (! $this->cacheEnabled || $scope !== null) {
            return $this->paginateFresh($filters, $perPage, $scope);
        }

        $currentPage = (int) request()->integer('page', 1);
        $key = CacheKey::of($this->cacheDomain.':list', [
            'f' => CacheKey::hashFilters($filters),
            'per_page' => $perPage,
            'page' => $currentPage,
        ], CacheVersion::current($this->cacheDomain.':list'));

        // Chỉ cache items()+total() (dữ liệu thô), KHÔNG cache cả LengthAwarePaginator —
        // paginator mang path/query của request lúc build cache, dùng cache hit ở 1
        // request khác (URL/query string khác) sẽ sinh link phân trang (next/prev) sai.
        // Paginator luôn được dựng lại mới từ path/query của request hiện tại.
        //
        // Items được xuống cache dưới dạng ARRAY thuần (attributes thô, không phải
        // Eloquent Model) — config/cache.php có `serializable_classes = false`
        // (chặn unserialize object để tránh gadget-chain attack nếu APP_KEY lộ), nên
        // cache Model object trực tiếp sẽ bị Laravel âm thầm trả về
        // `__PHP_Incomplete_Class` rỗng ở lần cache-hit (mất hết dữ liệu, không lỗi
        // rõ ràng). Rehydrate lại Model thật từ array ở dưới, KHÔNG nới lỏng cấu hình
        // bảo mật này.
        $cached = $this->cacheService()->rememberStampede(
            $key,
            CacheTtl::seconds($this->cacheTtlPolicy),
            function () use ($filters, $perPage, $scope): array {
                $paginator = $this->paginateFresh($filters, $perPage, $scope);

                return [
                    'items' => array_map(fn (Model $item) => $this->serializeItemForCache($item), $paginator->items()),
                    'total' => $paginator->total(),
                ];
            }
        );

        $items = array_map(fn (array $data) => $this->hydrateItemFromCache($data), $cached['items']);

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $cached['total'],
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    protected function paginateFresh(array $filters, int $perPage, ?\Closure $scope): LengthAwarePaginator
    {
        $query = $this->query();
        if ($scope !== null) {
            $query = $scope($query);
        }

        return $this->applyFilters($query, $filters)->paginate($perPage);
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
        if (! $this->cacheEnabled) {
            return $this->findFresh($id);
        }

        // Xem ghi chú trong paginate(): cache attributes thô (array), không cache
        // Model object trực tiếp — tránh __PHP_Incomplete_Class do
        // config('cache.serializable_classes') = false.
        $cached = $this->cacheService()->remember(
            $this->detailCacheKey($id),
            CacheTtl::seconds($this->cacheTtlPolicy),
            function () use ($id) {
                $model = $this->findFresh($id);

                return $model ? $this->serializeItemForCache($model) : null;
            }
        );

        return $cached ? $this->hydrateItemFromCache($cached) : null;
    }

    /**
     * Chuyển 1 Model (kèm relation trong $this->with, nếu có) thành array thuần để
     * đưa vào cache — an toàn với `serializable_classes = false` vì không có object
     * PHP nào trong payload, chỉ scalar/array.
     *
     * @return array{attributes: array<string, mixed>, relations: array<string, mixed>}
     */
    protected function serializeItemForCache(Model $item): array
    {
        $relations = [];

        foreach ($item->getRelations() as $name => $value) {
            if ($value instanceof Collection) {
                $relations[$name] = $value->map(fn (Model $related) => $related->getAttributes())->all();
            } elseif ($value instanceof Model) {
                $relations[$name] = $value->getAttributes();
            } else {
                $relations[$name] = null;
            }
        }

        return ['attributes' => $item->getAttributes(), 'relations' => $relations];
    }

    /**
     * Dựng lại Model thật từ array đã cache — dùng `newFromBuilder()`, đúng cơ chế
     * Eloquent tự dùng khi hydrate 1 dòng DB thô, nên casts/accessors/hidden hoạt
     * động y hệt như đọc thẳng từ DB. Chỉ hỗ trợ tên quan hệ 1 cấp (khớp $with hiện
     * tại của các repo bật cache — không có 'a.b' lồng nhau).
     *
     * @param  array{attributes?: array<string, mixed>, relations?: array<string, mixed>}  $data
     */
    protected function hydrateItemFromCache(array $data): Model
    {
        $model = $this->model->newFromBuilder($data['attributes'] ?? []);

        foreach ($data['relations'] ?? [] as $name => $relationData) {
            if (! method_exists($model, $name)) {
                continue;
            }

            $related = $model->{$name}()->getRelated();

            if ($relationData === null) {
                $model->setRelation($name, null);
            } elseif (array_is_list($relationData)) {
                $model->setRelation($name, $related->newCollection(
                    array_map(fn (array $attrs) => $related->newFromBuilder($attrs), $relationData)
                ));
            } else {
                $model->setRelation($name, $related->newFromBuilder($relationData));
            }
        }

        return $model;
    }

    protected function findFresh(int|string $id): ?Model
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

        $this->invalidateListCache();

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

        $this->invalidateListCache();
        $this->invalidateDetailCache($id);

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

        if (! $record) {
            return false;
        }

        $deleted = (bool) $record->delete();

        if ($deleted) {
            $this->invalidateListCache();
            $this->invalidateDetailCache($id);
        }

        return $deleted;
    }

    public function restore(int|string $id): bool
    {
        $record = $this->query()->onlyTrashed()->find($id);

        if (! $record) {
            return false;
        }

        $restored = (bool) $record->restore();

        if ($restored) {
            $this->invalidateListCache();
            $this->invalidateDetailCache($id);
        }

        return $restored;
    }

    public function forceDelete(int|string $id): bool
    {
        $record = $this->query()->withTrashed()->find($id);

        if (! $record) {
            return false;
        }

        $deleted = (bool) $record->forceDelete();

        if ($deleted) {
            $this->invalidateListCache();
            $this->invalidateDetailCache($id);
        }

        return $deleted;
    }

    public function forgetCache(int|string $id): void
    {
        $this->invalidateListCache();
        $this->invalidateDetailCache($id);
    }

    /**
     * Bump version namespace `{domain}:list` — mọi cache list/paginate cũ của domain
     * này tự "mồ côi" (mục 13), không xoá theo wildcard. Chạy sau khi transaction
     * ghi commit thành công (mục 12); nếu không có transaction đang mở, chạy ngay.
     */
    protected function invalidateListCache(): void
    {
        if (! $this->cacheEnabled) {
            return;
        }

        $domain = $this->cacheDomain;
        DB::afterCommit(fn () => $this->cacheService()->bumpVersion($domain.':list'));
    }

    /** Xoá đúng 1 cache key chi tiết theo id — chính xác, không cần version namespace. */
    protected function invalidateDetailCache(int|string $id): void
    {
        if (! $this->cacheEnabled) {
            return;
        }

        $key = $this->detailCacheKey($id);
        DB::afterCommit(fn () => $this->cacheService()->forget($key));
    }

    protected function detailCacheKey(int|string $id): string
    {
        return CacheKey::of($this->cacheDomain.':detail', ['id' => $id]);
    }

    protected function cacheService(): CacheService
    {
        return app(CacheService::class);
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

                // Khoá chính của toàn hệ thống là UUID (xem ERD 1.0), nên bộ lọc
                // theo `*_id` phải nhận UUID; guard cũ chỉ cho số nguyên khiến MỌI
                // bộ lọc theo id trả 422 (vd "Phòng ban" ở màn Danh sách người dùng
                // & phân quyền không lọc được giá trị nào). Vẫn chặn chuỗi rác để
                // không đẩy giá trị lạ xuống câu WHERE.
                if (($column === 'id' || str_ends_with($column, '_id'))
                    && ! is_numeric($value)
                    && ! (is_string($value) && Str::isUuid($value))) {
                    ApiError::abort(422, 'VALIDATION_ERROR', "Giá trị lọc '{$column}' phải là số nguyên hoặc UUID.");
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
