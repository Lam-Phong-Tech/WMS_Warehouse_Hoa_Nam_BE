<?php

use App\Support\Security\PiiCrypto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Support\WarrantyCasePii;

/**
 * WWHN-SEC-06 · Phase 6 — xoá hẳn `customer_name` / `customer_phone` /
 * `customer_address` khỏi bảng `warranty_cases`.
 *
 * ĐÂY LÀ BƯỚC LÀM CHO VIỆC MÃ HOÁ CÓ TÁC DỤNG THẬT. Trước migration này, bản
 * dump vẫn đọc được tên, số điện thoại và địa chỉ khách hàng của mọi hồ sơ cũ —
 * ciphertext mới chỉ nằm bên cạnh chứ chưa thay thế gì.
 *
 * FAIL-CLOSED: từ chối chạy nếu còn hồ sơ chưa mã hoá, hoặc nếu mẫu kiểm tra
 * không giải mã được bằng khoá của chính môi trường đang deploy. Deploy fail ở
 * đây là kết quả ĐÚNG — nó buộc người vận hành chạy
 * `php artisan pii:warranty-cases --encrypt` rồi `--verify` trước.
 *
 * ROLLBACK THẬT: `down()` dựng lại ba cột và GIẢI MÃ dữ liệu đổ về, đồng thời
 * đưa view/trigger legacy về đúng hình dạng cũ.
 */
