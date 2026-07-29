<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SafeStockAlertHistory extends Model
{
    use HasUuids;

    protected $table = 'safe_stock_alerts_history';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['product_id', 'warehouse_id', 'current_qty', 'min_qty', 'max_qty', 'old_status', 'new_status', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('safe_stock_alerts_history là append-only, không được update.');
        });

        static::deleting(function () {
            throw new \LogicException('safe_stock_alerts_history là append-only, không được xoá.');
        });
    }
}
