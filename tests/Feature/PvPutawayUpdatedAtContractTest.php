<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\PvRelations;
use Tests\Feature\Concerns\PreparesPutawayFixtures;
use Tests\TestCase;

class PvPutawayUpdatedAtContractTest extends TestCase
{
    use PreparesPutawayFixtures;
    use RefreshDatabase;

    public function test_profile_create_list_and_update_expose_persisted_profile_time(): void
    {
        $headers = $this->timestampAuthHeaders();
        $sku = Sku::create([
            'sku_code' => 'PV-UPDATED-AT',
            'sku_name' => 'Timestamp contract product',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        $payload = [
            'sku_id' => $sku->sku_id,
            'display_name' => 'Timestamp contract profile',
            'description' => null,
            'sort_order' => 0,
            'industry_ids' => [],
            'tag_ids' => [],
        ];
        $url = '/api/v1/pv-admin/resources/product-profiles';
        $created = $this->postJson($url, $payload, $headers)->assertCreated();
        $id = $created->json('data.id');
        $relation = PvRelations::profile();
        $query = fn () => DB::table($relation['table'])->where($relation['key'], $id);
        $this->assertSame($this->iso($query()->value('updated_at')), $created->json('data.updated_at'));

        // Different from both the SKU timestamp and the current request time.
        $persisted = '2026-09-10 03:04:05';
        $query()->update(['updated_at' => $persisted]);
        $filtered = $url.'?keyword=PV-UPDATED-AT';
        $first = $this->getJson($filtered, $headers)->assertOk();
        $first->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.updated_at', $this->iso($persisted));
        $this->getJson($filtered, $headers)->assertOk()
            ->assertJsonPath('data.0.updated_at', $this->iso($persisted));
        $this->assertSame($this->iso($persisted), $this->iso($query()->value('updated_at')));

        $payload['display_name'] = 'Updated timestamp contract profile';
        unset($payload['sku_id']);
        $updated = $this->patchJson($url.'/'.$id, $payload, [
            ...$headers,
            'If-Match' => (string) $first->json('data.0.version'),
        ])->assertOk();
        $expected = $this->iso($query()->value('updated_at'));
        $this->assertNotSame($this->iso($persisted), $expected);
        $updated->assertJsonPath('data.updated_at', $expected);
        $this->getJson($filtered, $headers)->assertOk()
            ->assertJsonPath('data.0.updated_at', $expected)
            ->assertJsonPath('data.0.display_name', $payload['display_name']);
    }

    public function test_putaway_queue_exposes_box_time_and_reads_do_not_mutate_it(): void
    {
        $headers = $this->timestampAuthHeaders();
        $sku = Sku::create([
            'sku_code' => 'PTW-UPDATED-AT',
            'sku_name' => 'Timestamp contract component',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $box = Container::create([
            'container_code' => 'BOX-UPDATED-AT',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);
        $this->seedPostedReceiptForBox((string) $box->container_id, (string) $sku->sku_id);
        $query = fn () => DB::table('containers')->where('container_id', $box->container_id);
        $persisted = '2026-09-11 06:07:08';
        $query()->update(['updated_at' => $persisted]);
        $url = '/api/v1/putaway/tasks?search=BOX-UPDATED-AT&per_page=1';
        for ($read = 0; $read < 2; $read++) {
            $this->getJson($url, $headers)->assertOk()
                ->assertJsonPath('data.0.box_container_id', (string) $box->container_id)
                ->assertJsonPath('data.0.updated_at', $this->iso($persisted))
                ->assertJsonPath('data.0.quantity', 5);
        }
        $this->assertSame($this->iso($persisted), $this->iso($query()->value('updated_at')));

        $next = '2026-09-12 09:10:11';
        $query()->update(['updated_at' => $next]);
        $this->getJson($url, $headers)->assertOk()
            ->assertJsonPath('data.0.updated_at', $this->iso($next));
        $this->assertSame($this->iso($next), $this->iso($query()->value('updated_at')));
    }

    private function iso(string $value): string
    {
        return Carbon::parse($value, config('app.timezone'))->toISOString();
    }

    private function timestampAuthHeaders(): array
    {
        $this->ensureActiveWarehouse('KHO-TONG-HN');
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'SUPER_ADMIN', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE'],
        );
        $user = User::create([
            'username' => 'timestamp-'.Str::lower(Str::random(8)),
            'display_name' => 'Timestamp contract tester',
            'password_hash' => Hash::make(Str::random(40)),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }
}
