<?php

namespace App\Support\Cache;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * `db:seed`/`migrate:fresh`/`migrate:refresh`/`migrate:reset` ghi thẳng xuống DB
 * ngoài luồng HTTP bình thường — không đi qua `BaseRepository::create/update/delete`
 * nên không tự bump bất kỳ cache version nào (xem CacheService/AuthCache). Nếu
 * không tự động clear ở đây, mọi domain cache có TTL dài (rbac/master/sku/stock/
 * reports) sẽ tiếp tục phục vụ dữ liệu của lứa seed CŨ dù DB đã đổi, gây lỗi
 * validate rất khó hiểu (ID "không tồn tại" dù vừa lấy từ chính GET trước đó).
 */
final class CacheAutoInvalidator
{
    /** @var array<int, string> */
    private const DATA_RESET_COMMANDS = ['db:seed', 'migrate:fresh', 'migrate:refresh', 'migrate:reset'];

    private function __construct() {}

    public static function handle(CommandFinished $event): void
    {
        if ($event->exitCode !== 0) {
            return;
        }

        if (! in_array($event->command, self::DATA_RESET_COMMANDS, true)) {
            return;
        }

        Artisan::call('wms:cache:clear', ['--scope' => 'all']);
        Log::info('cache.auto_clear_after_command', ['command' => $event->command]);
    }
}
