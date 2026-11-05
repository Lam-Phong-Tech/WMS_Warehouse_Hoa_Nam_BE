<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Repositories\AuditLogRepository;
use Modules\Warehouse\Support\P12DocumentReadContract;
use Tests\TestCase;

final class PostgresTimestampBindingTest extends TestCase
{
    public function test_account_offset_filters_also_use_the_application_wall_clock_on_naive_drivers(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $repo = new class(new AuditLog) extends AuditLogRepository
        {
            public function rangeBindings(string $connection): array
            {
                $model = (new AuditLog)->setConnection($connection);
                $query = $this->applyFilters($model->newQuery(), [
                    'date_from' => '2026-11-05T00:00:00-05:00',
                    'date_to' => '2026-11-05T23:59:59.999999-05:00',
                ]);

                return $query->getConnection()->prepareBindings($query->getBindings());
            }
        };
        $this->assertSame(['2026-11-05 12:00:00', '2026-11-06 11:59:59'], $repo->rangeBindings('sqlite'));
        $this->assertSame(['2026-11-05 12:00:00.000000+07:00', '2026-11-06 11:59:59.999999+07:00'], $repo->rangeBindings('pgsql'));
    }

    public function test_mini_app_date_filters_keep_their_offset_until_driver_binding(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $query = DB::connection('pgsql')->table('stock_documents');
        P12DocumentReadContract::applyCreatedRange($query, Request::create('/', 'GET', [
            'date_from' => '2026-11-05', 'date_to' => '2026-11-05', 'timezone' => 'Asia/Ho_Chi_Minh',
        ]));
        $this->assertSame(['2026-11-05 00:00:00.000000+07:00', '2026-11-06 00:00:00.000000+07:00'],
            $query->getConnection()->prepareBindings($query->getBindings()));
    }

    public function test_query_builder_and_eloquent_keep_offset_and_precision_without_connecting(): void
    {
        $connection = DB::connection('pgsql');
        $at = CarbonImmutable::parse('2026-11-05T16:51:12.123456+07:00');
        $this->assertSame(['2026-11-05 16:51:12.123456+07:00'], $connection->prepareBindings([$at]));

        $event = new AuditLog;
        $event->setConnection('pgsql');
        $event->created_at = $at;
        $this->assertSame('2026-11-05 16:51:12.123456+07:00', $event->getAttributes()['created_at']);
        $this->assertSame('2026-11-05T09:51:12.123456Z', $event->toArray()['created_at']);
    }

    public function test_sqlite_keeps_its_existing_wall_clock_bindings(): void
    {
        $at = CarbonImmutable::parse('2026-11-05T16:51:12+07:00');
        $this->assertSame(['2026-11-05 16:51:12'], DB::connection('sqlite')->prepareBindings([$at]));
    }
}
