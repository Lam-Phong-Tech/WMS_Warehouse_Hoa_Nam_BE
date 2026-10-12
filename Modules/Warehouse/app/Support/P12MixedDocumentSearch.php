<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Normalizer;
use PDO;

/** Unicode NFC, case-insensitive but accent-sensitive; all row matching stays in SQL. */
final class P12MixedDocumentSearch
{
    public const TYPE_TERMS = [
        'INBOUND' => ['INBOUND', 'PN', 'Nhập', 'Nhập kho', 'Phiếu nhập', 'Phiếu nhập kho'],
        'OUTBOUND' => ['OUTBOUND', 'PX', 'Xuất', 'Xuất kho', 'Phiếu xuất', 'Phiếu xuất kho'],
        'WARRANTY' => ['WARRANTY', 'BH', 'Bảo hành', 'Hồ sơ bảo hành'],
    ];

    public static function normalize(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            ApiError::abort(422, 'DOCUMENTS_SEARCH_ENCODING_INVALID', 'Nội dung tìm kiếm phải là UTF-8 hợp lệ.');
        }
        $normalized = Normalizer::normalize(trim($value), Normalizer::FORM_C);
        if ($normalized === false) {
            ApiError::abort(422, 'DOCUMENTS_SEARCH_ENCODING_INVALID', 'Không chuẩn hóa được nội dung tìm kiếm.');
        }

        return mb_strtolower($normalized, 'UTF-8');
    }

    public static function prepare(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        // SQLite's built-in LOWER is ASCII-only. Connection-local functions do
        // not create schema or write data; register on the actual query PDOs.
        $connection = DB::connection();
        $pdos = [$connection->getPdo(), $connection->getReadPdo()];
        $seen = [];
        foreach ($pdos as $pdo) {
            if (isset($seen[spl_object_id($pdo)])) {
                continue;
            }
            $seen[spl_object_id($pdo)] = true;
            $ok = $pdo->sqliteCreateFunction('p12_nfc_lower', static function (?string $value): ?string {
                if ($value === null) {
                    return null;
                }
                $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
                if ($normalized === false) {
                    ApiError::abort(503, 'DOCUMENTS_SOURCE_UNAVAILABLE', 'Nguồn tìm kiếm có văn bản không hợp lệ.');
                }

                return mb_strtolower($normalized, 'UTF-8');
            }, 1, PDO::SQLITE_DETERMINISTIC);
            if (! $ok) {
                ApiError::abort(503, 'DOCUMENTS_SEARCH_UNAVAILABLE', 'Chưa sẵn sàng nguồn tìm kiếm Unicode.');
            }
        }
    }

    public static function contains(string $column): string
    {
        // Only source-owned column identifiers, never request strings.
        if (! preg_match('/^[a-z_]+\.[a-z_]+$/D', $column)) {
            throw new \LogicException('Untrusted mixed document search column.');
        }
        $fold = DB::getDriverName() === 'pgsql' ? 'LOWER(NORMALIZE('.$column.', NFC))' : 'p12_nfc_lower('.$column.')';

        return $fold." LIKE ? ESCAPE '!'";
    }

    public static function typeMatches(string $source, string $query): bool
    {
        if ($query === '') {
            return false;
        }
        foreach (self::TYPE_TERMS[$source] as $term) {
            if (str_contains(self::normalize($term), $query)) {
                return true;
            }
        }

        return false;
    }
}
