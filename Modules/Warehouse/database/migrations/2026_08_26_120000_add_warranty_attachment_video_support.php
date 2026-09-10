<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAR-02: video đính kèm tiếp nhận bảo hành, tách khỏi 10 ảnh (Annex D SCR-WAR-01)
 * bằng `media_kind`. `storage_disk` ghi lại disk vật lý tại thời điểm upload (ảnh cũ
 * mặc định 'local') để endpoint download/delete đọc đúng disk kể cả sau khi
 * WARRANTY_VIDEO_DISK đổi sang 'r2'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warranty_attachments')) {
            return;
        }

        Schema::table('warranty_attachments', function (Blueprint $table): void {
            foreach ([
                'media_kind' => fn () => $table->string('media_kind', 10)->default('IMAGE'),
                'duration_seconds' => fn () => $table->integer('duration_seconds')->nullable(),
                'storage_disk' => fn () => $table->string('storage_disk', 20)->default('local'),
            ] as $column => $definition) {
                if (! Schema::hasColumn('warranty_attachments', $column)) {
                    $definition();
                }
            }
        });
    }

    /**
     * Rollback thật (WWHN-300 `migrations.reversible`, 10/09/2026): trả
     * `warranty_attachments` về đúng hình dạng trước WAR-02 bằng cách bỏ ba cột mà up()
     * thêm. Metadata video (`media_kind`, `duration_seconds`, `storage_disk`) mất theo cột
     * — đó là nghĩa của rollback một bước additive; tệp vật lý trên disk không bị đụng.
     */
    public function down(): void
    {
        if (! Schema::hasTable('warranty_attachments')) {
            return;
        }

        $columns = array_values(array_filter(
            ['media_kind', 'duration_seconds', 'storage_disk'],
            static fn (string $column): bool => Schema::hasColumn('warranty_attachments', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('warranty_attachments', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
