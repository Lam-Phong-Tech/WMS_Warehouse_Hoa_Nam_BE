<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('department')) {
            Schema::create('department', function (Blueprint $table): void {
                $table->uuid('department_id')->primary();
                $table->string('department_code', 80)->unique();
                $table->string('department_name', 200);
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->string('description', 500)->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('app_user') && ! Schema::hasColumn('app_user', 'department_id')) {
            Schema::table('app_user', function (Blueprint $table): void {
                // Nullable during rollout: existing users keep working until BA
                // assigns the confirmed department master values.
                $table->uuid('department_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_user') && Schema::hasColumn('app_user', 'department_id')) {
            Schema::table('app_user', fn (Blueprint $table) => $table->dropColumn('department_id'));
        }
        Schema::dropIfExists('department');
    }
};
