<?php

namespace App\Support\Migration;

use RuntimeException;

/**
 * Từ chối quyền của cutover operator/approver trước khi bất kỳ `migration_runs`
 * nào được tạo — mang theo error code ổn định để job in ra evidence (WWHN-299
 * DEV299-P1-02).
 */
class CutoverAuthorityException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
