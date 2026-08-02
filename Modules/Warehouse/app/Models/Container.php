<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Container extends Model
{
    use HasUuids;

    protected $table = 'containers';

    protected $primaryKey = 'container_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'container_code', 'container_type', 'physical_code_id', 'primary_sku_id',
        'parent_container_id', 'zone_code', 'location_hint', 'status', 'version',
    ];

    public function physicalCode(): BelongsTo
    {
        return $this->belongsTo(PhysicalCode::class, 'physical_code_id', 'physical_code_id');
    }

    public function primarySku(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'primary_sku_id', 'sku_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_container_id', 'container_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_container_id', 'container_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContainerItem::class, 'container_id', 'container_id');
    }
}
