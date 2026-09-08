<?php

namespace App\Console\Commands;

use App\Support\Security\BlindIndex;
use App\Support\Security\PiiCrypto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * WWHN-SEC-06 · Phase 3/4/6 — di trú dữ liệu PII của `public_quote_requests`.
 *
 * BA CHẾ ĐỘ, KHÔNG CHẾ ĐỘ NÀO TỰ ĐỘNG CHẠY CHẾ ĐỘ KHÁC:
 *
 *   --encrypt            Phase 3. Đọc từng lô hàng còn plaintext, ghi ciphertext
 *                        + blind index. Idempotent: chạy lại không hỏng gì, hàng
 *                        đã mã hoá bị bỏ qua. KHÔNG xoá plaintext.
 *   --verify             Phase 4. Đếm và đối chiếu; giải mã thử để chứng minh
 *                        khoá đang dùng ĐỌC ĐƯỢC dữ liệu vừa ghi.
 *   --purge-plaintext    Phase 5. Set NULL ba cột plaintext SAU KHI --verify
 *                        xanh. Đây là bước làm cho việc mã hoá có tác dụng thật.
 *   --restore-plaintext  ROLLBACK. Dựng lại plaintext từ ciphertext.
 *
 * VÌ SAO TÁCH `--encrypt` KHỎI `--purge-plaintext`:
 * Gộp lại thì một lần chạy vừa ghi vừa xoá; nếu khoá bị cấu hình sai ở môi
 * trường đó, plaintext đã mất trước khi ai kịp phát hiện ciphertext không đọc
 * được. Tách ra thì giữa hai bước luôn có một trạng thái an toàn để kiểm tra.
 *
 * KHÔNG BAO GIỜ IN DỮ LIỆU: lệnh chỉ in số đếm và id. Một lệnh di trú PII mà
 * `--verbose` in ra tên và số điện thoại là tự tạo ra bản sao PII trong log CI.
 */
class PiiQuoteRequests extends Command
{
    protected $signature = 'pii:quote-requests
        {--encrypt : Phase 3 — backfill ciphertext + blind index cho hàng còn plaintext}
        {--verify : Phase 4 — đối chiếu số lượng và giải mã thử}
        {--purge-plaintext : Phase 5 — set NULL cột plaintext (chạy SAU khi --verify xanh)}
        {--restore-plaintext : ROLLBACK — dựng lại plaintext từ ciphertext}
        {--chunk=500 : số hàng mỗi lô}';

    protected $description = 'Di trú PII của public_quote_requests sang dạng mã hoá (WWHN-SEC-06).';

    private const TABLE = 'public_quote_requests';

    private const CONTEXT = [
        'full_name' => 'public_quote_requests.full_name',
        'phone' => 'public_quote_requests.phone',
        'note' => 'public_quote_requests.note',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->error('Bảng '.self::TABLE.' chưa tồn tại — chạy migration trước.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn(self::TABLE, 'full_name_ciphertext')) {
            $this->error('Thiếu cột ciphertext — migration Phase 1 (2026_09_08_000100) chưa chạy.');

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
            'encrypt' => $this->encrypt(),
            'verify' => $this->verify(),
            'purge-plaintext' => $this->purgePlaintext(),
            default => $this->restorePlaintext(),
        };
    }

