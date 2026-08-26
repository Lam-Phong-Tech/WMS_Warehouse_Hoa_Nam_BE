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

    public function down(): void
    {
        // Fail-forward: cột mới additive, không rollback để tránh mất metadata video.
    }
};
