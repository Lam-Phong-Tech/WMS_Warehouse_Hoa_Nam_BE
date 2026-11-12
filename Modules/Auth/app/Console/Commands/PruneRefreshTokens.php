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
    protected $signature = 'auth:prune-refresh-tokens {--days= : Số ngày giữ lại sau khi token hết hạn/bị thu hồi (mặc định config refresh_token.prune_after_days)} {--max-families=30 : Số phiên đăng nhập đã kết thúc tối đa giữ lại cho mỗi người dùng (mặc định 30)}';

    protected $description = 'Xoá các refresh token đã hết hạn hoặc đã bị thu hồi quá cửa sổ lưu vết';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('refresh_token.prune_after_days', 30));
        if ($days < 1) {
            $this->error('Cửa sổ lưu vết phải >= 1 ngày.');

            return self::FAILURE;
        }
        $maxFamilies = (int) ($this->option('max-families') ?? 30);
        if ($maxFamilies < 1) {
            $this->error('Số phiên tối đa mỗi người dùng phải >= 1.');

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

        $capped = $this->capEndedFamiliesPerUser($maxFamilies);

        $this->info("Đã xoá {$deleted} refresh token chết quá {$days} ngày; xoá {$capped} dòng token thuộc các phiên đăng nhập cũ hơn {$maxFamilies} phiên mới nhất của mỗi người dùng.");

        return self::SUCCESS;
    }

    /**
     * Lưu lại tối đa $max phiên đăng nhập (ended family) MỚI NHẤT mỗi người dùng;
     * các phiên cũ hơn bị xoá hẳn. Không đụng tới family còn hoạt động.
     */
    private function capEndedFamiliesPerUser(int $max): int
    {
        $ended = RefreshToken::query()
            ->where(function ($query): void {
                $query->whereNotNull('revoked_at')->orWhere('expires_at', '<=', now());
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'auth_session_id', 'created_at']);

        $families = [];
        foreach ($ended as $row) {
            $family = (string) ($row->auth_session_id ?? ('legacy:' . $row->id));
            $key = $row->user_id . '|' . $family;
            if (! isset($families[$key])) {
                $families[$key] = [
                    'user' => $row->user_id,
                    'ids' => [],
                    'max' => $row->created_at,
                ];
            }
            $families[$key]['ids'][] = (string) $row->id;
            if ($row->created_at && $families[$key]['max'] < $row->created_at) {
                $families[$key]['max'] = $row->created_at;
            }
        }

        $byUser = [];
        foreach ($families as $info) {
            $byUser[$info['user']][] = $info;
        }

        $deleteIds = [];
        foreach ($byUser as $list) {
            usort($list, fn (array $a, array $b): int => strcmp((string) $b['max'], (string) $a['max']));
            foreach (array_slice($list, $max) as $family) {
                foreach ($family['ids'] as $id) {
                    $deleteIds[] = $id;
                }
            }
        }

        if ($deleteIds === []) {
            return 0;
        }

        return RefreshToken::query()->whereIn('id', $deleteIds)->delete();
    }
}
