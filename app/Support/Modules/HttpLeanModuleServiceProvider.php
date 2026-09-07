<?php

namespace App\Support\Modules;

use Illuminate\Support\Facades\Blade;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Bản `boot()` gọn cho đường HTTP của 4 module.
 *
 * `Nwidart\Modules\Support\ModuleServiceProvider::boot()` chạy NGUYÊN VẸN trên MỌI
 * request, kể cả request API chỉ trả JSON:
 *
 *   - `registerCommands()` + `registerCommandSchedules()` — chỉ có nghĩa với artisan.
 *   - `registerConfig()` — `RecursiveDirectoryIterator` quét cả thư mục config của
 *     module + `publishes()` cho từng file, rồi mới gọi `merge_config_from()` — mà
 *     hàm đó lại `return` ngay khi config đã được cache. Toàn bộ phần quét là công
 *     vô ích khi `config:cache` đang bật.
 *   - `registerViews()` — `is_dir()` cho từng entry của `config('view.paths')` +
 *     `publishes()`. Repo này KHÔNG có file .blade.php nào trong thư mục resources của module.
 *   - `loadMigrationsFrom()` — chỉ artisan migrate cần.
 *
 * Đo tại chỗ (docker local, bind mount Windows, xem docs/perf/): `BootProviders`
 * tốn ~80 ms mỗi request, riêng `AuditLogServiceProvider::boot()` ~38 ms. Chi phí
 * này nằm ở syscall filesystem, nên nó cộng vào MỌI endpoint — kể cả endpoint đã
 * cache hoàn hảo và trả 0 byte.
 *
 * Nguyên tắc của lớp này: đường console giữ nguyên 100% hành vi cũ
 * (`parent::boot()`), đường HTTP chỉ giữ những gì một response JSON có thể thực sự
 * cần. Việc đăng ký namespace view/translation vẫn được giữ nhưng đi qua
 * `loadViewsFrom`/`loadTranslationsFrom` — hai hàm này của Laravel là
 * `callAfterResolving`, chỉ chạm đĩa khi có ai đó thật sự render view/dịch chuỗi.
 */
abstract class HttpLeanModuleServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            parent::boot();

            return;
        }

        // Chỉ hợp nhất config khi CHƯA cache. Khi `config:cache` bật, giá trị của
        // module đã nằm sẵn trong bootstrap/cache/config.php (lệnh config:cache boot
        // đủ provider để bắt được chúng), nên quét lại thư mục là thuần lãng phí.
        if (! $this->app->configurationIsCached()) {
            $this->registerConfig();
        }

        // KHÔNG dùng `module_path()` ở đây. Helper đó gọi `app('modules')->find()`,
        // kéo theo lần quét đầu tiên của FileRepository: glob `Modules/*/module.json`
        // + đọc/parse từng manifest + dựng object Module. Đo được ~27 ms trên bind
        // mount Windows, và provider module nào boot trước thì trả tiền. Các provider
        // module lại được nạp qua package-discovery của Laravel nên lần quét đó vốn
        // KHÔNG cần cho một request API. Đường dẫn suy ra trực tiếp từ config —
        // đúng cùng gốc mà `getScanPaths()` của nwidart dùng.
        $moduleRoot = config('modules.paths.modules', base_path('Modules'))
            .DIRECTORY_SEPARATOR.$this->name;

        $langPath = $moduleRoot.DIRECTORY_SEPARATOR.config('modules.paths.generator.lang.path', 'lang');
        $this->loadTranslationsFrom($langPath, $this->nameLower);
        $this->loadJsonTranslationsFrom($langPath);

        $viewPath = $moduleRoot.DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, (string) config('modules.paths.generator.views.path', 'resources/views'));
        $this->loadViewsFrom($viewPath, $this->nameLower);

        // Hoãn tới khi có ai đó thật sự dùng Blade — nếu gọi thẳng, facade sẽ dựng
        // luôn view engine trên mọi request API.
        $this->callAfterResolving('blade.compiler', function () {
            Blade::componentNamespace(
                config('modules.namespace').'\\'.$this->name.'\\View\\Components',
                $this->nameLower,
            );
        });
    }
}
