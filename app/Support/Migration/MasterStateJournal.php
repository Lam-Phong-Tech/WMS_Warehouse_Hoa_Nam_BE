<?php

namespace App\Support\Migration;

use App\Support\Security\PiiCrypto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AuditLog\Services\AuditLogger;
use RuntimeException;
use Throwable;

/**
 * Nhật ký trạng thái master của lần nạp dữ liệu cuối (WWHN-299 / DEV299-P1-01).
 *
 * Vấn đề đã đóng
 * --------------
 * Rollback cũ chỉ đảo `OPENING` movement rồi đóng Container/Item theo
 * `migration_run_id`. Agency/SKU/Physical_Code được ghi bằng `updateOrCreate`
 * nên:
 *   - bản ghi **đã tồn tại** bị package ghi đè và **không có đường quay lại** —
 *     rollback để nguyên tên/đơn vị/trạng thái mới của package;
 *   - bản ghi **mới tạo** ở lại vĩnh viễn, không ai biết nó của run nào.
 * Cả hai đều vi phạm SRS §13.12: "Rollback test must prove no subset
 * master/opening/movement remains".
 *
 * Cách làm
 * --------
 * Trước mỗi lần ghi master, chụp lại nguyên trạng bản ghi (hoặc đánh dấu CREATE
 * nếu chưa có) vào `audit_log` — bảng ERD 7, append-only, đúng ngữ nghĩa
 * `before_data`/`after_data` — với `request_id = migration_run_id` để tra ngược.
 * Không thêm bảng mới nên ERD vẫn đúng 59 bảng canonical
 * (`HoaNamErdSchema::TABLE_COUNT`, `ErdCompilerSourcePinTest`).
 *
 * Hai bảo vệ bắt buộc, vì đây là nguồn phục hồi chứ không phải log tham khảo:
 *   1. **Fail-closed**: ép `audit.write_policy = fail_closed` quanh mỗi lần ghi.
 *      Ghi audit hỏng ⇒ ném lỗi ⇒ transaction COMMIT rollback ⇒ không tồn tại
 *      master nào không có snapshot.
 *   2. **Chống redaction**: `AuditLogger::sanitize()` thay giá trị của các khoá
 *      nhạy cảm bằng `[REDACTED]`. Nếu một cột master trùng tên khoá nhạy cảm,
 *      phục hồi sẽ ghi `[REDACTED]` vào dữ liệu thật. Nên trước khi ghi phải
 *      khẳng định payload đi qua sanitize không đổi, sai thì dừng cutover.
 *
 * Cột trùng khoá nhạy cảm: niêm phong, không lộ và không mất
 * ---------------------------------------------------------
 * Lúc viết lớp này chưa cột master nào trùng. Một ngày sau, WWHN-222 thêm `phone`
 * vào `audit.sensitive_keys` và `agencies.phone` biến MỌI COMMIT có AGENCY thành
 * MIGRATION_SNAPSHOT_REDACTED (kể cả phone NULL, vì sanitize() redact theo KHOÁ).
 * Hai cách "sửa nhanh" đều sai: bỏ `phone` khỏi danh sách là đưa SĐT dạng rõ vào
 * audit_log; bỏ qua bảo vệ số 2 là để rollback ghi `[REDACTED]` vào dữ liệu thật.
 *
 * `seal()` chạy TRƯỚC bảo vệ số 2: mọi cột trùng `audit.sensitive_keys` (so khoá
 * đúng như sanitize()) được tách khỏi `state` và mã hoá bằng lớp PII WWHN-SEC-06
 * (`PiiCrypto`: keyring có phiên bản, AES-256-GCM) dưới khoá không nhạy cảm
 * `sealed_state`. Hệ quả:
 *   - audit_log chỉ chứa tên cột + ciphertext, không bao giờ chứa giá trị dạng rõ;
 *   - bảo vệ số 2 giữ nguyên: payload đã niêm phong đi qua sanitize() không đổi;
 *   - ciphertext gắn AAD = (run, bảng, bản ghi, seq, trước/sau). Chép nó sang
 *     snapshot khác thì `restore()` dừng với MIGRATION_SNAPSHOT_SEAL_INVALID thay vì
 *     ghi PII của bản ghi này vào bản ghi khác;
 *   - rollback cần đúng key id đã niêm phong: KHÔNG gỡ khoá khỏi PII keyring khi còn
 *     run COMMITTED có thể phải rollback.
 *
 * Giới hạn có thật của việc xoá
 * -----------------------------
 * `stock_movements` là append-only (DEC-REV-001): OPENING và REVERSAL của run
 * vẫn nằm lại và giữ khoá ngoại tới SKU. Một SKU do run tạo mới vì thế **không
 * thể xoá cứng** sau khi đã COMMIT. Trường hợp này rollback chuyển sang
 * disposition: đưa bản ghi về trạng thái đóng (`INACTIVE`/`CLOSED`/
 * `ADJUSTED_OUT`) và ghi rõ vào evidence là `LEDGER_PINNED`. Bản ghi nào không
 * xoá được **và** không disposition được thì bị tính là tồn dư thật và làm
 * rollback FAIL.
 */
