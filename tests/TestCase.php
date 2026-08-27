<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Modules\Warehouse\Models\Warehouse;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Chốt chặn chống xoá nhầm CSDL phát triển.
     *
     * phpunit.xml ép DB_CONNECTION=sqlite, NHƯNG biến môi trường của container
     * (docker-compose.yml đặt DB_CONNECTION=pgsql, DB_DATABASE=wms_hoanam) có
     * độ ưu tiên CAO HƠN khối <env> của phpunit. Vì vậy `docker compose exec
     * backend php artisan test` chạy thẳng vào CSDL dev thật, và bất kỳ test nào
     * dùng RefreshDatabase sẽ migrate:fresh — tức XOÁ SẠCH dữ liệu đang dùng.
     * Sự cố này đã xảy ra thật (mất toàn bộ wms_hoanam ngày 27/08).
     *
     * Cách chạy đúng, truyền biến môi trường ngay tại lệnh exec:
     *   docker compose exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
     *     backend php artisan test
     *
     * Guard fail-closed: thà test không chạy còn hơn mất dữ liệu.
     */
    protected function setUp(): void
    {
        // PHẢI kiểm tra TRƯỚC parent::setUp(): chính parent::setUp() là nơi
        // trait RefreshDatabase chạy migrate:fresh. Kiểm tra sau đó là vô dụng,
        // dữ liệu đã bị xoá xong rồi (đã kiểm chứng bằng chính sự cố 27/08).
        $this->assertTestDatabaseIsDisposable();
        parent::setUp();
    }

    private function assertTestDatabaseIsDisposable(): void
    {
        // Chỉ chặn test thật sự huỷ dữ liệu. Test đọc/ghi thường vẫn được phép
        // chạy trên PostgreSQL thật (QA chạy live trên PG là quy trình có sẵn).
        $refreshesDatabase = array_intersect(
            [RefreshDatabase::class, DatabaseMigrations::class, DatabaseTruncation::class],
            class_uses_recursive(static::class),
        ) !== [];

        if (! $refreshesDatabase) {
            return;
        }

        // Chưa boot ứng dụng ở thời điểm này nên không dùng được facade DB.
        // Tự dựng một app tạm chỉ để đọc config đã resolve xong (đúng thứ tự ưu
        // tiên .env / biến môi trường container / phpunit.xml), rồi bỏ đi.
        $app = $this->createApplication();
        $connectionName = (string) $app['config']->get('database.default');
        $driver = (string) $app['config']->get("database.connections.{$connectionName}.driver");
        $database = (string) $app['config']->get("database.connections.{$connectionName}.database");
        $app->flush();

        if ($driver === 'sqlite') {
            return;
        }

        if (str_contains($database, 'test') || str_contains($database, ':memory:')) {
            return;
        }

        throw new RuntimeException(
            "Test đang trỏ vào CSDL '{$database}' (driver {$driver}) — đây không phải CSDL dùng một lần. ".
            'Test dùng RefreshDatabase sẽ XOÁ SẠCH dữ liệu ở đây. '.
            'Chạy lại bằng: docker compose exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: backend php artisan test'
        );
    }

    /**
     * REQ-SRS-001 + uk_warehouses_single_active: hệ thống chỉ có đúng một
     * warehouse ACTIVE. Fixture nào cần "kho tổng" phải tái sử dụng bản ghi
     * ACTIVE hiện hữu (đổi code/name theo test, rollback theo transaction)
     * thay vì tạo bản thứ hai và vỡ unique guard.
     */
    protected function ensureActiveWarehouse(string $code, ?string $name = null): Warehouse
    {
        $existing = Warehouse::query()->where('status', 'ACTIVE')->first();
        if ($existing !== null) {
            if ($existing->code !== $code || ($name !== null && $existing->name !== $name)) {
                $existing->forceFill(['code' => $code, 'name' => $name ?? $code])->save();
            }

            return $existing->refresh();
        }

        return Warehouse::create([
            'code' => $code,
            'name' => $name ?? $code,
            'status' => 'ACTIVE',
        ]);
    }
}
