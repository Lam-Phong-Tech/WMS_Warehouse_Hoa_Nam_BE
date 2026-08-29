<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Yêu cầu tư vấn gửi từ storefront ẩn danh.
 *
 * Model cố ý "câm": không event, không observer, không quan hệ tới SKU. Hợp
 * đồng nói rõ không được móc CRM/ERP/notification/queue/outbox vào luồng này,
 * và cách chắc chắn nhất để điều đó đúng mãi là không có chỗ nào để móc vào.
 */
class PublicQuoteRequest extends Model
{
    use HasUuids;

    public const STATUS_RECEIVED = 'RECEIVED';

    protected $table = 'public_quote_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'idempotency_key',
        'payload_hash',
        'product_id',
        'variant_id',
        'full_name',
        'phone',
        'phone_normalized',
        'province_code',
        'note',
        'consent',
        'privacy_version',
        'status',
        'source',
        'request_ip',
        'user_agent',
    ];

    protected $casts = [
        'consent' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
