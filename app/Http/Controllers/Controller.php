<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function perPage(Request $request, int $default = 15): int
    {
        return min(100, max(1, (int) $request->input('per_page', $default)));
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
