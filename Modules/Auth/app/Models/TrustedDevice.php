<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustedDevice extends Model
{
    use HasUuids;

    protected $table = 'trusted_devices';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'installation_id',
        'token_hash',
        'previous_hash',
        'previous_valid_until',
        'trusted_at',
        'expires_at',
        'last_used_at',
        'revoked_at',
        'user_agent',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'previous_valid_until' => 'datetime',
            'trusted_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
