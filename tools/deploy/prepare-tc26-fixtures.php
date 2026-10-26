<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\UserBusinessProfile;
use Modules\Warehouse\Models\Sku;

// stdout is a private pipe to the coordinator, NEVER a workflow log or artifact.
try {
    if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql'
        || DB::connection()->getDatabaseName() !== 'wms_hoanam_green'
        || getenv('FUTURE_FIXTURE_CONFIRM') !== 'TC26-TEST-ONLY-APPROVED') {
        throw new RuntimeException('TC26_TARGET_GUARD');
    }
    $email = 'tc26.fixture@wms-hoanam.test';
    if (User::where('email', $email)->exists() || DB::table('sku')->where('sku_code', 'TC26-PRODUCT')->exists()) {
        throw new RuntimeException('TC26_ALREADY_PROVISIONED_USE_EXISTING_PRIVATE_JOURNAL');
    }
    $warehouse = DB::table('warehouses')->where('code', 'KHO-TONG-HN')->where('status', 'ACTIVE')->sole();
    $password = bin2hex(random_bytes(24)).'aA1!';
    $result = DB::transaction(function () use ($email, $password, $warehouse): array {
        $user = User::create(['username' => 'tc26.fixture', 'email' => $email, 'display_name' => 'TC26 TEST Operator',
            'password_hash' => Hash::make($password), 'status' => 'ACTIVE', 'must_change_password' => false, 'version' => 1]);
        $role = Role::create(['role_code' => 'TC26_FIXTURE', 'role_name' => 'TC26 warehouse TEST only', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        foreach (['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post', 'inbound.cancel',
            'outbound.view', 'outbound.scan', 'outbound.post', 'outbound.request.manual_create', 'outbound.cancel',
            'warranty.view', 'warranty.manage', 'warranty.component_issue', 'documents.read',
            'inventory.view', 'inventory.trace', 'history.read', 'catalog.view', 'sku.view'] as $key) {
            $permission = Permission::where('permission_key', $key)->where('status', 'ACTIVE')->sole();
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        }
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        UserBusinessProfile::put($user->user_id, 'Nhân viên kho TEST', $user->user_id, 'USER_APPROVED_TC26_TEST_FIXTURE');
        $skus = [];
        foreach (['PRODUCT' => true, 'COMPONENT' => true] as $type => $tracked) {
            $master = DB::table('sku')->where('status', 'ACTIVE')->where('sku_type', $type)
                ->whereNotNull('brand_id')->whereNotNull('category_id')->whereNotNull('model_id')->first();
            if ($master === null) {
                throw new RuntimeException('TC26_MASTER_REFERENCE_MISSING');
            }
            $sku = Sku::create(['sku_code' => 'TC26-'.$type, 'sku_name' => 'TC26 TEST '.$type,
                'sku_type' => $type, 'unit' => $master->unit, 'is_serial_tracked' => $tracked,
                'brand_id' => $master->brand_id, 'category_id' => $master->category_id, 'model_id' => $master->model_id,
                'warranty_applicable' => $type === 'PRODUCT', 'published_to_app' => false, 'status' => 'ACTIVE']);
            $skus[$type] = ['id' => $sku->sku_id, 'code' => $sku->sku_code, 'category_id' => $sku->category_id, 'unit' => $sku->unit];
        }
        $supplier = (string) Str::uuid();
        DB::table('inbound_suppliers')->insert(['id' => $supplier, 'warehouse_id' => $warehouse->id,
            'name' => 'TC26 TEST SUPPLIER', 'status' => 'ACTIVE', 'version' => 1,
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return ['email' => $email, 'password' => $password, 'actor_id' => $user->user_id,
            'warehouse_id' => $warehouse->id, 'supplier_id' => $supplier, 'skus' => $skus,
            'prefix' => 'TC26', 'environment' => 'DEV_TEST_ONLY'];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    fwrite(STDERR, 'TC26_PROVISION_FAILED:'.get_class($error).PHP_EOL);
    exit(1);
}
