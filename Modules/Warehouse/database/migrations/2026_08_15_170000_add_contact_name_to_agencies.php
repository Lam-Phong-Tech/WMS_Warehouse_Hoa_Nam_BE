<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD bảng 9 (agencies) định nghĩa contact_name varchar(200) nullable ("Liên hệ")
 * nhưng bảng runtime `agencies` được tạo trước khi cột này vào baseline.
 * Bổ sung để API/FE hiển thị "Người liên hệ" đúng wireframe AGY-01/AGY-02.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agencies', 'contact_name')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->string('contact_name', 200)->nullable()->after('agency_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'contact_name')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropColumn('contact_name');
            });
        }
    }
};
