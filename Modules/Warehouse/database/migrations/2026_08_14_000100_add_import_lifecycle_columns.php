<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_request_imports', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbound_request_imports', 'validated_at')) {
                $table->timestamp('validated_at')->nullable();
            }
            if (! Schema::hasColumn('outbound_request_imports', 'consumed_at')) {
                $table->timestamp('consumed_at')->nullable();
            }
            if (! Schema::hasColumn('outbound_request_imports', 'consumed_by')) {
                $table->uuid('consumed_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('outbound_request_imports', function (Blueprint $table): void {
            $table->dropColumn(['validated_at', 'consumed_at', 'consumed_by']);
        });
    }
};