class MasterStateJournal
{
    public const ACTION = 'MIGRATION_MASTER_SNAPSHOT';

    /**
     * Khoá chứa phần niêm phong của snapshot. KHÔNG được trùng `audit.sensitive_keys`;
     * nếu một ngày trùng thì assertNotRedacted() tự dừng cutover.
     */
    public const SEALED_KEY = 'sealed_state';

    /**
     * Trạng thái "đóng" hợp lệ theo CHECK constraint của từng bảng, dùng khi
     * bản ghi do run tạo ra bị ledger append-only giữ lại.
     */
    private const DISPOSITION = [
        'sku' => ['status', 'INACTIVE'],
        'agencies' => ['status', 'INACTIVE'],
        'physical_codes' => ['status', 'INACTIVE'],
        'containers' => ['status', 'CLOSED'],
        'item_instances' => ['item_status', 'ADJUSTED_OUT'],
        'import_batches' => ['status', 'CLOSED'],
    ];

    /**
     * Bảng chỉ được xoá khi bản ghi đã "sạch". Balance do cutover tạo có thể đã
     * được luồng khác dùng sau COMMIT; xoá một balance còn số dư là mất dữ liệu.
     */
    private const ZERO_ONLY_DISPOSABLE = [
        'inventory_balances' => ['quantity', 'on_hand_qty', 'available_qty', 'reserved_qty'],
    ];

    private int $seq = 0;

    public function __construct(
        private readonly string $runId,
        private readonly ?string $actorId = null,
    ) {}

    /** Chụp trạng thái của một master ghi qua Eloquent. */
    public function recordModel(Model $model, ?array $before): void
    {
        $this->record(
            $model->getTable(),
            $model->getKeyName(),
            (string) $model->getKey(),
            $before,
            $model->getAttributes(),
        );
    }

    /**
     * @param  array<string, mixed>|null  $before  null = bản ghi do run này tạo mới
     * @param  array<string, mixed>  $after
     */
    public function record(string $table, string $primaryKey, string $id, ?array $before, array $after): void
    {
        $seq = ++$this->seq;
        $envelope = self::seal([
            'seq' => $seq,
            'operation' => $before === null ? 'CREATE' : 'UPDATE',
            'table' => $table,
            'primary_key' => $primaryKey,
            'state' => $before === null ? null : self::scalarize($before),
        ], self::sealContext($this->runId, $table, $id, $seq, 'before'));
        $afterEnvelope = self::seal(
            ['state' => self::scalarize($after)],
            self::sealContext($this->runId, $table, $id, $seq, 'after'),
        );

        $this->assertNotRedacted($envelope, $table, $id);
        $this->assertNotRedacted($afterEnvelope, $table, $id);

        $previousPolicy = config('audit.write_policy');
        config(['audit.write_policy' => 'fail_closed']);

        try {
            AuditLogger::record(
                action: self::ACTION,
                objectType: $table,
                objectId: $id,
                actorId: $this->actorId,
                requestId: $this->runId,
                beforeData: $envelope,
                afterData: $afterEnvelope,
                reason: 'WWHN-299 final data load: snapshot master trước khi ghi (REQ-SRS-021, SRS §13.12).',
            );
        } finally {
            config(['audit.write_policy' => $previousPolicy]);
        }
    }

