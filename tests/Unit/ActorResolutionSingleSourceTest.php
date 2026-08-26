<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trước 2026-08-26, việc lấy actor id từ `auth('api')->id()` bị copy-paste
 * riêng trong ~28 controller/service của module Warehouse (một số guard 401,
 * một số không) — dễ quên guard khi thêm action mới, không có nguồn duy nhất
 * để sửa nếu chính sách đổi. Đã gom về 2 trait dùng chung:
 * Modules\Warehouse\Support\Concerns\ResolvesActor (guard 401) và
 * ResolvesActorOrNull (không guard, dùng khi anonymous hợp lệ).
 *
 * Test này KHÔNG chạm DB — chỉ quét mã nguồn — để chặn việc một PR tương lai
 * lại khai báo tay một bản `function actorId()`/`currentActorId()` mới thay vì
 * `use ResolvesActor;`/`use ResolvesActorOrNull;`.
 */
class ActorResolutionSingleSourceTest extends TestCase
{
    /**
     * 3 file này CỐ Ý override actorId() cục bộ để giữ message lỗi 401 gốc
     * riêng của từng endpoint (khác message mặc định của trait) — chúng vẫn
     * dùng ResolvesActorOrNull để lấy actorIdOrNull(), chỉ tự viết lại phần
     * guard/message. Đây là 2 method thật hợp lệ, không phải bản sao "quên
     * dùng trait".
     */
    private const ALLOWED_LOCAL_OVERRIDES = [
        'Modules/Warehouse/app/Http/Controllers/BaM1InboundDocumentController.php',
        'Modules/Warehouse/app/Http/Controllers/MiniAppOutboundDraftController.php',
        'Modules/Warehouse/app/Http/Controllers/MiniAppOutboundStagingController.php',
    ];

    /** Chính 2 trait định nghĩa các method này — không phải bản sao. */
    private const CANONICAL_TRAIT_FILES = [
        'Modules/Warehouse/app/Support/Concerns/ResolvesActor.php',
        'Modules/Warehouse/app/Support/Concerns/ResolvesActorOrNull.php',
    ];

    public function test_no_new_duplicate_actor_resolution_method_outside_shared_trait(): void
    {
        $root = dirname(__DIR__, 2);
        $scanDirs = [
            $root.'/Modules/Warehouse/app/Http/Controllers',
            $root.'/Modules/Warehouse/app/Services',
        ];

        $pattern = '/private\s+(?:static\s+)?function\s+(actorId|currentActorId|actorIdOrNull|actorIdCurrent)\s*\(/';
        $offenders = [];

        foreach ($scanDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                if (in_array($relative, self::CANONICAL_TRAIT_FILES, true)) {
                    continue;
                }
                if (in_array($relative, self::ALLOWED_LOCAL_OVERRIDES, true)) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                if ($content !== false && preg_match($pattern, $content)) {
                    $offenders[] = $relative;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Các file sau khai báo lại actorId()/currentActorId() cục bộ thay vì dùng "
            .'Modules\Warehouse\Support\Concerns\ResolvesActor'.'/'.'ResolvesActorOrNull'
            .": ".implode(', ', $offenders)
        );
    }
}
