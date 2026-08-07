<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerItem extends Model
{
    use HasUuids;

    protected $table = 'container_items';

    protected $primaryKey = 'container_item_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['container_id', 'item_id', 'sku_id', 'quantity', 'status'];

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class, 'container_id', 'container_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemInstance::class, 'item_id', 'item_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'sku_id', 'sku_id');
    }
}
