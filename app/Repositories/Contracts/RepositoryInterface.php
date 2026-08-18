<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface RepositoryInterface
{
    public function all(): Collection;

    public function paginate(array $filters = [], int $perPage = 15, ?\Closure $scope = null): LengthAwarePaginator;

    public function trashed(array $filters = [], int $perPage = 15, ?\Closure $scope = null): LengthAwarePaginator;

    public function find(int|string $id): ?Model;

    public function create(array $data): Model;

    public function update(int|string $id, array $data): ?Model;

    public function delete(int|string $id): bool;

    public function restore(int|string $id): bool;

    public function forceDelete(int|string $id): bool;

    /**
     * Vô hiệu hoá cache list + cache chi tiết của 1 bản ghi. Dùng khi mutation đi
     * qua Eloquent relation trực tiếp (vd: `sync()`) thay vì `update()`/`create()`
     * của repository — controller không cần biết cấu trúc cache key thật sự.
     * No-op nếu repo con không bật cache.
     */
    public function forgetCache(int|string $id): void;
}
