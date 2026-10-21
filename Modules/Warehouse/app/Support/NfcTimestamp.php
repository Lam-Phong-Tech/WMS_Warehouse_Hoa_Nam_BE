<?php

namespace Modules\Warehouse\Support;

use App\Support\DocumentTimestamp;
use DateTimeInterface;

/** physical_codes uses timestamptz; binding a DateTime would discard its offset. */
final class NfcTimestamp
{
    public const CONTRACT = 'nfc-timestamps-v1';

    public const FIELDS = ['assigned_at', 'created_at', 'updated_at', 'deactivated_at'];

    public static function write(DateTimeInterface $instant): string
    {
        return DocumentTimestamp::instant($instant)->format('Y-m-d H:i:s.uP');
    }

    public static function read(mixed $value): ?string
    {
        // Old SQLite fixtures used Vietnam wall clocks. PostgreSQL aware values retain their instant.
        return DocumentTimestamp::utc($value, DocumentTimestamp::LEGACY_TIMEZONE);
    }

    public static function record(array $record): array
    {
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $record)) {
                $record[$field] = self::read($record[$field]);
            }
        }

        return $record;
    }
}
