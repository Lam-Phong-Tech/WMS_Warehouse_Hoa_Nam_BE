<?php

namespace Modules\AuditLog\Repositories;

use App\Repositories\BaseRepository;
use Carbon\CarbonImmutable;
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

        // Lọc theo khoảng thời gian / khung giờ (mục 13: "trong khung giờ X ai
        // làm gì"). Nhận datetime dạng "Y-m-d H:i:s" hoặc "Y-m-d"; chỉ có 1 đầu
        // mút thì lọc mở một phía. Dùng created_at (đã cast datetime).
        $from = $filters['date_from'] ?? null;
        if ($from !== null && $from !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($from, 'Asia/Ho_Chi_Minh')
                ->setTimezone(config('app.timezone', 'Asia/Ho_Chi_Minh')));
        }

        $to = $filters['date_to'] ?? null;
        if ($to !== null && $to !== '') {
            $query->where('created_at', '<=', CarbonImmutable::parse($to, 'Asia/Ho_Chi_Minh')
                ->setTimezone(config('app.timezone', 'Asia/Ho_Chi_Minh')));
        }

        unset($filters['user_id'], $filters['q'], $filters['date_from'], $filters['date_to']);

        return parent::applyFilters($query, $filters);
    }
}
