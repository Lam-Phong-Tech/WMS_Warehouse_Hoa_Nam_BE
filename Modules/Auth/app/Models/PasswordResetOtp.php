<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordResetOtp extends Model
{
    use HasUuids;

    protected $table = 'password_reset_otps';

    protected $fillable = [
        'user_id', 'email', 'otp_hash', 'attempts', 'expires_at',
        'verified_at', 'reset_token_hash', 'reset_token_expires_at',
        'consumed_at', 'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'reset_token_expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
