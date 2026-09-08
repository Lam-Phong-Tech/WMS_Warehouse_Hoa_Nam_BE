<?php

use App\Support\Security\BlindIndex;
use App\Support\Security\PiiCrypto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-SEC-06 · Phase 6 — xoá hẳn cột plaintext của `public_quote_requests`.
 *
 * ĐÂY LÀ BƯỚC LÀM CHO VIỆC MÃ HOÁ CÓ TÁC DỤNG THẬT. Trước migration này, kẻ lấy
 * được bản dump vẫn đọc được `full_name` / `phone` / `phone_normalized` của mọi
 * hàng cũ — ciphertext chỉ nằm bên cạnh chứ chưa thay thế gì.
 *
 * FAIL-CLOSED, KHÔNG "BEST EFFORT":
 *
 * Migration TỪ CHỐI chạy nếu còn hàng chưa có ciphertext, hoặc nếu mẫu kiểm tra
 * không giải mã được bằng khoá của chính môi trường đang deploy. Lý do: xoá
 * plaintext trong khi khoá cấu hình sai là mất dữ liệu VĨNH VIỄN và không có
 * cách nào phát hiện sau đó. Deploy fail ở đây là kết quả ĐÚNG — nó buộc người
 * vận hành chạy `php artisan pii:quote-requests --encrypt` rồi `--verify`.
 *
 * ROLLBACK THẬT SỰ HOẠT ĐỘNG: `down()` dựng lại ba cột và GIẢI MÃ ciphertext để
 * đổ dữ liệu về. Vì khoá vẫn nằm trong keyring, đây không phải rollback "trên
 * giấy" — dữ liệu quay lại đầy đủ. Điều kiện duy nhất: keyring còn khoá đã dùng
 * để mã hoá (đó chính là lý do PiiKeyring giữ nhiều khoá thay vì một).
 */
return new class extends Migration
{
    private const TABLE = 'public_quote_requests';

    private const CONTEXT = [
        'full_name' => 'public_quote_requests.full_name',
        'phone' => 'public_quote_requests.phone',
        'note' => 'public_quote_requests.note',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'full_name')) {
            return;
        }

        $this->assertMigrationComplete();

        $this->dropIndexIfExists('idx_public_quote_requests_phone');

        // `note` nằm trong danh sách này vì nó là TRƯỜNG TỰ DO do người dùng gõ:
        // trong thực tế người ta dán vào đó địa chỉ giao hàng, tên công ty, đôi
        // khi cả số điện thoại thứ hai. Bỏ sót nó thì hai cột kia được mã hoá
        // còn dữ liệu nhạy cảm nhất vẫn nằm phơi ngay bên cạnh.
        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (['full_name', 'phone', 'phone_normalized', 'note'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'full_name')) {
                $table->string('full_name', 120)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'phone')) {
                $table->string('phone', 32)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'phone_normalized')) {
                $table->string('phone_normalized', 32)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'note')) {
                $table->text('note')->nullable();
            }
        });

        // Đổ dữ liệu về từ ciphertext. Không phải trang trí: nếu bỏ bước này thì
        // rollback để lại ba cột rỗng và bản build cũ (đọc plaintext) sẽ thấy
        // toàn bộ khách hàng biến mất.
        DB::table(self::TABLE)
            ->whereNotNull('full_name_ciphertext')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $phone = PiiCrypto::decrypt($row->phone_ciphertext, self::CONTEXT['phone']);

                    DB::table(self::TABLE)->where('id', $row->id)->update([
                        'full_name' => PiiCrypto::decrypt($row->full_name_ciphertext, self::CONTEXT['full_name']),
                        'phone' => $phone,
                        'phone_normalized' => BlindIndex::normalizePhone($phone),
                        'note' => PiiCrypto::decrypt($row->note_ciphertext, self::CONTEXT['note']),
                    ]);
                }
            }, 'id');

        $this->createIndexIfMissing('idx_public_quote_requests_phone', 'phone_normalized');
    }

    /**
     * Hai điều kiện, cả hai đều bắt buộc:
     *  1. Không còn hàng nào có dữ liệu plaintext mà thiếu ciphertext.
     *  2. Ciphertext ĐỌC ĐƯỢC bằng khoá của môi trường này (round-trip thật).
     */
    private function assertMigrationComplete(): void
    {
        $pending = DB::table(self::TABLE)
            ->whereNull('full_name_ciphertext')
            ->whereNotNull('full_name')
            ->count();

        if ($pending > 0) {
            throw new RuntimeException(
                'WWHN-SEC-06 Phase 6 bị chặn: còn '.$pending.' hàng public_quote_requests chưa được mã hoá. '
                .'Chạy `php artisan pii:quote-requests --encrypt` rồi `--verify` trước khi migrate.'
            );
        }

        $sample = DB::table(self::TABLE)
            ->whereNotNull('full_name_ciphertext')
            ->limit(25)
            ->get(['id', 'full_name_ciphertext', 'phone_ciphertext']);

        foreach ($sample as $row) {
            try {
                PiiCrypto::decrypt($row->full_name_ciphertext, self::CONTEXT['full_name']);
                PiiCrypto::decrypt($row->phone_ciphertext, self::CONTEXT['phone']);
            } catch (Throwable $error) {
                throw new RuntimeException(
                    'WWHN-SEC-06 Phase 6 bị chặn: ciphertext không giải mã được bằng keyring của môi trường này '
                    .'(id='.$row->id.'). Kiểm tra PII_ENCRYPTION_KEYS / PII_ACTIVE_ENCRYPTION_KEY. '
                    .'Xoá plaintext lúc này sẽ mất dữ liệu vĩnh viễn.',
                    0,
                    $error,
                );
            }
        }
    }

    private function createIndexIfMissing(string $name, string $column): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS '.$name.' ON '.self::TABLE.' ('.$column.')');

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($name, $column): void {
            $table->index([$column], $name);
        });
    }

    private function dropIndexIfExists(string $name): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.$name);

            return;
        }

        try {
            Schema::table(self::TABLE, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        } catch (Throwable) {
            // sqlite: index có thể đã bị dropColumn dọn kèm.
        }
    }
};
