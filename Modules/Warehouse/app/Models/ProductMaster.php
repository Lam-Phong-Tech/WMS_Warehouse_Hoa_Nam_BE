<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductMaster extends Model
{
    use HasUuids;

    protected $table = 'product_masters';

    protected $primaryKey = 'master_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['master_type', 'code', 'name', 'parent_master_id', 'status'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_master_id', 'master_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_master_id', 'master_id');
    }
}
