<?php

namespace App\Console\Commands;

use App\Support\Security\BlindIndex;
use App\Support\Security\PiiCrypto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Support\WarrantyCasePii;
use Throwable;

/**
 * WWHN-SEC-06 · Phase 3/4/5 cho hồ sơ bảo hành.
 *
 * Lệnh này CỐ Ý ghi thẳng vào BẢNG GỐC `warranty_cases`, không đi qua view
 * `warranty_case`: view có trigger `INSTEAD OF` chạy một INSERT ... ON CONFLICT
 * đầy đủ cho MỖI hàng, tức là backfill 200 nghìn hồ sơ sẽ tốn 200 nghìn lần
 * upsert 39 cột thay vì 200 nghìn lần UPDATE 5 cột. Trên sqlite (bộ test) không
 * có lớp legacy đó nên bảng đích chính là `warranty_case`.
 *
 * KHÔNG BAO GIỜ IN DỮ LIỆU: chỉ số đếm và id.
 */
class PiiWarrantyCases extends Command
{
    protected $signature = 'pii:warranty-cases
        {--encrypt : Phase 3 — backfill ciphertext + blind index cho hồ sơ còn plaintext}
        {--verify : Phase 4 — đối chiếu số lượng và giải mã thử}
        {--purge-plaintext : Phase 5 — set NULL cột plaintext (chạy SAU khi --verify xanh)}
        {--restore-plaintext : ROLLBACK — dựng lại plaintext từ ciphertext}
        {--chunk=500 : số hàng mỗi lô}';

    protected $description = 'Di trú PII hồ sơ bảo hành sang dạng mã hoá (WWHN-SEC-06).';

    private const CONTEXT = [
        'customer_name' => 'warranty_cases.customer_name',
        'customer_phone' => 'warranty_cases.customer_phone',
        'customer_address' => 'warranty_cases.customer_address',
    ];

