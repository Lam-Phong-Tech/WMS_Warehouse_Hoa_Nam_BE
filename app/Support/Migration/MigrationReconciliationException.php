<?php

namespace App\Support\Migration;

use RuntimeException;

/**
 * Ném ra bên trong transaction COMMIT khi reconciliation FAIL, để Laravel rollback
 * toàn bộ master/opening/movement — không cho phép tồn tại trạng thái "một phần"
 * (SRS §13.12: "Rollback test must prove no subset master/opening/movement remains").
 */
class MigrationReconciliationException extends RuntimeException
{
    /** @param array<string, mixed> $result */
    public function __construct(public readonly array $result)
    {
        parent::__construct('MIGRATION_RECONCILIATION_FAILED');
    }
}
