<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AuditLog\Database\Seeders\AuditLogDatabaseSeeder;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AuthDatabaseSeeder::class,
            AuditLogDatabaseSeeder::class,
        ]);
    }
}