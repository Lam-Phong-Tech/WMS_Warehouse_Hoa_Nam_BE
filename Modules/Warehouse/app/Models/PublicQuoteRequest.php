<?php

namespace Modules\Warehouse\Models;

use App\Support\Security\BlindIndex;
use App\Support\Security\PiiCrypto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Yêu cầu tư vấn gửi từ storefront ẩn danh.
 *
 * Model cố ý "câm": không event, không observer, không quan hệ tới SKU. Hợp
 * đồng nói rõ không được móc CRM/ERP/notification/queue/outbox vào luồng này,
 * và cách chắc chắn nhất để điều đó đúng mãi là không có chỗ nào để móc vào.
 * Quan hệ DUY NHẤT là `items()`: các dòng sản phẩm của chính yêu cầu này, cùng
 * phạm vi storefront, không dẫn sang miền kho.
 *
 * ------------------------------------------------------------------------
 * WWHN-SEC-06 — mã hoá PII đặt ở ĐÂY, không phải ở controller
 * ------------------------------------------------------------------------
 *
 * Ba trường `full_name` / `phone` / `note` là dữ liệu cá nhân của người NGOÀI
 * công ty, thu thập kèm `consent` + `privacy_version`. Chúng được lưu ở dạng
 * ciphertext AEAD trong `*_ciphertext`, và `phone` có thêm blind index
 * `phone_lookup` để vẫn tra cứu chính xác được.
 *
 * VÌ SAO ĐẶT Ở MODEL CHỨ KHÔNG Ở CONTROLLER: controller là nơi người ta thêm
 * code mới. Một `PublicQuoteRequest::create([...])` viết ở chỗ khác trong tương
 * lai mà quên gọi hàm mã hoá sẽ ghi thẳng plaintext xuống DB và không ai nhận
 * ra. Đặt ở accessor/mutator thì KHÔNG CÒN đường nào ghi được plaintext: cột
 * plaintext thậm chí không nằm trong `$fillable` nữa.
 *
 * `phone_normalized` (cột cũ) CỐ Ý không còn được ghi. Nó chính là số điện
 * thoại chỉ bỏ khoảng trắng — giữ nó thì việc mã hoá `phone` trở nên vô nghĩa.
 * Cột được xoá hẳn ở migration Phase 6.
 */
class PublicQuoteRequest extends Model
{
    use HasUuids;

    public const STATUS_RECEIVED = 'RECEIVED';

    /** Ràng buộc ciphertext vào đúng ô của nó — xem PiiCrypto::encrypt(). */
    private const CONTEXT_FULL_NAME = 'public_quote_requests.full_name';

    private const CONTEXT_PHONE = 'public_quote_requests.phone';

    private const CONTEXT_NOTE = 'public_quote_requests.note';

    protected $table = 'public_quote_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * `full_name` / `phone` / `note` vẫn nằm ở đây vì đó là tên thuộc tính mà
     * ứng dụng dùng; mutator bên dưới chuyển chúng thành cột `*_ciphertext`.
     * Cột plaintext và `phone_lookup` KHÔNG có trong danh sách: không ai được
     * gán trực tiếp, kể cả bằng mass assignment.
     */
    protected $fillable = [
        'idempotency_key',
        'payload_hash',
        'product_id',
        'variant_id',
        'item_count',
        'full_name',
        'phone',
        'province_code',
        'note',
        'consent',
        'privacy_version',
        'status',
        'source',
        'request_ip',
        'user_agent',
    ];

    /**
     * Ẩn khỏi mọi lần serialize. Không endpoint nào trả các trường này ra, nhưng
     * `toArray()` còn được log/kiểm thử dùng — để lộ mặc định là kiểu rò rỉ im
     * lặng nhất.
     */
    protected $hidden = [
        'full_name_ciphertext',
        'phone_ciphertext',
        'note_ciphertext',
        'phone_lookup',
        'full_name',
        'phone',
        'phone_normalized',
        'note',
    ];

    protected $casts = [
        'consent' => 'boolean',
        'item_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Danh sách sản phẩm khách chọn — NGUỒN SỰ THẬT của yêu cầu.
     *
     * `product_id` / `variant_id` trên bảng cha chỉ là bản sao của dòng 1, giữ
     * lại cho dữ liệu và truy vấn cũ. Mọi câu hỏi dạng "khách này quan tâm
     * những gì" phải đọc quan hệ này; đọc `product_id` rồi dừng lại là bỏ sót
     * đúng phần mà bảng con sinh ra để cứu.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PublicQuoteRequestItem::class, 'request_id')->orderBy('line_no');
    }

    /**
     * Tra cứu theo số điện thoại mà không cần lưu lại số đó.
     *
     * Dùng `whereIn` với TẤT CẢ key id còn trong keyring: trong cửa sổ xoay
     * khoá, hàng chưa kịp re-index vẫn phải tìm ra. Chỉ dùng khoá active ở đây
     * sẽ tạo ra lỗi im lặng "không tìm thấy" đúng vào lúc đang xoay khoá.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeWherePhone($query, ?string $phone)
    {
        $candidates = BlindIndex::phoneCandidates($phone);

        return $candidates === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('phone_lookup', $candidates);
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => PiiCrypto::decrypt(
                $this->attributes['full_name_ciphertext'] ?? null,
                self::CONTEXT_FULL_NAME,
            ),
            set: fn (?string $value): array => [
                'full_name_ciphertext' => PiiCrypto::encrypt($value, self::CONTEXT_FULL_NAME),
            ],
        );
    }

    protected function phone(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => PiiCrypto::decrypt(
                $this->attributes['phone_ciphertext'] ?? null,
                self::CONTEXT_PHONE,
            ),
            // Ciphertext và blind index LUÔN được ghi cùng một lượt. Tách ra hai
            // chỗ là cách chắc chắn nhất để một ngày nào đó chúng lệch nhau và
            // bản ghi biến mất khỏi kết quả tra cứu mà không có lỗi nào.
            set: fn (?string $value): array => [
                'phone_ciphertext' => PiiCrypto::encrypt($value, self::CONTEXT_PHONE),
                'phone_lookup' => BlindIndex::phone($value),
            ],
        );
    }

    protected function note(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => PiiCrypto::decrypt(
                $this->attributes['note_ciphertext'] ?? null,
                self::CONTEXT_NOTE,
            ),
            set: fn (?string $value): array => [
                'note_ciphertext' => PiiCrypto::encrypt($value, self::CONTEXT_NOTE),
            ],
        );
    }
}
