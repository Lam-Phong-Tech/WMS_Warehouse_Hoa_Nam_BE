<?php

namespace Modules\Warehouse\Models;

use App\Support\Security\BlindIndex;
use App\Support\Security\PiiCrypto;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarrantyCase extends Model
{
    use SoftDeletes;

    /**
     * SRS v1.12 §6 + ERD §0.2: đúng 6 trạng thái, DB CHECK
     * chk_erd_warranty_cases_2 enforce. INSPECTING/WAITING_CHECK/REPAIRED/TEMP_ONLY
     * là hằng số legacy đã gỡ — TEMP_ONLY là identification_status, không phải status.
     */
    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_CHECKING = 'CHECKING';

    public const STATUS_REPAIRING = 'REPAIRING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_RETURNED = 'RETURNED';

    public const STATUS_CANCELLED = 'CANCELLED';

    /** Chỉ còn để nhận diện dữ liệu legacy khi kiểm tra "case đã đóng". */
    public const STATUS_CLOSED = 'CLOSED';

    protected $table = 'warranty_case';

    protected $primaryKey = 'warranty_case_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'warranty_case_id',
        'warranty_case_code',
        'inventory_serial_id',
        'item_id',
        'product_id',
        'temporary_item_code',
        'identification_status',
        'missing_code_reason',
        'manual_product_description',
        'defect_id',
        'customer_name',
        'customer_phone',
        'status',
        'condition_status',
        'description',
        'created_by',
        'version',
        'closed_at',
        'retention_until',
        'legal_hold_flag',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'retention_until' => 'datetime',
        'legal_hold_flag' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(WarrantyEvent::class, 'warranty_case_id', 'warranty_case_id');
    }

    /*
    |--------------------------------------------------------------------------
    | WWHN-SEC-06 — PII khách hàng
    |--------------------------------------------------------------------------
    |
    | Ba accessor/mutator dưới đây KHÔNG phải lớp bảo vệ chính. Gần như toàn bộ
    | luồng bảo hành đi qua query builder (`DB::table('warranty_case')`) chứ
    | không qua model này, nên lớp chính vẫn là
    | `Modules\Warehouse\Support\WarrantyCasePii::encode()/decodeRow()` được gọi
    | tường minh ở từng điểm ghi/đọc.
    |
    | Nhưng chúng vẫn phải có mặt: bỏ đi thì một `WarrantyCase::create([...])`
    | viết ở chỗ khác sẽ ném "no column named customer_name" (cột đã bị xoá ở
    | Phase 6). Đặt mutator tại model làm cho ĐƯỜNG NÀO ghi cũng đúng, thay vì
    | phụ thuộc vào việc người viết sau có nhớ gọi helper hay không.
    |
    | Dùng chung chuỗi context với WarrantyCasePii — hai nơi lệch context thì tag
    | xác thực AEAD không khớp và dữ liệu thành không giải mã được.
    */
    protected function customerName(): Attribute
    {
        return $this->piiAttribute('customer_name');
    }

    protected function customerPhone(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => PiiCrypto::decrypt(
                $this->attributes['customer_phone_ciphertext'] ?? null,
                'warranty_cases.customer_phone',
            ),
            // Ciphertext và blind index luôn ghi cùng một lượt — tách ra là cách
            // chắc chắn để một ngày nào đó chúng lệch nhau và hồ sơ biến mất khỏi
            // kết quả tra cứu mà không có lỗi nào.
            set: fn (?string $value): array => [
                'customer_phone_ciphertext' => PiiCrypto::encrypt($value, 'warranty_cases.customer_phone'),
                'customer_phone_lookup' => BlindIndex::phone($value),
            ],
        );
    }

    protected function customerAddress(): Attribute
    {
        return $this->piiAttribute('customer_address');
    }

    private function piiAttribute(string $field): Attribute
    {
        $context = 'warranty_cases.'.$field;
        $column = $field.'_ciphertext';

        return Attribute::make(
            get: fn (): ?string => PiiCrypto::decrypt($this->attributes[$column] ?? null, $context),
            set: function (?string $value) use ($column, $context, $field): array {
                $payload = [$column => PiiCrypto::encrypt($value, $context)];

                if ($field === 'customer_name') {
                    $payload['customer_name_lookup'] = BlindIndex::nameTokens($value);
                }

                return $payload;
            },
        );
    }
}
