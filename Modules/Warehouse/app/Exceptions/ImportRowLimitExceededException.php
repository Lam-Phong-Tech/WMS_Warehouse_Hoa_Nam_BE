<?php

namespace Modules\Warehouse\Exceptions;

use InvalidArgumentException;

/**
 * WWHN-330 (DEV330-P1-02) — file import vượt quá giới hạn số dòng cho phép.
 * Exception riêng để controller map ra error code chuẩn ('SKU_IMPORT_ROW_LIMIT_EXCEEDED')
 * thay vì lẫn với các lỗi file/parse khác, không lộ stack trace cho client.
 */
class ImportRowLimitExceededException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly int $maxRows,
        public readonly ?int $actualRows = null,
    ) {
        parent::__construct($message);
    }
}
