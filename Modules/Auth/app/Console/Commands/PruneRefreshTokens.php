<?php

namespace Modules\Auth\Console\Commands;

use Illuminate\Console\Command;
use Modules\Auth\Models\RefreshToken;

/**
 * WWHN-SEC-09: bảng refresh_tokens chỉ tăng — mỗi lượt login/rotate thêm một
 * hàng, revoke chỉ đóng dấu `revoked_at` chứ không xoá. Không dọn thì (1) bảng
 * phình vô hạn theo số lượt đăng nhập, (2) hash token chết nằm lại vĩnh viễn
 * trong DB/backup — vô ích cho vận hành nhưng vẫn là dữ liệu credential phải
 * bảo vệ. Giữ lại một cửa sổ ngắn sau khi chết để còn truy vết sự cố
 * (fingerprint trong audit TOKEN_REFRESH_FAILED trỏ về hàng này), rồi xoá hẳn.
 */
class PruneRefreshTokens extends Command
{
    protected $signature = 'auth:prune-refresh-tokens {--days= : Số ngày giữ lại sau khi token hết hạn/bị thu hồi (mặc định config refresh_token.prune_after_days)}';

    protected $description = 'Xoá các refresh token đã hết hạn hoặc đã bị thu hồi quá cửa sổ lưu vết';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('refresh_token.prune_after_days', 30));
        if ($days < 1) {
            $this->error('Cửa sổ lưu vết phải >= 1 ngày.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $deleted = RefreshToken::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('expires_at', '<', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNotNull('revoked_at')->where('revoked_at', '<', $cutoff);
                    });
            })
            ->delete();

        $this->info("Đã xoá {$deleted} refresh token chết quá {$days} ngày.");

        return self::SUCCESS;
    }
}