return new class extends Migration
{
    private const BASE_TABLE = 'warranty_cases';

    private const VIEW = 'warranty_case';

    private const CONTEXT = [
        'customer_name' => 'warranty_cases.customer_name',
        'customer_phone' => 'warranty_cases.customer_phone',
        'customer_address' => 'warranty_cases.customer_address',
    ];

    public function up(): void
    {
        $table = $this->targetRelation();

        if ($table === null || ! Schema::hasColumn($table, 'customer_name')) {
            return;
        }

        $this->assertMigrationComplete($table);

        // Dọn nốt phần plaintext còn sót trước khi DROP. Không bắt buộc về mặt kỹ
        // thuật (DROP xoá hết) nhưng giữ cho trạng thái trung gian luôn hợp lệ
        // nếu migration bị dừng giữa chừng vì lý do hạ tầng.
        DB::table($table)->whereNotNull('customer_name_ciphertext')->update([
            'customer_name' => null,
            'customer_phone' => null,
            'customer_address' => null,
        ]);

        if ($this->isPostgres()) {
            // View và trigger đang tham chiếu ba cột này — phải thôi tham chiếu
            // TRƯỚC khi DROP, nếu không PostgreSQL từ chối với
            // "cannot drop column ... because other objects depend on it".
            $this->rebuildLegacyView(withPlaintext: false);
            $this->rebuildLegacyWriteTrigger(withPlaintext: false);
            $this->attachLegacyWriteTrigger();
        }

        // sqlite có bố cục NGƯỢC: bảng là `warranty_case`, còn `warranty_cases`
        // là view alias legacy. Nó cũng tham chiếu ba cột sắp bị xoá, và sqlite
        // báo lỗi rất khó đọc — "error in view warranty_cases after drop column".
        $dependentViews = $this->dropDependentViews();

        // ...và index cũ trên `customer_phone`. PostgreSQL tự gỡ index khi xoá
        // cột; sqlite thì KHÔNG và báo "error in index ... after drop column".
        // Index này mất đi là đúng ý đồ: vai trò tra cứu đã chuyển sang
        // `idx_warranty_cases_customer_phone_lookup` (blind index).
        $this->dropDependentIndexes($table);

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            foreach (['customer_name', 'customer_phone', 'customer_address'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });

        $this->recreateViewsWithoutPlaintext($dependentViews);
    }

    public function down(): void
    {
        $table = $this->targetRelation();

        if ($table === null) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (! Schema::hasColumn($table, 'customer_name')) {
                $blueprint->string('customer_name', 200)->nullable();
            }
            if (! Schema::hasColumn($table, 'customer_phone')) {
                $blueprint->string('customer_phone', 30)->nullable();
            }
            if (! Schema::hasColumn($table, 'customer_address')) {
                $blueprint->text('customer_address')->nullable();
            }
        });

        if ($this->isPostgres()) {
            $this->rebuildLegacyView(withPlaintext: true);
            $this->rebuildLegacyWriteTrigger(withPlaintext: true);
            $this->attachLegacyWriteTrigger();
        }

        $key = $table === self::BASE_TABLE ? 'id' : 'warranty_case_id';

        // Đổ dữ liệu về từ ciphertext — nếu bỏ bước này thì rollback để lại ba cột
        // rỗng và bản build cũ (đọc plaintext) sẽ thấy toàn bộ khách hàng biến mất.
        DB::table($table)
            ->whereNotNull('customer_name_ciphertext')
            ->orderBy($key)
            ->chunkById(500, function ($rows) use ($table, $key): void {
                foreach ($rows as $row) {
                    DB::table($table)->where($key, $row->{$key})->update([
                        'customer_name' => PiiCrypto::decrypt($row->customer_name_ciphertext, self::CONTEXT['customer_name']),
                        'customer_phone' => PiiCrypto::decrypt($row->customer_phone_ciphertext, self::CONTEXT['customer_phone']),
                        'customer_address' => PiiCrypto::decrypt($row->customer_address_ciphertext, self::CONTEXT['customer_address']),
                    ]);
                }
            }, $key);
    }

    private function assertMigrationComplete(string $table): void
    {
        $pending = DB::table($table)
            ->whereNull('customer_name_ciphertext')
            ->where(function ($query): void {
                $query->whereNotNull('customer_name')->orWhereNotNull('customer_phone');
            })
            ->count();

        if ($pending > 0) {
            throw new RuntimeException(
                'WWHN-SEC-06 Phase 6 bị chặn: còn '.$pending.' hồ sơ bảo hành chưa được mã hoá. '
                .'Chạy `php artisan pii:warranty-cases --encrypt` rồi `--verify` trước khi migrate.'
            );
        }

        $key = $table === self::BASE_TABLE ? 'id' : 'warranty_case_id';

        $sample = DB::table($table)
            ->whereNotNull('customer_name_ciphertext')
            ->limit(25)
            ->get([$key, 'customer_name_ciphertext', 'customer_phone_ciphertext']);

        foreach ($sample as $row) {
            try {
                PiiCrypto::decrypt($row->customer_name_ciphertext, self::CONTEXT['customer_name']);
                PiiCrypto::decrypt($row->customer_phone_ciphertext, self::CONTEXT['customer_phone']);
            } catch (Throwable $error) {
                throw new RuntimeException(
                    'WWHN-SEC-06 Phase 6 bị chặn: ciphertext không giải mã được bằng keyring của môi trường này '
                    .'('.$key.'='.$row->{$key}.'). Kiểm tra PII_ENCRYPTION_KEYS. '
                    .'Xoá plaintext lúc này sẽ mất dữ liệu vĩnh viễn.',
                    0,
                    $error,
                );
            }
        }

        // Blind index phải đầy đủ trước khi bỏ plaintext: sau Phase 6 nó là cách
        // DUY NHẤT còn tìm được hồ sơ theo tên/số điện thoại. Thiếu nó thì màn
        // WAR-01 im lặng trả rỗng cho đúng những hồ sơ chưa index.
        $missingLookup = DB::table($table)
            ->whereNotNull('customer_name_ciphertext')
            ->whereNull('customer_phone_lookup')
            ->whereNotNull('customer_phone_ciphertext')
            ->count();

        if ($missingLookup > 0) {
            throw new RuntimeException(
                'WWHN-SEC-06 Phase 6 bị chặn: '.$missingLookup.' hồ sơ có ciphertext nhưng thiếu blind index SĐT. '
                .'Chạy lại `php artisan pii:warranty-cases --encrypt`.'
            );
        }
    }

    private function targetRelation(): ?string
    {
        // Bố cục ngược nhau giữa hai driver — xem WarrantyCasePii::baseTable().
        $table = WarrantyCasePii::baseTable();

        return Schema::hasTable($table) ? $table : null;
    }

    /**
     * Gỡ mọi VIEW đang tham chiếu ba cột plaintext và trả lại định nghĩa của
     * chúng để dựng lại sau khi DROP COLUMN.
     *
     * Cần cho sqlite (view alias `warranty_cases` do migration ERD legacy tạo).
     * Trên PostgreSQL view `warranty_case` đã được dựng lại ở bước trên nên hàm
     * này không tìm thấy gì.
     *
     * @return array<string, string> tên view => câu CREATE VIEW gốc
     */
    private function dropDependentViews(): array
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return [];
        }

        $views = [];

        foreach (DB::select("select name, sql from sqlite_master where type = 'view'") as $view) {
            $sql = (string) $view->sql;

            if (preg_match('/\bcustomer_(name|phone|address)\b/', $sql) !== 1) {
                continue;
            }

            $views[(string) $view->name] = $sql;
            DB::statement('DROP VIEW IF EXISTS "'.$view->name.'"');
        }

        return $views;
    }

    /**
     * Gỡ index đang tham chiếu ba cột plaintext.
     *
     * PostgreSQL tự dọn index khi DROP COLUMN nên hàm này là no-op ở đó; sqlite
     * thì để lại index treo và từ chối lệnh DROP với thông báo khó đọc
     * ("error in index ... after drop column: no such column").
     */
    private function dropDependentIndexes(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $indexes = DB::select(
            "select name, sql from sqlite_master where type = 'index' and tbl_name = ? and sql is not null",
            [$table],
        );

        foreach ($indexes as $index) {
            if (preg_match('/\bcustomer_(?:name|phone|address)\b(?!_)/', (string) $index->sql) === 1) {
                DB::statement('DROP INDEX IF EXISTS "'.$index->name.'"');
            }
        }
    }

    /**
     * @param  array<string, string>  $views
     */
    private function recreateViewsWithoutPlaintext(array $views): void
    {
        foreach ($views as $name => $sql) {
            // Bỏ đúng ba cột khỏi danh sách SELECT. Định nghĩa view legacy là một
            // danh sách cột phẳng ngăn bằng dấu phẩy (do migration ERD sinh ra),
            // nên phép thay thế này an toàn; nếu một ngày view phức tạp hơn thì
            // CREATE VIEW sẽ ném lỗi ngay tại đây chứ không hỏng âm thầm.
            $rewritten = preg_replace('/\s*\bcustomer_(?:name|phone|address)\b\s*,/', '', $sql) ?? $sql;
            $rewritten = preg_replace('/,\s*\bcustomer_(?:name|phone|address)\b(?=\s|$)/', '', $rewritten) ?? $rewritten;

            DB::statement($rewritten);

            if (preg_match('/\bcustomer_(name|phone|address)\b/', $rewritten) === 1) {
                throw new RuntimeException(
                    'Không gỡ được cột plaintext khỏi định nghĩa view "'.$name.'". '
                    .'View này phức tạp hơn danh sách cột phẳng — sửa tay trước khi chạy Phase 6.'
                );
            }
        }
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function rebuildLegacyView(bool $withPlaintext): void
    {
        $columns = [
            'id AS warranty_case_id',
            'warranty_case_code',
            'item_id AS inventory_serial_id',
            'sku_id AS product_id',
            'temporary_item_code',
            'identification_status',
            'missing_code_reason',
            'manual_product_description',
            'defect_id',
            'status',
            'condition_status',
            'description',
            'created_by',
            'version',
            'closed_at',
            'retention_until',
            'legal_hold_flag',
            'created_at',
            'updated_at',
            'NULL::timestamp with time zone AS deleted_at',
            'item_id',
            'purchase_date',
            'sales_trace_status',
            'reported_defect',
            'confirmed_defect',
            'accessories_received',
            'received_condition',
            'received_at',
            'completed_at',
            'returned_at',
            'cancel_reason',
            'estimated_completion_date',
            'updated_by',
            'customer_name_ciphertext',
            'customer_phone_ciphertext',
            'customer_address_ciphertext',
            'customer_phone_lookup',
            'customer_name_lookup',
        ];

        if ($withPlaintext) {
            $columns = array_merge($columns, ['customer_name', 'customer_phone', 'customer_address']);
        }

        DB::statement('DROP VIEW IF EXISTS '.self::VIEW);
        DB::statement('CREATE VIEW '.self::VIEW.' AS SELECT '.implode(', ', $columns).' FROM '.self::BASE_TABLE);
    }

    private function attachLegacyWriteTrigger(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS trg_hn_legacy_warranty_case_write ON '.self::VIEW.';'
            .' CREATE TRIGGER trg_hn_legacy_warranty_case_write'
            .' INSTEAD OF INSERT OR UPDATE OR DELETE ON '.self::VIEW
            .' FOR EACH ROW EXECUTE FUNCTION hn_legacy_warranty_case_write();'
        );
    }

    private function rebuildLegacyWriteTrigger(bool $withPlaintext): void
    {
        $mapping = [
            ['id', 'COALESCE(NEW.warranty_case_id, gen_random_uuid())'],
            ['warranty_case_code', 'NEW.warranty_case_code'],
            ['item_id', 'COALESCE(NEW.item_id, NEW.inventory_serial_id)'],
            ['sku_id', 'NEW.product_id'],
            ['temporary_item_code', 'NEW.temporary_item_code'],
            ['identification_status', "COALESCE(NEW.identification_status, 'IDENTIFIED')"],
            ['missing_code_reason', 'NEW.missing_code_reason'],
            ['manual_product_description', 'NEW.manual_product_description'],
            ['defect_id', 'NEW.defect_id'],
            ['purchase_date', 'NEW.purchase_date'],
            ['sales_trace_status', "COALESCE(NEW.sales_trace_status, 'NOT_TRACEABLE')"],
            ['status', "COALESCE(NEW.status, 'RECEIVED')"],
            ['condition_status', 'NEW.condition_status'],
            ['description', 'NEW.description'],
            ['created_by', 'NEW.created_by'],
            ['version', 'COALESCE(NEW.version, 1)'],
            ['closed_at', 'NEW.closed_at'],
            ['retention_until', 'NEW.retention_until'],
            ['legal_hold_flag', 'COALESCE(NEW.legal_hold_flag, false)'],
            ['created_at', 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)'],
            ['updated_at', 'COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)'],
            ['reported_defect', 'COALESCE(NEW.reported_defect, NEW.description)'],
            ['confirmed_defect', 'NEW.confirmed_defect'],
            ['accessories_received', 'NEW.accessories_received'],
            ['received_condition', 'COALESCE(NEW.received_condition, NEW.condition_status)'],
            ['received_at', 'COALESCE(NEW.received_at, NEW.created_at, CURRENT_TIMESTAMP)'],
            ['completed_at', 'NEW.completed_at'],
            ['returned_at', 'NEW.returned_at'],
            ['cancel_reason', 'NEW.cancel_reason'],
            ['estimated_completion_date', 'NEW.estimated_completion_date'],
            ['updated_by', 'NEW.updated_by'],
            ['customer_name_ciphertext', 'NEW.customer_name_ciphertext'],
            ['customer_phone_ciphertext', 'NEW.customer_phone_ciphertext'],
            ['customer_address_ciphertext', 'NEW.customer_address_ciphertext'],
            ['customer_phone_lookup', 'NEW.customer_phone_lookup'],
            ['customer_name_lookup', 'NEW.customer_name_lookup'],
        ];

        if ($withPlaintext) {
            $mapping = array_merge($mapping, [
                ['customer_name', 'NEW.customer_name'],
                ['customer_phone', 'NEW.customer_phone'],
                ['customer_address', 'NEW.customer_address'],
            ]);
        }

        $insertColumns = implode(', ', array_map(static fn (array $p): string => '"'.$p[0].'"', $mapping));
        $insertValues = implode(', ', array_map(static fn (array $p): string => $p[1], $mapping));
        $updateSet = implode(', ', array_map(
            static fn (array $p): string => '"'.$p[0].'"=EXCLUDED."'.$p[0].'"',
            array_filter($mapping, static fn (array $p): bool => $p[0] !== 'id'),
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION public.hn_legacy_warranty_case_write()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$function\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    DELETE FROM warranty_cases WHERE id = OLD.warranty_case_id;
                    RETURN OLD;
                END IF;
                INSERT INTO warranty_cases ({$insertColumns})
                VALUES ({$insertValues})
                ON CONFLICT ("id") DO UPDATE SET {$updateSet};
                RETURN NEW;
            END;
            \$function\$
        SQL);
    }
};
