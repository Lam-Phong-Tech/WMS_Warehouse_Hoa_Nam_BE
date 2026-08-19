<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEV199-P2-01 — hợp nhất hai unique index tương đương trên `outbound_item_matches`.
 *
 * Trạng thái trước migration này (xác minh bằng pg_indexes trên DB candidate
 * `hn_wwhn199` dựng mới từ migrate --force, không suy đoán):
 *
 *   uk_outbound_match_active_item  CREATE UNIQUE INDEX ... ON outbound_item_matches (item_id)
 *                                  WHERE item_id IS NOT NULL AND status = 'ACTIVE'   -- 000670:291
 *   ux_erd_outbound_active_item    CREATE UNIQUE INDEX ... ON outbound_item_matches (item_id)
 *                                  WHERE item_id IS NOT NULL AND status = 'ACTIVE'   -- 000770:238
 *
 * Sau khi PostgreSQL chuẩn hoá, hai định nghĩa giống nhau từng ký tự: cùng bảng, cùng
 * cột, cùng predicate. Chúng không phải hai lớp phòng thủ mà là một ràng buộc bị vật
 * chất hoá hai lần — mỗi INSERT phải bảo trì hai B-tree, và evidence index trở nên mơ
 * hồ (index nào là nguồn sự thật khi đọc pg_indexes).
 *
 * Giữ `ux_erd_outbound_active_item` vì đó là tên neo theo ERD (bảng 41) và khớp đối
 * xứng với phía inbound `ux_erd_inbound_active_item` (bảng 28, migration
 * 2026_08_18_193200). Bỏ `uk_outbound_match_active_item`.
 *
 * Vì sao được phép dọn ở migration tiến (không sửa 000670/000770 đã chạy): chính repo
 * đã đặt tiền lệ ở `2026_08_10_000710_enforce_cross_aggregate_business_guards.php:14`
 * — nó `DROP INDEX IF EXISTS uk_inbound_scan_active_item` (index do 000670 tạo) để
 * thay bằng trigger. Migration đã áp dụng là bất biến; thay đổi trạng thái schema luôn
 * đi bằng bước tiến mới. Cả 000670 và 000770 đều có `down()` rỗng (fail-forward), nên
 * không tồn tại đường rollback để sửa tại chỗ — xem
 * docs/evidence/WWHN-199/PG_MIGRATION_AND_INDEX_EVIDENCE.md §4.
 *
 * Fail-closed hai tầng trước khi DROP:
 *   1. index giữ lại phải đang tồn tại;
 *   2. hai định nghĩa phải THỰC SỰ tương đương (so sánh cột + tập atom của predicate
 *      sau chuẩn hoá). Nếu chúng đã phân kỳ, migration ném lỗi thay vì âm thầm gỡ mất
 *      một ràng buộc khác nghĩa.
 *
 * Sau khi dọn, item vẫn còn 4 lớp chặn "một item chỉ thuộc một phiếu xuất đang mở":
 *   1. row lock `item_instances` trong `resolveItem()`;
 *   2. `assertItemAvailableForIssue()` → 409 ITEM_ALREADY_MATCHED;
 *   3. trigger `trg_erd_outbound_item_exclusive`;
 *   4. unique index `ux_erd_outbound_active_item` (được giữ lại).
 */
return new class extends Migration
{
    private const TABLE = 'outbound_item_matches';

    private const KEEP = 'ux_erd_outbound_active_item';

    private const DROP = 'uk_outbound_match_active_item';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        $keep = $this->indexDefinition(self::KEEP);
        $drop = $this->indexDefinition(self::DROP);

        // Bản sao đã được dọn ở môi trường này (hoặc chưa bao giờ tồn tại) → không làm gì.
        if ($drop === null) {
            return;
        }

        // Fail-closed 1: chỉ được bỏ bản sao khi bản giữ lại thật sự đang tồn tại.
        // Nếu không, migration này sẽ âm thầm gỡ backstop cuối cùng.
        if ($keep === null) {
            throw new RuntimeException(
                'DEV199-P2-01: khong tim thay '.self::KEEP.' tren '.self::TABLE.'. '.
                'Khong duoc bo '.self::DROP.' khi chua co index thay the — chay lai migration '.
                '2026_08_10_000770_enforce_erd_1_0_keys_relationships_and_constraints truoc.'
            );
        }

        // Fail-closed 2: chỉ hợp nhất khi hai index thật sự nói cùng một điều.
        if ($this->fingerprint($keep) !== $this->fingerprint($drop)) {
            throw new RuntimeException(
                'DEV199-P2-01: hai index khong tuong duong nen khong duoc hop nhat.'.PHP_EOL.
                'KEEP: '.$keep.PHP_EOL.
                'DROP: '.$drop
            );
        }

        DB::statement('DROP INDEX IF EXISTS '.self::DROP);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        // Khôi phục nguyên trạng bản sao: `down()` phải đưa schema về đúng hình dạng
        // trước bước tiến này, kể cả khi hình dạng đó là dư thừa.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::DROP.' ON '.self::TABLE.' (item_id) '.
            "WHERE item_id IS NOT NULL AND status = 'ACTIVE'"
        );
    }

    private function indexDefinition(string $name): ?string
    {
        $definition = DB::table('pg_indexes')
            ->whereRaw('schemaname = current_schema()')
            ->where('tablename', self::TABLE)
            ->where('indexname', $name)
            ->value('indexdef');

        return $definition === null ? null : (string) $definition;
    }

    /**
     * Rút gọn một `indexdef` về phần mang nghĩa: có UNIQUE hay không, danh sách cột,
     * và TẬP các atom của predicate (đã sắp xếp — `A AND B` phải bằng `B AND A`).
     * Tên index bị loại bỏ vì đó chính là thứ duy nhất được phép khác nhau.
     */
    private function fingerprint(string $indexDefinition): string
    {
        $unique = str_contains(strtoupper($indexDefinition), 'CREATE UNIQUE INDEX') ? 'UNIQUE' : 'NONUNIQUE';

        $columns = '';
        if (preg_match('/USING\s+\w+\s*\((?P<cols>.*?)\)\s*(WHERE|$)/is', $indexDefinition, $matches) === 1) {
            $columns = $this->squeeze($matches['cols']);
        }

        $atoms = [];
        if (preg_match('/\sWHERE\s+(?P<pred>.*)$/is', $indexDefinition, $matches) === 1) {
            $predicate = $this->stripOuterParentheses($this->squeeze($matches['pred']));

            foreach (preg_split('/\s+AND\s+/i', $predicate) ?: [] as $atom) {
                $atom = $this->stripOuterParentheses(trim($atom));
                if ($atom !== '') {
                    $atoms[] = strtolower($atom);
                }
            }
            sort($atoms);
        }

        return $unique.'|'.strtolower($columns).'|'.implode(' & ', $atoms);
    }

    private function stripOuterParentheses(string $value): string
    {
        while (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $inner = substr($value, 1, -1);
            if (! $this->parenthesesBalanced($inner)) {
                break;
            }
            $value = trim($inner);
        }

        return $value;
    }

    private function squeeze(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function parenthesesBalanced(string $value): bool
    {
        $depth = 0;
        foreach (str_split($value) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }
};
