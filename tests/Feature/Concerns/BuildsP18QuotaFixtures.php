<?php

namespace Tests\Feature\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\AttachmentUploadReceipts;

/** Disposable database and private fake disk only; never a production seeder. */
trait BuildsP18QuotaFixtures
{
    use BuildsScanDrivenInbound;
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;

    private function quotaActor(): array
    {
        Storage::fake('local');
        config(['documents.disk' => 'local', 'documents.attachment_scan_command' => '',
            'warranty.photo_disk' => 'local', 'warranty.attachment_scan_command' => '']);
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Isolated quota test warehouse');
        $suffix = Str::lower(Str::random(12));
        $password = bin2hex(random_bytes(18));
        $user = User::create(['username' => 'quota_'.$suffix, 'display_name' => 'Isolated quota actor',
            'email' => 'quota_'.$suffix.'@example.test', 'password_hash' => Hash::make($password),
            'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'QUOTA_'.$suffix, 'role_name' => 'Isolated permission fixture',
            'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['inbound.view', 'inbound.create', 'inbound.scan', 'outbound.view', 'outbound.scan',
            'warranty.view', 'warranty.manage', 'documents.read'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(),
                'role_id' => $role->role_id, 'permission_id' => $permission->permission_id,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $this->quotaHeaders('');
        $login = $this->browserLogin($user->email, $password)->assertOk();
        $token = $this->postJson('/api/v1/auth/switch-context',
            ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->quotaHeaders($login->json('data.access_token')))->assertOk()->json('data.access_token');

        return [$token, $warehouse];
    }

    private function quotaParent(string $owner, string $token, Warehouse $warehouse): string
    {
        if ($owner === 'INBOUND') {
            return $this->createInboundHeader($this->quotaHeaders($token), $warehouse->id)['document_id'];
        }
        if ($owner === 'OUTBOUND') {
            $sku = $this->productSkuFixture('QUOTA-'.Str::upper(Str::random(8)));
            $request = $this->outboundRequestFixture($sku->sku_id, 1, 'QUOTA-'.Str::upper(Str::random(8)));

            return $this->outboundDocumentFixture($warehouse->id, $request)['document_id'];
        }

        return $this->postJson('/api/v1/warranty-cases', ['warehouse_id' => $warehouse->id,
            'customer_name' => 'Isolated quota fixture', 'customer_phone' => '0900000000',
            'description' => 'Disposable test', 'missing_code_reason' => 'Disposable test',
            'manual_product_description' => 'Disposable test'], $this->quotaHeaders($token))
            ->assertCreated()->json('data.warranty_case_id');
    }

    private function quotaHeaders(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function quotaUploadHeaders(string $token, Warehouse $warehouse, UploadedFile $file, string $key): array
    {
        return $this->quotaHeaders($token) + ['X-Upload-Contract' => AttachmentUploadReceipts::CONTRACT,
            'X-Upload-SHA256' => hash_file('sha256', $file->getRealPath()),
            'X-Upload-Warehouse-ID' => $warehouse->id, 'Idempotency-Key' => $key];
    }

    private function quotaPath(string $owner, string $id, bool $web = false): string
    {
        $path = match ($owner) {
            'INBOUND' => 'inbound-documents',
            'OUTBOUND' => 'outbound-documents',
            'WARRANTY' => 'warranty-cases',
        };

        return '/api/v1/'.($web ? '' : 'mini-app/').$path.'/'.$id.'/attachments';
    }

    private function quotaRows(string $owner, string $id)
    {
        return $owner === 'WARRANTY'
            ? DB::table('warranty_attachments')->where('warranty_case_id', $id)
            : DB::table('document_attachments')->where('document_type', $owner)->where('document_id', $id);
    }

    private function primeQuota(string $owner, string $id, string $token): int
    {
        $limit = $owner === 'WARRANTY' ? 10 : 20;
        $file = UploadedFile::fake()->image('quota-seed.png');
        $first = $this->post($this->quotaPath($owner, $id, true), ['file' => $file], $this->quotaHeaders($token))
            ->assertCreated()->json('data.id');
        $original = (array) $this->quotaRows($owner, $id)->where('id', $first)->first();
        // Pre-existing metadata fixtures test quota, not binary availability.
        // Only the two competing HTTP uploads create new bytes during the race.
        for ($n = 1; $n < $limit - 1; $n++) {
            $row = $original;
            $row['id'] = (string) Str::uuid();
            foreach (['storage_key', 'storage_path'] as $column) {
                if (isset($row[$column])) {
                    $row[$column] = str_replace($first, $row['id'], $row[$column]);
                }
            }
            $table = $owner === 'WARRANTY' ? 'warranty_attachments' : 'document_attachments';
            DB::table($table)->insert($row);
        }
        $this->assertSame($limit - 1, $this->quotaRows($owner, $id)->where('status', 'ACTIVE')->count());

        return $limit;
    }
}
