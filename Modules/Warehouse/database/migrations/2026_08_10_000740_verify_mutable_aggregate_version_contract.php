<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->models() as $modelClass) {
            /** @var Model $model */
            $model = new $modelClass;
            $tableName = $model->getTable();
            if (! Schema::hasTable($tableName)) {
                throw new RuntimeException("Mutable aggregate table {$tableName} is missing for {$modelClass}.");
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
            \Modules\Warehouse\Models\Warehouse::class,
            \Modules\Warehouse\Models\Agency::class,
            \Modules\Warehouse\Models\DefectCatalog::class,
            \Modules\Warehouse\Models\ProductMaster::class,
            \Modules\Warehouse\Models\ImportBatch::class,
            \Modules\Warehouse\Models\ItemInstance::class,
            \Modules\Auth\Models\User::class,
            \Modules\Auth\Models\Role::class,
        ];
    }
};
