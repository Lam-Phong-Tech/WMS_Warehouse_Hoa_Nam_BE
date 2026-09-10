<?php

namespace Modules\AuditLog\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Throwable;

/**
 * Tra ngược MÃ NGHIỆP VỤ của đối tượng trong nhật ký hệ thống (`object_code`).
 *
 * Bảng `audit_log` chỉ lưu `object_type` + `object_id` (UUID). Số phiếu chỉ có mặt
 * trong `after_data` khi nơi ghi nhật ký tự thêm vào — đo DB dev 10/09/2026: 553/1089
 * nhật ký phiếu nhập/xuất kho có `doc_no` (Ghi sổ, Huỷ, luồng chuyển kho... không
 * ghi), còn phiếu xuất linh kiện, phiếu đảo, hồ sơ bảo hành, hiện vật thì KHÔNG bản
 * ghi nào có. Màn "Chi tiết nhật ký hệ thống" vì vậy chỉ còn UUID để hiển thị —
 * người dùng kho không tra cứu được gì từ chuỗi đó.
 *
 * Tra lúc ĐỌC, không đụng dữ liệu append-only đã ghi: gom `object_id` của cả trang
 * theo bảng nguồn, mỗi bảng MỘT truy vấn `whereIn` — trang 100 dòng tốn vài truy vấn,
 * không N+1. Không tra được (loại chưa có mã, bản ghi gốc không còn) thì
 * `object_code = null` để FE tự quyết định cách hiển thị.
 */
final class AuditObjectReferenceResolver
{
    /**
     * `object_type` đã chuẩn hoá → [bảng/view, cột khoá, cột mã theo thứ tự ưu tiên].
     *
     * Đối chiếu DB dev 10/09/2026: với mọi `object_id` có giá trị, stock_document
     * 1089/1089, item_instance 3816/3816, component_issue_document 240/240,
     * reversal_document 61/61, warranty_case 48/48 đều khớp khoá và có mã.
     */
    private const SOURCES = [
        'stock_document' => ['stock_documents', 'id', ['doc_no']],
        'inbound_document' => ['stock_documents', 'id', ['doc_no']],
        'outbound_document' => ['stock_documents', 'id', ['doc_no']],
        'component_issue_document' => ['component_issue_documents', 'id', ['component_issue_no']],
        'reversal_document' => ['reversal_documents', 'id', ['reversal_no']],
        'warranty_case' => ['warranty_case', 'warranty_case_id', ['warranty_case_code']],
        'item_instance' => ['item_instances', 'id', ['serial_number', 'item_code']],
        'sku' => ['skus', 'id', ['sku_code']],
        'warehouse' => ['warehouses', 'id', ['warehouse_code', 'code']],
        'container' => ['containers', 'id', ['container_code']],
        'agency' => ['agencies', 'id', ['agency_code']],
        'product' => ['products', 'id', ['code']],
        'app_user' => ['app_user', 'user_id', ['email', 'username']],
        'user' => ['app_user', 'user_id', ['email', 'username']],
        'document_attachment' => ['document_attachments', 'id', ['file_name']],
    ];

    /**
     * Giá trị thật lộn xộn giữa tên bảng số ít (`stock_document`), số nhiều (`users`,
     * `warehouses`) và slug route (`label-print-events`) — quy về snake_case số ít.
     */
    public static function normalizeType(?string $objectType): string
    {
        $key = strtolower(trim((string) $objectType));
        $key = (string) preg_replace('/[\s\-]+/', '_', $key);

        if (isset(self::SOURCES[$key])) {
            return $key;
        }
        if (str_ends_with($key, 'ies')) {
            return substr($key, 0, -3).'y';
        }
        if (str_ends_with($key, 's')) {
            return substr($key, 0, -1);
        }

        return $key;
    }

    /**
     * Gắn `object_code` (string|null) lên từng bản ghi. Khoá luôn có mặt, kể cả khi
     * null, để hợp đồng response ổn định.
     *
     * @param  iterable<AuditLog>  $logs
     */
    public function attach(iterable $logs): void
    {
        /** @var array<string, array{0: string, 1: array<int, string>, 2: array<int, AuditLog>}> $groups */
        $groups = [];

        foreach ($logs as $log) {
            $log->setAttribute('object_code', null);

            $source = self::SOURCES[self::normalizeType($log->object_type)] ?? null;
            $objectId = (string) ($log->object_id ?? '');
            // Cột khoá của mọi bảng nguồn là UUID: giá trị lạ đưa vào whereIn sẽ làm
            // PostgreSQL ném "invalid input syntax for type uuid" cho CẢ trang.
            if ($source === null || ! Str::isUuid($objectId)) {
                continue;
            }

            [$table, $keyColumn, $codeColumns] = $source;
            $groups[$table] ??= [$keyColumn, $codeColumns, []];
            $groups[$table][2][] = $log;
        }

        foreach ($groups as $table => [$keyColumn, $codeColumns, $group]) {
            $ids = array_values(array_unique(array_map(
                static fn (AuditLog $log): string => strtolower((string) $log->object_id),
                $group,
            )));
            $codes = $this->lookup($table, $keyColumn, $codeColumns, $ids);

            foreach ($group as $log) {
                $log->setAttribute('object_code', $codes[strtolower((string) $log->object_id)] ?? null);
            }
        }
    }

    /**
     * @param  array<int, string>  $codeColumns
     * @param  array<int, string>  $ids
     * @return array<string, string> id (chữ thường) → mã
     */
    private function lookup(string $table, string $keyColumn, array $codeColumns, array $ids): array
    {
        $codes = [];

        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                // Lấy cả dòng thay vì liệt kê cột: tên cột mã khác nhau giữa schema
                // PostgreSQL và SQLite của test (vd warehouses.warehouse_code).
                foreach (DB::table($table)->whereIn($keyColumn, $chunk)->get() as $row) {
                    $row = (array) $row;
                    foreach ($codeColumns as $column) {
                        $value = trim((string) ($row[$column] ?? ''));
                        if ($value !== '') {
                            $codes[strtolower((string) $row[$keyColumn])] = $value;
                            break;
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            // Không tra được mã KHÔNG được làm hỏng màn nhật ký: bỏ qua bảng này.
            Log::warning('AUDIT_OBJECT_CODE_LOOKUP_FAILED', [
                'table' => $table,
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ]);
        }

        return $codes;
    }
}
