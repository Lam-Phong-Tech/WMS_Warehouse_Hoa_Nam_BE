<?php

namespace Modules\Warehouse\Database\Migrations\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * SQLite rebuilds a whole table (create __temp__, copy, rename) for column
 * changes that other databases apply in place, and refuses to rename a table
 * that a view still references. 2026_08_09_000400_align_latest_erd_baseline
 * creates read-only views (app_users, skus, inbound_lines, warranty_cases,
 * ...) over the physical baseline tables, so any later migration that runs
 * Schema::table() on one of those physical tables must drop the dependent
 * views first and recreate them after — on SQLite only; Postgres applies
 * these ALTERs in place and never needs this.
 */
trait DropsBaselineViewsAroundAlter
{
    /**
     * Physical baseline table => views selecting from it, per
     * 2026_08_09_000400_align_latest_erd_baseline's VIEWS list.
     *
     * @var array<string, string[]>
     */
    private static array $baselineViewsByTable = [
        'app_user' => ['app_users'],
        'role' => ['roles'],
        'permission' => ['permissions'],
        'user_role' => ['user_roles'],
        'role_permission' => ['role_permissions'],
        'idempotency_keys' => ['idempotency_records'],
        'audit_log' => ['audit_logs'],
        'sku' => ['skus'],
        'sku_alias' => ['sku_aliases'],
        'putaway_task' => ['putaway_events'],
        'scan_event' => ['scan_events'],
        'stock_movements' => ['inventory_movements'],
        'posting_event' => ['posting_events'],
        'reconciliation_incident' => ['reconciliation_incidents'],
        'reversal_document' => ['reversal_documents'],
        'reversal_line' => ['reversal_lines'],
        'stock_documents' => ['inbound_documents', 'outbound_documents'],
        'stock_document_lines' => ['inbound_lines', 'outbound_lines'],
        'warranty_case' => ['warranty_cases'],
        'warranty_event' => ['warranty_events'],
        'pv_product_profile' => ['pv_product_profiles'],
        'pv_industry' => ['pv_industries'],
        'pv_filter_tag' => ['pv_tags'],
        'pv_product_industry' => ['pv_product_industries'],
        'pv_product_tag' => ['pv_product_tags'],
        'pv_recommendation_rule' => ['pv_recommendation_rules'],
    ];

    /**
     * Drop the baseline views (if any) over $table on SQLite, run $callback,
     * then recreate the views from their live sqlite_master definition — not
     * a hardcoded copy, so this stays correct if the baseline migration's
     * view SQL ever changes.
     */
    private function alterTableAroundBaselineViews(string $table, callable $callback): void
    {
        $views = DB::getDriverName() === 'sqlite'
            ? (self::$baselineViewsByTable[$table] ?? [])
            : [];

        $viewSqlByName = [];
        foreach ($views as $view) {
            $sql = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = ?",
                [$view]
            )->sql ?? null;

            if ($sql !== null) {
                $viewSqlByName[$view] = $sql;
                DB::statement("DROP VIEW IF EXISTS {$view}");
            }
        }

        $callback();

        foreach ($viewSqlByName as $sql) {
            DB::statement($sql);
        }
    }
}