    private function encrypt(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        // Chỉ lấy hàng CHƯA có ciphertext. Điều kiện này làm lệnh idempotent và
        // cũng là điều kiện dừng: mỗi lô xử lý xong sẽ không còn khớp nữa.
        while (true) {
            $rows = DB::table(self::TABLE)
                ->whereNull('full_name_ciphertext')
                ->orderBy('created_at')
                ->limit($chunk)
                ->get(['id', 'full_name', 'phone', 'note']);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                try {
                    // Ghi từng hàng trong transaction riêng: một hàng dữ liệu
                    // hỏng không được kéo cả lô đã xử lý đúng quay lại.
                    DB::transaction(function () use ($row): void {
                        DB::table(self::TABLE)->where('id', $row->id)->update([
                            'full_name_ciphertext' => PiiCrypto::encrypt($row->full_name, self::CONTEXT['full_name']),
                            'phone_ciphertext' => PiiCrypto::encrypt($row->phone, self::CONTEXT['phone']),
                            'note_ciphertext' => PiiCrypto::encrypt($row->note, self::CONTEXT['note']),
                            'phone_lookup' => BlindIndex::phone($row->phone),
                        ]);
                    });
                    $processed++;
                } catch (Throwable $error) {
                    $failed++;
                    // Chỉ id + loại lỗi. Không bao giờ in giá trị cột.
                    $this->warn('Bỏ qua id='.$row->id.' ('.$error::class.').');
                }
            }

            // Hàng có full_name rỗng/NULL sẽ không bao giờ nhận ciphertext (mã
            // hoá chuỗi rỗng trả null), nên vòng lặp sẽ gặp lại chúng mãi mãi.
            // Đếm và thoát thay vì quay vòng vô hạn.
            $remaining = DB::table(self::TABLE)->whereNull('full_name_ciphertext')->count();
            if ($remaining > 0 && $rows->count() < $chunk) {
                $skipped = $remaining;
                break;
            }
            if ($remaining === 0) {
                break;
            }
            if ($processed === 0 && $failed >= $rows->count()) {
                break;
            }
        }

        $this->info('Đã mã hoá: '.$processed.' hàng. Lỗi: '.$failed.'. Không đủ dữ liệu để mã hoá: '.$skipped.'.');
        $this->line('Bước tiếp theo: php artisan pii:quote-requests --verify');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function verify(): int
    {
        $total = DB::table(self::TABLE)->count();
        $withCipher = DB::table(self::TABLE)->whereNotNull('full_name_ciphertext')->count();
        $withLookup = DB::table(self::TABLE)->whereNotNull('phone_lookup')->count();
        // Sau Phase 6 cột plaintext KHÔNG còn tồn tại — đó là trạng thái đích,
        // không phải lỗi. Truy vấn thẳng vào cột đã xoá sẽ ném SQLSTATE 42703 và
        // biến một lệnh kiểm tra thành một lệnh gãy.
        $plaintextGone = ! Schema::hasColumn(self::TABLE, 'full_name');
        $plaintextLeft = $plaintextGone
            ? 0
            : DB::table(self::TABLE)->whereNotNull('full_name')->count();

        // Giải mã thử một mẫu: đây là điểm khác biệt giữa "có ciphertext" và
        // "ciphertext ĐỌC ĐƯỢC bằng khoá của môi trường này". Chỉ bước này mới
        // chứng minh được cấu hình khoá đúng trước khi xoá plaintext.
        $sample = DB::table(self::TABLE)
            ->whereNotNull('full_name_ciphertext')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['id', 'full_name_ciphertext', 'phone_ciphertext', 'note_ciphertext']);

        $decrypted = 0;
        $undecryptable = [];

        foreach ($sample as $row) {
            try {
                PiiCrypto::decrypt($row->full_name_ciphertext, self::CONTEXT['full_name']);
                PiiCrypto::decrypt($row->phone_ciphertext, self::CONTEXT['phone']);
                PiiCrypto::decrypt($row->note_ciphertext, self::CONTEXT['note']);
                $decrypted++;
            } catch (Throwable) {
                $undecryptable[] = (string) $row->id;
            }
        }

        $this->table(['Chỉ số', 'Giá trị'], [
            ['tổng số hàng', $total],
            ['cột plaintext đã xoá (Phase 6)', $plaintextGone ? 'có' : 'chưa'],
            ['có ciphertext', $withCipher],
            ['có blind index', $withLookup],
            ['còn plaintext', $plaintextLeft],
            ['mẫu giải mã thử', $sample->count()],
            ['giải mã thành công', $decrypted],
        ]);