    /**
     * Đảo mọi thay đổi master của một run COMMIT: khôi phục bản ghi bị ghi đè và
     * loại bỏ bản ghi do run tạo mới, theo đúng thứ tự ngược với lúc ghi (con
     * trước cha) để không vướng khoá ngoại.
     *
     * @return array<string, mixed>
     */
    public static function restore(string $runId): array
    {
        $entries = self::entries($runId);

        $restored = 0;
        $deleted = 0;
        $missing = [];
        $disposed = [];
        $retained = [];

        foreach ($entries as $entry) {
            $table = (string) $entry['table'];
            $primaryKey = (string) $entry['primary_key'];
            $id = (string) $entry['object_id'];

            if ($entry['operation'] === 'UPDATE') {
                // Cột nhạy cảm nằm trong phần niêm phong chứ không nằm trong `state`.
                // Giá trị niêm phong (đã xác thực) thắng nếu trùng khoá.
                $state = self::unseal($entry, $runId) + (array) ($entry['state'] ?? []);
                unset($state[$primaryKey]);
                if ($state === []) {
                    continue;
                }

                $affected = DB::table($table)->where($primaryKey, $id)->update($state);
                if ($affected === 0) {
                    $missing[] = ['table' => $table, 'id' => $id, 'operation' => 'UPDATE'];

                    continue;
                }
                $restored++;

                continue;
            }

            $row = DB::table($table)->where($primaryKey, $id)->first();
            if ($row === null) {
                // Đã bị xoá trước đó (rerun rollback) — không còn tồn dư.
                continue;
            }

            if (! self::isDeletable($table, $row)) {
                $retained[] = ['table' => $table, 'id' => $id, 'reason' => 'NON_ZERO_STATE'];

                continue;
            }

            if (self::tryDelete($table, $primaryKey, $id)) {
                $deleted++;

                continue;
            }

            // Không xoá được: ledger append-only hoặc dữ liệu phát sinh sau
            // COMMIT đang giữ khoá ngoại. Chuyển sang disposition có kiểm soát.
            $disposition = self::tryDispose($table, $primaryKey, $id);
            if ($disposition === null) {
                $retained[] = ['table' => $table, 'id' => $id, 'reason' => 'HAS_DEPENDENTS'];

                continue;
            }

            $disposed[] = [
                'table' => $table,
                'id' => $id,
                'reason' => 'LEDGER_PINNED',
                'status_column' => $disposition[0],
                'status_value' => $disposition[1],
            ];
        }

        return [
            'snapshots' => count($entries),
            'restored_rows' => $restored,
            'deleted_rows' => $deleted,
            'disposed_rows' => $disposed,
            'missing_rows' => $missing,
            'retained_rows' => $retained,
        ];
    }

    /**
     * Số bản ghi do run tạo mới mà vẫn còn **đang hoạt động** sau rollback. Bản
     * ghi đã disposition không tính là tồn dư vì nó không còn dùng được và vẫn
     * giữ được dấu vết audit; bằng 0 là bằng chứng của SRS §13.12.
     *
     * @param  list<string>  $disposedKeys  danh sách "table|id" đã disposition
     */
    public static function residualCreatedRows(string $runId, array $disposedKeys = []): int
    {
        $disposedKeys = array_flip($disposedKeys);
        $residual = 0;

        foreach (self::entries($runId) as $entry) {
            if ($entry['operation'] !== 'CREATE') {
                continue;
            }
            if (isset($disposedKeys[$entry['table'].'|'.$entry['object_id']])) {
                continue;
            }
            $exists = DB::table((string) $entry['table'])
                ->where((string) $entry['primary_key'], (string) $entry['object_id'])
                ->exists();
            if ($exists) {
                $residual++;
            }
        }

        return $residual;
    }

    /**
     * @return list<array{seq:int, operation:string, table:string, primary_key:string, object_id:string, state:array<string, mixed>|null, sealed:array<string, mixed>|null}>
     */
    public static function entries(string $runId): array
    {
        $rows = DB::table('audit_log')
            ->where('action', self::ACTION)
            ->where('request_id', $runId)
            ->get(['object_id', 'before_data']);

        $entries = [];
        foreach ($rows as $row) {
            $payload = $row->before_data;
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            if (! is_array($payload) || ! isset($payload['table'], $payload['primary_key'], $payload['operation'])) {
                continue;
            }
            $entries[] = [
                'seq' => (int) ($payload['seq'] ?? 0),
                'operation' => (string) $payload['operation'],
                'table' => (string) $payload['table'],
                'primary_key' => (string) $payload['primary_key'],
                'object_id' => (string) $row->object_id,
                'state' => is_array($payload['state'] ?? null) ? $payload['state'] : null,
                // Chỉ giải mã khi thật sự phục hồi (restore): đếm tồn dư không cần PII.
                'sealed' => is_array($payload[self::SEALED_KEY] ?? null) ? $payload[self::SEALED_KEY] : null,
            ];
        }

        // Ngược thứ tự ghi: balance và item trước container, container trước
        // physical code, và mọi thứ trước SKU/Agency.
        usort($entries, static fn (array $a, array $b): int => $b['seq'] <=> $a['seq']);

        return $entries;
    }

