<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class P09WarrantyPage
{
    public static function read(Request $request, Builder $query, array $scope, string $id, string $time, bool $descending = true): array
    {
        $input = Validator::make($request->query(), ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string', 'max:4096']])->validate();
        if (isset($input['page'], $input['cursor'])) {
            ApiError::abort(422, 'WARRANTY_PAGING_CONFLICT', 'Không gửi đồng thời page và cursor.');
        }
        $size = (int) ($input['per_page'] ?? 20);
        $filters = $request->query();
        unset($filters['cursor'], $filters['page']);
        $fingerprint = P09WarrantyPolicy::digest(['path' => $request->path(), 'filters' => $filters,
            'scope' => $scope, 'descending' => $descending], (string) config('app.key'));
        $q = DB::query()->fromSub($query->selectRaw("{$id} AS p09_id, COALESCE(CAST({$time} AS VARCHAR(60)), '') AS p09_time"), 'p09_page');
        $total = (clone $q)->count(); // Full authorized, filtered source; never page size.
        $cursor = null;
        if (isset($input['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($input['cursor']), true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                ApiError::abort(422, 'WARRANTY_CURSOR_INVALID', 'Cursor không hợp lệ; tải lại trang đầu.');
            }
            if (! is_array($cursor) || ! P09WarrantyPolicy::cursorValid($cursor, $fingerprint, time())) {
                ApiError::abort(422, 'WARRANTY_CURSOR_SCOPE_MISMATCH', 'Cursor hết hạn hoặc khác kho, phiên, quyền hay bộ lọc.');
            }
            $operator = $descending ? '<' : '>';
            $q->where(fn (Builder $part): Builder => $part->where('p09_time', $operator, $cursor['time'])
                ->orWhere(fn (Builder $tie): Builder => $tie->where('p09_time', $cursor['time'])->where('p09_id', $operator, $cursor['id'])));
        }
        $direction = $descending ? 'desc' : 'asc';
        $q->orderBy('p09_time', $direction)->orderBy('p09_id', $direction);
        if (isset($input['page'])) {
            $q->offset(((int) $input['page'] - 1) * $size);
        }
        $rows = $q->limit($size + 1)->get();
        $more = $rows->count() > $size;
        $rows = $rows->take($size)->values();
        $next = null;
        if ($more && ! isset($input['page'])) {
            $last = $rows->last();
            $next = Crypt::encryptString(json_encode(['v' => 1, 'scope' => $fingerprint,
                'time' => $last->p09_time, 'id' => $last->p09_id,
                'expires' => $cursor['expires'] ?? time() + 900], JSON_THROW_ON_ERROR));
        }
        return [$rows->pluck('p09_id')->all(), ['total' => $total, 'per_page' => $size,
            'page' => isset($input['page']) ? (int) $input['page'] : null, 'hasMore' => $more, 'nextCursor' => $next,
            'nextPage' => isset($input['page']) && $more ? (int) $input['page'] + 1 : null,
            'pagination' => isset($input['page']) ? 'OFFSET' : 'KEYSET', 'consistency' => 'LIVE_READ_NOT_FROZEN_CROSS_REQUEST']];
    }
}
