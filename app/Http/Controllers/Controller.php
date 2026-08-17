<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * SRS v1.12 §13.6 Pagination: "page, page_size default 50/max 200".
     * `per_page` là tên tham số client hiện hành nên được giữ; `page_size` là tên
     * chuẩn trong contract và phải được nhận đúng như nhau. Cap 200 theo contract.
     */
    protected function perPage(Request $request, int $default = 15): int
    {
        $requested = $request->input('per_page', $request->input('page_size', $default));

        return min(200, max(1, (int) $requested));
    }

    /**
     * Apply the same `keyword` LIKE search contract used by repository-backed list APIs.
     *
     * @param  array<int, string>  $columns
     */
    protected function applyKeyword(EloquentBuilder|QueryBuilder $query, Request $request, array $columns): EloquentBuilder|QueryBuilder
    {
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword === '' || $columns === []) {
            return $query;
        }

        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function ($nested) use ($columns, $keyword, $operator): void {
            foreach ($columns as $column) {
                $nested->orWhere($column, $operator, "%{$keyword}%");
            }
        });
    }
}