        if ($undecryptable !== []) {
            $this->error('KHÔNG giải mã được '.count($undecryptable).' hàng (id: '.implode(', ', array_slice($undecryptable, 0, 5)).'...).');
            $this->error('TUYỆT ĐỐI KHÔNG chạy --purge-plaintext khi dòng này còn xuất hiện.');

            return self::FAILURE;
        }

        if ($withCipher < $total) {
            $this->warn('Còn '.($total - $withCipher).' hàng chưa có ciphertext — chạy lại --encrypt.');

            return self::FAILURE;
        }

        $this->info('Verify PASS. Có thể chạy: php artisan pii:quote-requests --purge-plaintext');

        return self::SUCCESS;
    }

    private function purgePlaintext(): int
    {
        // Fail-closed: không cho xoá plaintext khi chưa chứng minh được ciphertext
        // đọc được. Đây là hàng rào chống đúng một sai lầm không thể sửa.
        if (! Schema::hasColumn(self::TABLE, 'full_name')) {
            $this->info('Cột plaintext đã bị xoá ở Phase 6 — không còn gì để dọn.');

            return self::SUCCESS;
        }

        if ($this->verifyQuietly() !== self::SUCCESS) {
            $this->error('--verify chưa xanh. Từ chối xoá plaintext.');

            return self::FAILURE;
        }

        $affected = DB::table(self::TABLE)
            ->whereNotNull('full_name_ciphertext')
            ->update([
                'full_name' => null,
                'phone' => null,
                'phone_normalized' => null,
                'note' => null,
            ]);

        $this->info('Đã xoá plaintext của '.$affected.' hàng.');
        $this->line('Cột plaintext được XOÁ HẲN ở migration Phase 6 (2026_09_08_000200).');

        return self::SUCCESS;
    }

    private function restorePlaintext(): int
    {
        if (! Schema::hasColumn(self::TABLE, 'full_name')) {
            $this->error('Cột plaintext đã bị xoá ở Phase 6. Rollback đúng cách là '
                .'`php artisan migrate:rollback` cho migration 2026_09_08_000200 — down() của nó '
                .'vừa dựng lại cột vừa giải mã dữ liệu về.');

            return self::FAILURE;
        }

        $restored = 0;
        $failed = 0;
        $chunk = max(1, (int) $this->option('chunk'));

        DB::table(self::TABLE)
            ->whereNotNull('full_name_ciphertext')
            ->orderBy('created_at')
            ->chunkById($chunk, function ($rows) use (&$restored, &$failed): void {
                foreach ($rows as $row) {
                    try {
                        $phone = PiiCrypto::decrypt($row->phone_ciphertext, self::CONTEXT['phone']);
                        DB::table(self::TABLE)->where('id', $row->id)->update([
                            'full_name' => PiiCrypto::decrypt($row->full_name_ciphertext, self::CONTEXT['full_name']),
                            'phone' => $phone,
                            'phone_normalized' => BlindIndex::normalizePhone($phone),
                            'note' => PiiCrypto::decrypt($row->note_ciphertext, self::CONTEXT['note']),
                        ]);
                        $restored++;
                    } catch (Throwable $error) {
                        $failed++;
                        $this->warn('Không khôi phục được id='.$row->id.' ('.$error::class.').');
                    }
                }
            }, 'id');

        $this->info('Đã khôi phục plaintext: '.$restored.' hàng. Lỗi: '.$failed.'.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function verifyQuietly(): int
    {
        $total = DB::table(self::TABLE)->count();
        $withCipher = DB::table(self::TABLE)->whereNotNull('full_name_ciphertext')->count();

        if ($total > 0 && $withCipher < $total) {
            return self::FAILURE;
        }

        $sample = DB::table(self::TABLE)
            ->whereNotNull('full_name_ciphertext')
            ->limit(25)
            ->get(['full_name_ciphertext']);

        foreach ($sample as $row) {
            try {
                PiiCrypto::decrypt($row->full_name_ciphertext, self::CONTEXT['full_name']);
            } catch (Throwable) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
