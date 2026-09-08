<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-SEC-06 · Phase 1 — thêm cột ciphertext + blind index cho
 * `public_quote_requests`, KHÔNG xoá gì.
 *
 * VÌ SAO BẢNG NÀY TRƯỚC TIÊN
 *
 * Đây là bảng duy nhất trong hệ thống chứa dữ liệu cá nhân của NGƯỜI NGOÀI —
 * khách vãng lai gửi form tư vấn từ storefront công khai, kèm `consent` và
 * `privacy_version`, tức là một hoạt động xử lý dữ liệu cá nhân có cam kết pháp
 * lý kèm theo. Đồng thời nó có bề mặt hẹp nhất trong toàn repo:
 *
 *   - là BASE TABLE thật (không phải view + INSTEAD OF trigger như
 *     `warranty_case`, xem docs/ops/SECURITY_PII_ENCRYPTION.md §5)
 *   - chỉ một model Eloquent chạm tới, chỉ một controller ghi
 *   - `full_name` / `phone` / `note` KHÔNG được đọc ra ở bất kỳ response nào
 *     (PublicQuoteRequestController chỉ trả `request_id` + `status`)
 *
 * Nghĩa là mã hoá ba cột này không đổi một byte nào của hợp đồng API. Nó là nơi
 * đúng để chứng minh toàn bộ đường ống mã hoá chạy thật trước khi áp lên
 * `warranty_case` — nơi mỗi thay đổi phải đi qua view legacy và ~15 điểm gọi.
 *
 * VÌ SAO `phone_normalized` PHẢI ĐƯỢC THAY, KHÔNG PHẢI GIỮ
 *
 * `phone_normalized` chính là số điện thoại, chỉ bỏ khoảng trắng. Mã hoá `phone`
 * mà giữ nguyên cột đó là tự vô hiệu hoá việc mã hoá. Thay bằng `phone_lookup` —
 * HMAC có khoá (xem App\Support\Security\BlindIndex) — giữ được tra cứu chính
 * xác mà không lưu lại số.
 *
 * PHA TIẾP THEO (không nằm trong migration này, cố ý):
 *   Phase 3  `php artisan pii:quote-requests --encrypt` — backfill theo lô
 *   Phase 4  `--verify` — đếm và đối chiếu, không in dữ liệu
 *   Phase 6  migration 2026_09_08_000200_* xoá cột plaintext (chạy RIÊNG, sau
 *            khi Phase 4 xanh trên chính môi trường đó)
 *
 * ROLLBACK: `php artisan pii:quote-requests --restore-plaintext` dựng lại ba cột
 * plaintext từ ciphertext (khoá vẫn trong tay hệ thống), rồi deploy bản cũ.
 * Chừng nào migration Phase 6 chưa chạy thì rollback KHÔNG mất dữ liệu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('public_quote_requests')) {
            return;
        }

        Schema::table('public_quote_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('public_quote_requests', 'full_name_ciphertext')) {
                $table->text('full_name_ciphertext')->nullable();
            }
            if (! Schema::hasColumn('public_quote_requests', 'phone_ciphertext')) {
                $table->text('phone_ciphertext')->nullable();
            }
            if (! Schema::hasColumn('public_quote_requests', 'note_ciphertext')) {
                $table->text('note_ciphertext')->nullable();
            }
            if (! Schema::hasColumn('public_quote_requests', 'phone_lookup')) {
                // "<key_id>:<64 hex>" — 96 ký tự là dư cho mọi key id hợp lệ.
                $table->string('phone_lookup', 96)->nullable();
            }
        });

        // Index cho blind index: đây là cột duy nhất còn tra cứu được số điện
        // thoại sau Phase 6, nên nó phải có index hệt như `phone_normalized` cũ.
        $this->createIndexIfMissing('idx_public_quote_requests_phone_lookup', 'phone_lookup');

        // Ba cột plaintext phải trở thành NULLABLE ngay từ Phase 1: từ lúc code
        // mới chạy, bản ghi MỚI chỉ ghi ciphertext và để plaintext trống. Nếu
        // không nới ràng buộc ở đây thì mọi INSERT sau khi deploy sẽ vi phạm
        // NOT NULL — tức là form tư vấn công khai chết ngay lúc deploy.
        $this->makeNullable('full_name');
        $this->makeNullable('phone');
        $this->makeNullable('phone_normalized');
    }

    public function down(): void
    {
        if (! Schema::hasTable('public_quote_requests')) {
            return;
        }

        $this->dropIndexIfExists('idx_public_quote_requests_phone_lookup');

        Schema::table('public_quote_requests', function (Blueprint $table): void {
            foreach (['full_name_ciphertext', 'phone_ciphertext', 'note_ciphertext', 'phone_lookup'] as $column) {
                if (Schema::hasColumn('public_quote_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // CỐ Ý KHÔNG khôi phục NOT NULL cho ba cột plaintext.
        //
        // Nếu đã có bản ghi ghi bằng code mới (plaintext = NULL) thì lệnh
        // SET NOT NULL sẽ ném lỗi và làm `migrate:rollback` hỏng giữa chừng —
        // một migration không rollback được là thứ tệ hơn một ràng buộc lỏng.
        // Trình tự rollback đúng là chạy `pii:quote-requests --restore-plaintext`
        // TRƯỚC, khi đó dữ liệu đầy đủ và DBA có thể tự siết lại NOT NULL.
    }

    private function makeNullable(string $column): void
    {
        if (! Schema::hasColumn('public_quote_requests', $column)) {
            return;
        }

        // sqlite (bộ test) không hỗ trợ ALTER COLUMN DROP NOT NULL và cũng không
        // cần: Schema::table trên sqlite dựng lại bảng. Chỉ chạy trên PostgreSQL.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE public_quote_requests ALTER COLUMN '.$column.' DROP NOT NULL');
    }

    private function createIndexIfMissing(string $name, string $column): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS '.$name.' ON public_quote_requests ('.$column.')');

            return;
        }

        Schema::table('public_quote_requests', function (Blueprint $table) use ($name, $column): void {
            $table->index([$column], $name);
        });
    }

    private function dropIndexIfExists(string $name): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.$name);

            return;
        }

        Schema::table('public_quote_requests', function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }
};
