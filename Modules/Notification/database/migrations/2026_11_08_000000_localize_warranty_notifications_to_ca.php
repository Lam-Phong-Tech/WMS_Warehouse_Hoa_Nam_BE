<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Việt hóa tiếng Anh "case" → "ca" trong thông báo bảo hành đã lưu.
 *
 * Hai listener chỉ đổi câu chữ cho thông báo TƯƠNG LAI; các dòng đã nằm trong
 * `notifications` (đang hiện trong chuông thông báo) vẫn giữ chữ "case" nếu
 * không backfill. Migration này chỉ chạm đúng hai event_key bảo hành và chỉ
 * thay cụm "case bảo hành"/"Case bảo hành" — không đụng thông báo tự soạn.
 *
 * Làm bằng PHP (không phải SQL REPLACE) để giữ đúng hoa/thường: "Case bảo
 * hành" ở đầu câu thành "Ca bảo hành", còn "… tiếp nhận case bảo hành mới"
 * thành "… tiếp nhận ca bảo hành mới". SQL REPLACE trên collation CI sẽ làm
 * mất phân biệt này.
 */
return new class extends Migration
{
    private const EVENT_KEYS = ['warranty.case_created', 'warranty.status_changed'];

    private const FROM = ['Case bảo hành', 'case bảo hành'];

    private const TO = ['Ca bảo hành', 'ca bảo hành'];

    public function up(): void
    {
        DB::table('notifications')
            ->whereIn('event_key', self::EVENT_KEYS)
            ->select(['id', 'title', 'body'])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $title = str_replace(self::FROM, self::TO, (string) $row->title);
                    $body = str_replace(self::FROM, self::TO, (string) $row->body);

                    if ($title === $row->title && $body === $row->body) {
                        continue;
                    }

                    DB::table('notifications')
                        ->where('id', $row->id)
                        ->update(['title' => $title, 'body' => $body]);
                }
            });
    }

    public function down(): void
    {
        // Sửa câu chữ là thay đổi một chiều: hoàn nguyên không thể khôi phục
        // đúng hoa/thường gốc của từng dòng, và có thể sửa nhầm thông báo mới
        // vốn đã đúng. Giữ nguyên dữ liệu thay vì làm hỏng nội dung.
    }
};