    private static function tryDelete(string $table, string $primaryKey, string $id): bool
    {
        try {
            // Transaction lồng ⇒ SAVEPOINT: khoá ngoại nổ chỉ rollback tới đây,
            // transaction rollback tổng vẫn ghi tiếp được.
            DB::transaction(fn () => DB::table($table)->where($primaryKey, $id)->delete());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{0:string, 1:string}|null cột và giá trị đã ghi, null nếu không disposition được */
    private static function tryDispose(string $table, string $primaryKey, string $id): ?array
    {
        $disposition = self::DISPOSITION[$table] ?? null;
        if ($disposition === null || ! Schema::hasColumn($table, $disposition[0])) {
            return null;
        }

        try {
            DB::transaction(fn () => DB::table($table)
                ->where($primaryKey, $id)
                ->update([$disposition[0] => $disposition[1]]));

            return $disposition;
        } catch (Throwable) {
            return null;
        }
    }

    private static function isDeletable(string $table, object $row): bool
    {
        $columns = self::ZERO_ONLY_DISPOSABLE[$table] ?? null;
        if ($columns === null) {
            return true;
        }

        foreach ($columns as $column) {
            if ((int) ($row->{$column} ?? 0) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $payload */
    private function assertNotRedacted(array $payload, string $table, string $id): void
    {
        if (AuditLogger::sanitize($payload) !== $payload) {
            throw new RuntimeException(sprintf(
                'MIGRATION_SNAPSHOT_REDACTED: cột của %s (%s) trùng khoá nhạy cảm trong config/audit.php; '
                .'snapshot sẽ mất dữ liệu gốc nên cutover dừng lại.',
                $table,
                $id,
            ));
        }
    }

    /**
     * Tách các cột trùng `audit.sensitive_keys` ra khỏi `state` rồi niêm phong chúng
     * bằng PiiCrypto dưới SEALED_KEY. Không có cột trùng thì envelope giữ nguyên, nên
     * snapshot của bảng không mang PII không phụ thuộc vào keyring.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private static function seal(array $envelope, string $context): array
    {
        $state = $envelope['state'] ?? null;
        if (! is_array($state)) {
            return $envelope;
        }

        $sensitiveKeys = self::sensitiveKeys();
        $sealed = [];
        foreach ($state as $column => $value) {
            if (in_array(strtolower((string) $column), $sensitiveKeys, true)) {
                $sealed[$column] = $value;
                unset($state[$column]);
            }
        }

        if ($sealed === []) {
            return $envelope;
        }

        $envelope['state'] = $state;
        $envelope[self::SEALED_KEY] = [
            'columns' => array_keys($sealed),
            'ciphertext' => PiiCrypto::encrypt(
                json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $context,
            ),
        ];

        return $envelope;
    }

    /**
     * Mở phần niêm phong của snapshot `before`. Fail-closed: ciphertext hỏng, sai khoá,
     * bị chép từ snapshot khác hoặc danh sách cột bị sửa đều dừng rollback (transaction
     * tổng huỷ mọi phục hồi đã làm) thay vì ghi dữ liệu sai vào bản ghi.
     *
     * @param  array{seq:int, table:string, object_id:string, sealed:array<string, mixed>|null}  $entry
     * @return array<string, mixed>
     */
    private static function unseal(array $entry, string $runId): array
    {
        $sealed = $entry['sealed'] ?? null;
        if ($sealed === null) {
            return [];
        }

        // Thông điệp chỉ mang bảng + id, không bao giờ mang giá trị.
        $message = sprintf(
            'MIGRATION_SNAPSHOT_SEAL_INVALID: không mở được phần niêm phong của %s (%s); '
            .'rollback dừng để không ghi dữ liệu sai vào bản ghi.',
            $entry['table'],
            $entry['object_id'],
        );

        $ciphertext = $sealed['ciphertext'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            throw new RuntimeException($message);
        }

        try {
            $columns = json_decode(
                (string) PiiCrypto::decrypt(
                    $ciphertext,
                    self::sealContext($runId, $entry['table'], $entry['object_id'], $entry['seq'], 'before'),
                ),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $error) {
            throw new RuntimeException($message, 0, $error);
        }

        if (! is_array($columns) || array_keys($columns) !== ($sealed['columns'] ?? null)) {
            throw new RuntimeException($message);
        }

        return $columns;
    }

    /**
     * AAD của phần niêm phong: gắn ciphertext vào đúng snapshot (run, bảng, bản ghi,
     * seq, trước/sau) để bản chép sang snapshot khác không giải mã được.
     */
    private static function sealContext(string $runId, string $table, string $id, int $seq, string $phase): string
    {
        return implode('|', ['audit_log', self::ACTION, $runId, $table, $id, (string) $seq, $phase]);
    }

    /**
     * Cùng cách so khoá với AuditLogger::sanitize(): hạ chữ thường, khớp nguyên khoá.
     *
     * @return list<string>
     */
    private static function sensitiveKeys(): array
    {
        return array_values(array_map(
            static fn (string $key): string => strtolower($key),
            (array) config('audit.sensitive_keys', []),
        ));
    }

    /**
     * Chuẩn hoá về kiểu JSON được: DateTime/enum/object của Eloquent phải thành
     * chuỗi thì mới ghi và đọc lại nguyên vẹn.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function scalarize(array $attributes): array
    {
        $scalar = [];
        foreach ($attributes as $column => $value) {
            $scalar[(string) $column] = match (true) {
                $value === null, is_scalar($value) => $value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
                $value instanceof \BackedEnum => $value->value,
                default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            };
        }

        return $scalar;
    }
}