    public function handle(): int
    {
        $table = $this->baseTable();

        if ($table === null) {
            $this->error('Không tìm thấy bảng hồ sơ bảo hành.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn($table, 'customer_name_ciphertext')) {
            $this->error('Thiếu cột ciphertext — migration 2026_09_08_000300 chưa chạy.');

            return self::FAILURE;
        }

        $modes = array_filter([
            'encrypt' => (bool) $this->option('encrypt'),
            'verify' => (bool) $this->option('verify'),
            'purge-plaintext' => (bool) $this->option('purge-plaintext'),
            'restore-plaintext' => (bool) $this->option('restore-plaintext'),
        ]);

        if (count($modes) !== 1) {
            $this->error('Chọn ĐÚNG MỘT chế độ: --encrypt | --verify | --purge-plaintext | --restore-plaintext.');

            return self::FAILURE;
        }

        return match (array_key_first($modes)) {
            'encrypt' => $this->encrypt($table),
            'verify' => $this->verify($table),
            'purge-plaintext' => $this->purge($table),
            default => $this->restore($table),
        };
    }

    private function encrypt(string $table): int
    {
        if (! $this->hasPlaintextColumns($table)) {
            $this->info('Cột plaintext đã bị xoá (Phase 6) — không còn gì để backfill.');

            return self::SUCCESS;
        }

        $key = $this->primaryKey($table);
        $chunk = max(1, (int) $this->option('chunk'));
        $processed = 0;
        $failed = 0;

        while (true) {
            // Điều kiện "chưa có ciphertext VÀ còn plaintext" vừa là bộ lọc vừa là
            // điều kiện dừng: mỗi hàng xử lý xong sẽ không khớp lại nữa, nên lệnh
            // idempotent và không quay vòng vô hạn trên hàng thiếu dữ liệu.
            $rows = DB::table($table)
                ->whereNull('customer_name_ciphertext')
                ->where(function ($query): void {
                    $query->whereNotNull('customer_name')
                        ->orWhereNotNull('customer_phone')
                        ->orWhereNotNull('customer_address');
                })
                ->orderBy($key)
                ->limit($chunk)
                ->get([$key, 'customer_name', 'customer_phone', 'customer_address']);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                try {
                    DB::table($table)->where($key, $row->{$key})->update([
                        'customer_name_ciphertext' => PiiCrypto::encrypt($row->customer_name, self::CONTEXT['customer_name']),
                        'customer_phone_ciphertext' => PiiCrypto::encrypt($row->customer_phone, self::CONTEXT['customer_phone']),
                        'customer_address_ciphertext' => PiiCrypto::encrypt($row->customer_address, self::CONTEXT['customer_address']),
                        'customer_phone_lookup' => BlindIndex::phone($row->customer_phone),
                        'customer_name_lookup' => BlindIndex::nameTokens($row->customer_name),
                    ]);
                    $processed++;
                } catch (Throwable $error) {
                    $failed++;
                    $this->warn('Bỏ qua '.$key.'='.$row->{$key}.' ('.$error::class.').');
                }
            }

            // Hồ sơ có CẢ BA cột PII rỗng sẽ không nhận được ciphertext nào (mã
            // hoá null trả null) nên vòng lặp sẽ gặp lại chúng mãi. Bộ lọc
            // whereNotNull ở trên đã loại chúng, nhưng nếu vẫn còn hàng khớp mà
            // không xử lý được thì thoát thay vì lặp vô tận.
            if ($processed === 0 && $failed >= $rows->count()) {
                break;
            }
        }

        $this->info('Đã mã hoá: '.$processed.' hồ sơ. Lỗi: '.$failed.'.');
        $this->line('Bước tiếp theo: php artisan pii:warranty-cases --verify');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function verify(string $table): int
    {
        $hasPlaintext = $this->hasPlaintextColumns($table);
        $total = DB::table($table)->count();
        $withCipher = DB::table($table)->whereNotNull('customer_name_ciphertext')->count();
        $withPhoneLookup = DB::table($table)->whereNotNull('customer_phone_lookup')->count();

        $pending = $hasPlaintext
            ? DB::table($table)->whereNull('customer_name_ciphertext')->whereNotNull('customer_name')->count()
            : 0;
        $plaintextLeft = $hasPlaintext
            ? DB::table($table)->whereNotNull('customer_name')->count()
            : 0;

        // Khác biệt giữa "có ciphertext" và "ciphertext ĐỌC ĐƯỢC bằng khoá của
        // môi trường này" — chỉ bước này mới chứng minh cấu hình khoá đúng.
        $sample = DB::table($table)
            ->whereNotNull('customer_name_ciphertext')
            ->limit(25)
            ->get([$this->primaryKey($table), 'customer_name_ciphertext', 'customer_phone_ciphertext', 'customer_address_ciphertext']);

        $undecryptable = [];

        foreach ($sample as $row) {
            try {
                PiiCrypto::decrypt($row->customer_name_ciphertext, self::CONTEXT['customer_name']);
                PiiCrypto::decrypt($row->customer_phone_ciphertext, self::CONTEXT['customer_phone']);
                PiiCrypto::decrypt($row->customer_address_ciphertext, self::CONTEXT['customer_address']);
            } catch (Throwable) {
                $undecryptable[] = (string) $row->{$this->primaryKey($table)};
            }
        }

        $this->table(['Chỉ số', 'Giá trị'], [
            ['tổng số hồ sơ', $total],
            ['cột plaintext đã xoá (Phase 6)', $hasPlaintext ? 'chưa' : 'có'],
            ['có ciphertext', $withCipher],
            ['có blind index SĐT', $withPhoneLookup],
            ['còn chờ mã hoá', $pending],
            ['còn plaintext', $plaintextLeft],
            ['mẫu giải mã thử', $sample->count()],
            ['giải mã lỗi', count($undecryptable)],
        ]);

        if ($undecryptable !== []) {
            $this->error('KHÔNG giải mã được '.count($undecryptable).' hồ sơ. TUYỆT ĐỐI KHÔNG chạy --purge-plaintext.');

            return self::FAILURE;
        }

        if ($pending > 0) {
            $this->warn('Còn '.$pending.' hồ sơ chưa mã hoá — chạy lại --encrypt.');

            return self::FAILURE;
        }

        $this->info('Verify PASS.');

        return self::SUCCESS;
    }

    private function purge(string $table): int
    {
        if (! $this->hasPlaintextColumns($table)) {
            $this->info('Cột plaintext đã bị xoá ở Phase 6 — không còn gì để dọn.');

            return self::SUCCESS;
        }

        if ($this->verify($table) !== self::SUCCESS) {
            $this->error('--verify chưa xanh. Từ chối xoá plaintext.');

            return self::FAILURE;
        }

        $affected = DB::table($table)
            ->whereNotNull('customer_name_ciphertext')
            ->update([
                'customer_name' => null,
                'customer_phone' => null,
                'customer_address' => null,
            ]);

        $this->info('Đã xoá plaintext của '.$affected.' hồ sơ.');
        $this->line('Cột được XOÁ HẲN ở migration Phase 6 (2026_09_08_000400).');

        return self::SUCCESS;
    }

    private function restore(string $table): int
    {
        if (! $this->hasPlaintextColumns($table)) {
            $this->error('Cột plaintext đã bị xoá ở Phase 6. Rollback đúng cách là '
                .'`php artisan migrate:rollback` cho 2026_09_08_000400 — down() vừa dựng lại cột vừa giải mã dữ liệu về.');

            return self::FAILURE;
        }

        $key = $this->primaryKey($table);
        $restored = 0;
        $failed = 0;

        DB::table($table)
            ->whereNotNull('customer_name_ciphertext')
            ->orderBy($key)
            ->chunkById(max(1, (int) $this->option('chunk')), function ($rows) use ($table, $key, &$restored, &$failed): void {
                foreach ($rows as $row) {
                    try {
                        DB::table($table)->where($key, $row->{$key})->update([
                            'customer_name' => PiiCrypto::decrypt($row->customer_name_ciphertext, self::CONTEXT['customer_name']),
                            'customer_phone' => PiiCrypto::decrypt($row->customer_phone_ciphertext, self::CONTEXT['customer_phone']),
                            'customer_address' => PiiCrypto::decrypt($row->customer_address_ciphertext, self::CONTEXT['customer_address']),
                        ]);
                        $restored++;
                    } catch (Throwable $error) {
                        $failed++;
                        $this->warn('Không khôi phục được '.$key.'='.$row->{$key}.' ('.$error::class.').');
                    }
                }
            }, $key);

        $this->info('Đã khôi phục plaintext: '.$restored.' hồ sơ. Lỗi: '.$failed.'.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Hai driver có bố cục NGƯỢC nhau (PostgreSQL: bảng warranty_cases + view
     * warranty_case; sqlite: bảng warranty_case + view alias warranty_cases).
     * Schema::hasTable() trả true cho cả view nên phải hỏi catalog — xem
     * WarrantyCasePii::baseTable().
     */
    private function baseTable(): ?string
    {
        $table = WarrantyCasePii::baseTable();

        return Schema::hasTable($table) ? $table : null;
    }

    private function primaryKey(string $table): string
    {
        return $table === 'warranty_cases' ? 'id' : 'warranty_case_id';
    }

    private function hasPlaintextColumns(string $table): bool
    {
        return Schema::hasColumn($table, 'customer_name');
    }
}
