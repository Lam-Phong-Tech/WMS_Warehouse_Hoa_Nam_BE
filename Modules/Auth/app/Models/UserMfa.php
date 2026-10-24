<?php

namespace Modules\Auth\Models;

use App\Casts\UtcInstant;
use App\Support\Security\PiiCrypto;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * WWHN-SEC-08 — trạng thái MFA của một tài khoản.
 *
 * `secret` là thuộc tính ẢO: đọc/ghi thì thấy base32, còn trong CSDL là
 * ciphertext AEAD (App\Support\Security\PiiCrypto) dưới keyring PII. Không có
 * đường nào ghi được bí mật ở dạng rõ — cột plaintext không tồn tại.
 */
class UserMfa extends Model
{
    private const CONTEXT = 'user_mfa.secret';

    protected $table = 'user_mfa';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'secret',
        'confirmed_at',
        'recovery_codes',
        'failed_attempts',
        'locked_until',
        'last_used_at',
    ];

    /**
     * Cả bí mật lẫn băm mã khôi phục đều KHÔNG được lọt vào bất kỳ lần serialize
     * nào. Bản ghi này còn đi qua audit/log ở luồng bật-tắt MFA.
     */
    protected $hidden = [
        'secret',
        'secret_ciphertext',
        'recovery_codes',
    ];

    protected $casts = [
        'confirmed_at' => UtcInstant::class,
        'locked_until' => UtcInstant::class,
        'last_used_at' => UtcInstant::class,
        'recovery_codes' => 'array',
        'failed_attempts' => 'integer',
    ];

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    protected function secret(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => PiiCrypto::decrypt($this->attributes['secret_ciphertext'] ?? null, self::CONTEXT),
            set: fn (?string $value): array => [
                'secret_ciphertext' => PiiCrypto::encrypt($value, self::CONTEXT),
            ],
        );
    }
}
