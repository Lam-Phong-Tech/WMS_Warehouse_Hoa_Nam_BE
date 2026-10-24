<?php

namespace App\Casts;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Explicit-offset writes and UTC reads for security instants, not calendar dates. */
final class UtcInstant implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC')->utc();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        $at = $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, 'UTC');

        return $at->utc()->format('Y-m-d H:i:s.uP');
    }
}
