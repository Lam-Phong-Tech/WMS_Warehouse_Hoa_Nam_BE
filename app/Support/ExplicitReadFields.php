<?php

namespace App\Support;

use stdClass;

/** Versioned read representation: absent values have explicit reasons, never fake defaults. */
final class ExplicitReadFields
{
    public const CONTRACT = 'explicit-read-fields-v1';

    public const HEADER = 'X-Read-Fields-Contract';

    public static function project(stdClass $body): stdClass
    {
        if (isset($body->meta) && $body->meta instanceof stdClass && property_exists($body->meta, 'read_fields')) {
            ApiError::abort(503, 'READ_FIELD_NAMESPACE_CONFLICT', 'Owner metadata already uses the projection namespace; no fields are overwritten.');
        }
        $missing = [];
        $walk = function (mixed $node, string $pointer = '') use (&$walk, &$missing, $body): mixed {
            if (is_array($node)) {
                foreach ($node as $i => $value) {
                    // Removing/reindexing list elements would alter owner identities/counts.
                    if ($value === null) {
                        ApiError::abort(503, 'READ_FIELD_LIST_SOURCE_INVALID', 'A list contains an undefined element; no partial list is returned.');
                    }
                    $node[$i] = $walk($value, $pointer.'/'.$i);
                }

                return $node;
            }
            if (! $node instanceof stdClass) {
                return $node;
            }
            $copy = clone $node;
            foreach (get_object_vars($node) as $key => $value) {
                $path = $pointer.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
                if ($value === null) {
                    unset($copy->{$key});
                    $reason = self::reason($node, (string) $key, $path);
                    if ($path === '/data' && ($body->meta->shift_admission->current_state ?? null) === 'NO_ACTIVE_SHIFT') {
                        $reason['status'] = 'NO_ACTIVE_SHIFT';
                    }
                    $missing[] = $reason;
                    if (count($missing) > 10000) {
                        ApiError::abort(503, 'READ_FIELD_PROJECTION_LIMIT', 'Availability projection exceeds its declared limit; use a smaller page.');
                    }
                } else {
                    $copy->{$key} = $walk($value, $path);
                }
            }

            return $copy;
        };
        $result = $walk($body);
        if (! isset($result->meta)) {
            $result->meta = new stdClass;
        }
        if (! $result->meta instanceof stdClass) {
            ApiError::abort(503, 'READ_FIELD_META_SOURCE_INVALID', 'Owner metadata is not an object.');
        }
        $representation = (object) ['contract' => self::CONTRACT, 'missing_fields' => $missing,
            'value_policy' => 'KNOWN_VALUES_UNCHANGED_ABSENT_VALUES_EXPLAINED_NO_NULL_OR_FAKE_DEFAULTS'];
        if (isset($result->meta->contract)) {
            $representation->owner_contract = $result->meta->contract;
        }
        $result->meta->contract = self::CONTRACT;
        $result->meta->read_fields = $representation;

        return $result;
    }

    private static function reason(stdClass $parent, string $key, string $path): array
    {
        $status = 'UNSPECIFIED_BY_OWNER';
        $declared = $parent->{$key.'_status'} ?? $parent->{$key.'_availability'} ?? null;
        if (! is_string($declared) && in_array($key, ['display_name', 'name', 'value'], true)) {
            $declared = $parent->availability ?? null;
        }
        if (is_string($declared) && preg_match('/^[A-Z][A-Z0-9_]{0,99}$/D', $declared)) {
            $status = $declared;
        } elseif ($key === 'contained_qty' && ($parent->quantity_source ?? null) === 'container_items.ACTIVE'
            && is_string($parent->quantity_status ?? null)) {
            $status = $parent->quantity_status;
        } elseif ($key === 'posted_at' || $key === 'reviewed_at') {
            $status = 'NOT_RECORDED'; // Never manufacture time from POSTED/COMPLETED or now().
        } elseif (in_array($key, ['available_qty', 'reserved_qty'], true)
            && ($parent->quantity_semantics ?? null) === 'CONTAINER_CONTENTS_NOT_WAREHOUSE_BALANCE_OR_ISSUE_ELIGIBILITY') {
            $status = 'NO_AUTHORITATIVE_CONTAINER_AVAILABILITY_MODEL';
        } elseif ($key === 'capacity' && ($parent->model ?? null) === 'SINGLE_WAREHOUSE_WITH_BOXES') {
            $status = 'NOT_CONFIGURED';
        } elseif ($key === 'physical_hierarchy' && ($parent->model ?? null) === 'SINGLE_WAREHOUSE_WITH_BOXES') {
            $status = 'NOT_CONFIGURED';
        } elseif (in_array($key, ['next_cursor', 'nextCursor'], true)
            && (($parent->has_more ?? $parent->hasMore ?? null) === false)) {
            $status = 'END_OF_RESULTS';
        } elseif ($key === 'total' && ($parent->coverage ?? null) === 'PARTIAL_SOURCE') {
            $status = 'PARTIAL_SOURCE';
        } elseif ($key === 'check_token' && ($parent->can_end ?? null) === false) {
            $status = 'SERVER_BLOCKED';
        } elseif ($key === 'selectedActivity') {
            $status = 'NO_EVENT_SELECTED';
        } elseif ($key === 'current_shift_id' && ($parent->current_state ?? null) === 'NO_ACTIVE_SHIFT') {
            $status = 'NO_ACTIVE_SHIFT';
        } elseif ($key === 'unavailable_reason' && ($parent->new_tracked_start_supported ?? null) === true) {
            $status = 'NOT_APPLICABLE';
        } elseif (str_starts_with($path, '/meta/query/') && in_array($key, ['date_from', 'date_to'], true)) {
            $status = 'FILTER_NOT_APPLIED';
        }
        $reason = ['path' => $path, 'status' => $status, 'source' => 'AUTHORIZED_OWNER_RESPONSE', 'value_supplied' => false];
        if (is_string($declared) && preg_match('/^[A-Z][A-Z0-9_]{0,99}$/D', $declared)) {
            $reason['owner_status'] = $declared;
        }

        return $reason;
    }
}
