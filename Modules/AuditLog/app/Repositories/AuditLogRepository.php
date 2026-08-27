<?php

namespace Modules\AuditLog\Repositories;

use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Repositories\Contracts\AuditLogRepositoryInterface;

class AuditLogRepository extends BaseRepository implements AuditLogRepositoryInterface
{
    /**
     * `object_id`/`actor_id` CHỦ Ý không nằm trong danh sách này: cả hai đều là
     * UUID string trên bảng này, trong khi BaseRepository::applyFilters() ép
     * buộc mọi cột tên kết thúc bằng `_id` phải là số (dành cho FK số nguyên ở
     * các repo khác) — lọc theo 2 cột đó sẽ luôn 422. `user_id`/`q` được xử lý
     * thủ công bên dưới, không đi qua đường generic này.
     */
    protected array $filterable = ['action', 'module'];

    /** Lấy kèm actor để UI hiện "Người thực hiện" mà không cần join tay ở controller. */
    protected array $with = ['actor'];

    public function __construct(AuditLog $model)
    {
        parent::__construct($model);
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['user_id'])) {
            $query->where('actor_id', (string) $filters['user_id']);
        }

        $keyword = $filters['q'] ?? null;
        if ($keyword !== null && $keyword !== '') {
            $op = $this->likeOperator($query);
            $query->where(function (Builder $q) use ($keyword, $op) {
                $q->orWhere('reason', $op, "%{$keyword}%")
                    ->orWhere('object_type', $op, "%{$keyword}%")
                    ->orWhere('object_id', $op, "%{$keyword}%");
            });
        }

        unset($filters['user_id'], $filters['q']);

        return parent::applyFilters($query, $filters);
    }
}
