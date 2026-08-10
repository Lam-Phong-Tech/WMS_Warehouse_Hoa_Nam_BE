<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Agency;
use Modules\Warehouse\Models\DefectCatalog;
use Modules\Warehouse\Models\ImportBatch;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Models\Warehouse;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->models() as $modelClass) {
            /** @var Model $model */
            $model = new $modelClass;
            $tableName = $model->getTable();
            if (! Schema::hasTable($tableName)) {
                throw new \RuntimeException("Mutable aggregate table {$tableName} is missing for {$modelClass}.");
            }
            if (! Schema::hasColumn($tableName, 'version')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->unsignedInteger('version')->default(1);
                });
            }
        }
    }

    public function down(): void
    {
        // Optimistic-lock version is a current-baseline invariant and is retained.
    }

    /** @return array<int, class-string<Model>> */
    private function models(): array
    {
        return [
            Warehouse::class,
            Agency::class,
            DefectCatalog::class,
            ProductMaster::class,
            ImportBatch::class,
            ItemInstance::class,
            User::class,
            Role::class,
        ];
    }
};
