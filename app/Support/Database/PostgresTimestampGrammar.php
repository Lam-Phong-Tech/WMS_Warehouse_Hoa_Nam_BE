<?php

namespace App\Support\Database;

use Illuminate\Database\Query\Grammars\PostgresGrammar;

/** Preserve the instant when PostgreSQL's session zone differs from PHP's zone. */
final class PostgresTimestampGrammar extends PostgresGrammar
{
    public function getDateFormat()
    {
        // timestamptz consumes the offset; timestamp without time zone keeps the
        // supplied wall clock, as before. Do not change the DB session timezone.
        return 'Y-m-d H:i:s.uP';
    }
}
