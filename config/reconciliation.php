<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Inventory reconciliation scheduler
    |--------------------------------------------------------------------------
    |
    | `inventory:reconcile` compares the inventory_balances projection with the
    | stock_movements ledger and persists newly detected divergence incidents.
    | The command does not auto-correct stock. Default cadence is every 15 minutes
    | and can be overridden per environment after DevOps/PM approval.
    |
    */
    'inventory' => [
        'enabled' => filter_var(env('INVENTORY_RECONCILE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'schedule' => env('INVENTORY_RECONCILE_CRON', '*/15 * * * *'),
        'overlap_lock_minutes' => (int) env('INVENTORY_RECONCILE_LOCK_MINUTES', 30),
    ],

    /*
    | No automatic incident purge is enabled. Retention/disposition is a BA+QA -> PM
    | governance decision (DEV219-P1-05). Keeping this null prevents destructive
    | cleanup before that decision is formally approved.
    */
    'incident_retention_days' => env('RECONCILIATION_INCIDENT_RETENTION_DAYS'),
];
