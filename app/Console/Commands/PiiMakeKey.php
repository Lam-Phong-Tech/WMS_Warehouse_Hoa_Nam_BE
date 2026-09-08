<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * WWHN-SEC-06 — sinh khoá cho keyring PII.
 *
 * CỐ Ý KHÔNG tự ghi vào `.env`. `key:generate` của Laravel ghi được vì APP_KEY
 * xoay là chuyện không mất dữ liệu; khoá PII thì ngược lại — ghi đè nhầm một
 * khoá đang có dữ liệu dưới nó là mất vĩnh viễn dữ liệu đó. Lệnh này chỉ IN ra,
 * người vận hành tự đưa vào secret manager của môi trường.
 *
 * Giá trị được in ra stdout MỘT LẦN và không đi qua logger.
 */
class PiiMakeKey extends Command
{
    protected $signature = 'pii:make-key
        {--purpose=encryption : encryption|blind_index — chỉ dùng cho nhãn gợi ý}
        {--id=v1 : key id sẽ nhúng vào ciphertext/vân tay}';

    protected $description = 'Sinh một khoá 32 byte cho keyring PII (WWHN-SEC-06). Không ghi vào .env.';

    public function handle(): int
    {
        $purpose = (string) $this->option('purpose');
        $id = trim((string) $this->option('id'));

        if (preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $id) !== 1) {
            $this->error('key id chỉ được gồm chữ, số, "_", "." hoặc "-" và tối đa 32 ký tự.');

            return self::FAILURE;
        }

        $key = 'base64:'.base64_encode(random_bytes(32));
        $envName = $purpose === 'blind_index' ? 'PII_BLIND_INDEX_KEYS' : 'PII_ENCRYPTION_KEYS';
        $activeName = $purpose === 'blind_index' ? 'PII_ACTIVE_BLIND_INDEX_KEY' : 'PII_ACTIVE_ENCRYPTION_KEY';

        $this->newLine();
        $this->line('# Thêm khoá này vào keyring hiện có (GIỮ NGUYÊN các khoá cũ — gỡ khoá cũ');
        $this->line('# trước khi `pii:reencrypt` chạy xong sẽ làm dữ liệu cũ không giải mã được):');
        $this->newLine();
        $this->line($envName.'={"'.$id.'":"'.$key.'"}');
        $this->line($activeName.'='.$id);
        $this->newLine();
        $this->warn('Giá trị trên chỉ hiển thị MỘT LẦN và không được ghi vào log.');
        $this->warn('Đưa thẳng vào secret manager của môi trường; không commit, không dán vào ticket/chat.');

        return self::SUCCESS;
    }
}
