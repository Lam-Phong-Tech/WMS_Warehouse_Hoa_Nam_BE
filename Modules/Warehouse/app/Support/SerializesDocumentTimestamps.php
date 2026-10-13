<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\DocumentTimestamp;
use InvalidArgumentException;

/** Serialization only. Do not change date casts/getters, storage format or writers. */
trait SerializesDocumentTimestamps
{
    public function attributesToArray(): array
    {
        $result = parent::attributesToArray();
        foreach (['created_at', 'updated_at', 'submitted_at', 'approved_at', 'posted_at',
            'closed_at', 'reversed_at', 'rejected_at', 'cancelled_at'] as $field) {
            // Use the original column representation before an ORM cast can
            // interpret a naive wall-clock in PHP's current default timezone.
            // Preserve visibility and never synthesize a missing attribute.
            if (! array_key_exists($field, $result) || ! array_key_exists($field, $this->attributes)) {
                continue;
            }
            try {
                $result[$field] = DocumentTimestamp::utc($this->attributes[$field]);
            } catch (InvalidArgumentException) {
                ApiError::abort(503, 'DOCUMENT_TIMESTAMP_INVALID', 'Thời gian chứng từ nguồn không hợp lệ.');
            }
        }

        return $result;
    }
}
