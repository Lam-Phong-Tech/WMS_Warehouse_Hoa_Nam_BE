<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Auth\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        // Register capability names only. No automatic role/user grants or demo data.
        foreach (['history.read' => 'Đọc lịch sử hoạt động', 'documents.read' => 'Mở chứng từ từ lịch sử'] as $key => $name) {
            Permission::firstOrCreate(['permission_key' => $key], [
                'permission_name' => $name, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE',
            ]);
        }
    }

    public function down(): void
    {
        // Preserve permissions and any administrator grants made after deployment.
    }
};
