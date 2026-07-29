<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasUuids;

    protected $table = 'permission';

    protected $primaryKey = 'permission_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'permission_key',
        'permission_name',
        'scope_type',
        'status',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission', 'permission_id', 'role_id', 'permission_id', 'role_id');
    }
}
