<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng sản phẩm trong yêu cầu tư vấn gửi từ storefront ẩn danh.
 *
 * KHÔNG có PII ở đây — mã và tên sản phẩm là dữ liệu danh mục công khai, ai mở
 * app cũng thấy. Toàn bộ dữ liệu cá nhân của người gửi nằm ở bảng cha và đã
 * được mã hoá tại đó (WWHN-SEC-06). Đó cũng là lý do bảng con này không có
 * accessor/mutator nào: thêm mã hoá cho dữ liệu công khai chỉ tạo cảm giác an
 * toàn giả và làm mất khả năng truy vấn.
 *
 * Dòng item là BẤT BIẾN: ghi một lần lúc tiếp nhận, không có luồng nào sửa hay
 * xoá. Vì vậy model chỉ có `created_at` ($timestamps = false để Eloquent không
 * đòi `updated_at`).
 */
class PublicQuoteRequestItem extends Model
{
    use HasUuids;

    protected $table = 'public_quote_request_items';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'line_no',
        'product_id',
        'variant_id',
        'quantity',
        'sku_code_snapshot',
        'product_name_snapshot',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'quantity' => 'integer',
        'created_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(PublicQuoteRequest::class, 'request_id');
    }
}
