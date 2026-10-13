<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/** Read-only serialization. Never rewrites stored dates or historical receipts. */
final class DocumentTimestamp
{
    public const CONTRACT = 'document-timestamps-v1';

    public const LEGACY_TIMEZONE = 'Asia/Ho_Chi_Minh';

    public static function instant(mixed $value, ?string $naiveTimezone = null): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (! is_string($value) || ! preg_match('/\A(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}(?::?\d{2})?)?\z/D', $value, $parts)) {
            throw new InvalidArgumentException('Invalid absolute document timestamp.');
        }
        $fraction = str_pad($parts[3] ?? '', 6, '0');
        $offset = $parts[4] ?? '';
        if ($offset === 'Z') {
            $offset = '+00:00';
        } elseif ($offset !== '') {
            $digits = str_replace(':', '', substr($offset, 1));
            $hours = (int) substr($digits, 0, 2);
            $minutes = strlen($digits) === 4 ? (int) substr($digits, 2, 2) : 0;
            if ($hours > 14 || $minutes > 59 || ($hours === 14 && $minutes !== 0) || ($offset[0] === '-' && $hours === 0 && $minutes === 0)) {
                throw new InvalidArgumentException('Invalid or unknown document timestamp offset.');
            }
            $offset = sprintf('%s%02d:%02d', $offset[0], $hours, $minutes);
        }
        $text = $parts[1].' '.$parts[2].'.'.$fraction;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u'.($offset === '' ? '' : 'P'), $text.$offset, new DateTimeZone($naiveTimezone ?? self::legacyTimezone()));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s.u') !== $text) {
            throw new InvalidArgumentException('Invalid document calendar timestamp.');
        }

        return $date;
    }

    public static function utc(mixed $value, ?string $naiveTimezone = null): ?string
    {
        return self::instant($value, $naiveTimezone)?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** UTC, fixed-width, microsecond key; NOT a public timestamp without offset. */
    public static function key(mixed $value, ?string $naiveTimezone = null): ?string
    {
        return self::instant($value, $naiveTimezone)?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function legacyTimezone(): string
    {
        $zone = config('document_timestamps.legacy_naive_timezone', self::LEGACY_TIMEZONE);
        if (! in_array($zone, [self::LEGACY_TIMEZONE, 'UTC'], true)) {
            throw new InvalidArgumentException('Unreviewed legacy document timezone.');
        }

        return $zone;
    }

    public static function metadata(): array
    {
        return ['contract' => self::CONTRACT, 'format' => 'RFC3339_UTC',
            'legacy_naive_timezone' => self::legacyTimezone(), 'offset_policy' => 'PRESERVE_INSTANT',
            'fractional_digits' => 6, 'precision_policy' => 'PRESERVE_AVAILABLE_ZERO_PAD_NOT_EXTRA_ACCURACY',
            'null_policy' => 'PRESERVE_NULL'];
    }
}
