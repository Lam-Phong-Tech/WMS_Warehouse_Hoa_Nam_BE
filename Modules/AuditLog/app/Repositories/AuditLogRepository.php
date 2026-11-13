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
        $actions = $filters['actions'] ?? null;
        $actions = is_array($actions)
            ? array_values(array_filter($actions, static fn ($item) => is_string($item) && $item !== ''))
            : [];
        if (($keyword !== null && $keyword !== '') || $actions !== []) {
            $op = $this->likeOperator($query);
            $query->where(function (Builder $q) use ($keyword, $op, $actions) {
                if ($keyword !== null && $keyword !== '') {
                    $q->orWhere('reason', $op, "%{$keyword}%")
                        ->orWhere('object_type', $op, "%{$keyword}%")
                        ->orWhere('object_id', $op, "%{$keyword}%")
                        ->orWhere('action', $op, "%{$keyword}%")
                        ->orWhereHas('actor', function (Builder $actor) use ($keyword, $op) {
                            $actor->where('display_name', $op, "%{$keyword}%")
                                ->orWhere('email', $op, "%{$keyword}%");
                        });
                }
                if ($actions !== []) {
                    // Nhãn tiếng Việt/Anh/Trung của hành động không nằm trong DB;
                    // FE gửi mã hành động khớp nhãn tìm kiếm (actions[]) để khớp
                    // theo ngôn ngữ người dùng gõ.
                    $q->orWhereIn('action', $actions);
                }
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

        unset($filters['user_id'], $filters['q'], $filters['date_from'], $filters['date_to'], $filters['actions']);

        return parent::applyFilters($query, $filters);
    }
}
