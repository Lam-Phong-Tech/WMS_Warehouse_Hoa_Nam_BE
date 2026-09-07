<?php

namespace Tests\Unit\Support\Database;

use App\Support\Database\SchemaCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_answers_match_the_schema_builder(): void
    {
        $this->assertTrue(SchemaCache::hasTable('app_user'));
        $this->assertFalse(SchemaCache::hasTable('bang_khong_ton_tai'));
        $this->assertTrue(SchemaCache::hasColumn('app_user', 'email'));
        $this->assertFalse(SchemaCache::hasColumn('app_user', 'cot_khong_ton_tai'));
        // Cột trên một bảng không tồn tại: cùng câu trả lời với Schema::hasColumn.
        $this->assertFalse(SchemaCache::hasColumn('bang_khong_ton_tai', 'x'));
    }

    public function test_repeat_questions_do_not_hit_the_database_again(): void
    {
        SchemaCache::flush();

        DB::flushQueryLog();
        DB::enableQueryLog();
        SchemaCache::hasColumn('app_user', 'email');
        $firstAsk = count(DB::getQueryLog());

        DB::flushQueryLog();
        foreach (['email', 'user_id', 'status', 'created_at', 'cot_khong_ton_tai'] as $column) {
            SchemaCache::hasColumn('app_user', $column);
        }
        $repeatAsks = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $firstAsk, 'Lần hỏi đầu phải thật sự đọc schema.');
        $this->assertSame(0, $repeatAsks, 'Hỏi lại cùng bảng KHÔNG được sinh thêm truy vấn nào.');
    }

    public function test_flush_makes_it_read_the_schema_again(): void
    {
        SchemaCache::hasColumn('app_user', 'email');
        SchemaCache::flush();

        DB::flushQueryLog();
        DB::enableQueryLog();
        SchemaCache::hasColumn('app_user', 'email');
        $afterFlush = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $afterFlush, 'Sau flush phải đọc lại schema thật.');
    }

    /**
     * HÀNG RÀO THƯỜNG TRỰC.
     *
     * `SchemaCache` nhớ câu trả lời trong suốt vòng đời tiến trình. Điều đó chỉ
     * đúng khi schema KHÔNG đổi giữa chừng. File nào vừa dùng `SchemaCache` vừa
     * tự chạy DDL sẽ tự nói dối với chính nó ngay sau lệnh DDL đầu tiên — đúng
     * lớp lỗi đã xảy ra một lần với `HoaNamErdSchema` (nó drop bảng rồi hỏi lại
     * bảng đó còn không).
     *
     * Nơi chạy DDL phải dùng thẳng facade `Schema` — nó không nhớ gì, và đó
     * chính là điều đúng đắn ở đó.
     */
    public function test_no_file_mixes_schema_cache_with_ddl(): void
    {
        $roots = [base_path('app'), base_path('Modules')];
        $ddl = '/Schema::(table|create|createOrReplace|drop|dropIfExists|dropColumns|rename)\(/';
        $offenders = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                if (str_contains($path, 'migrations') || str_contains($path, DIRECTORY_SEPARATOR.'Console'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $source = file_get_contents($path);
                if (str_contains($source, 'SchemaCache::') && preg_match($ddl, $source)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                }
                // Hạ tầng migration cũng bị cấm: `Wwhn172MigrationInspector` được
                // gọi TỪ BÊN TRONG một migration đang đổi schema, mà sự kiện
                // `MigrationEnded` chỉ bắn SAU khi migration đó xong — bộ nhớ đệm sẽ
                // trả lời theo schema trước khi migration chạy.
                if (str_contains($source, 'SchemaCache::')
                    && (str_contains($path, DIRECTORY_SEPARATOR.'Migration'.DIRECTORY_SEPARATOR)
                        || str_contains($path, 'MigrationInspector'))) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' (hạ tầng migration)';
                }
            }
        }

        $this->assertSame([], $offenders,
            'File vừa dùng SchemaCache vừa chạy DDL — dùng thẳng facade Schema ở đó.');
    }
}
