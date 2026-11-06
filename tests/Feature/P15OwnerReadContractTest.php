<?php

namespace Tests\Feature;

use App\Support\IdempotencyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\P04InboundController;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Isolated database tests only. Owner writes below prepare real software receipts
 * in the disposable test database; every P15 check is GET. No hardware/live PASS.
 */
class P15OwnerReadContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const RIGHTS = ['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.cancel', 'outbound.view', 'outbound.import', 'outbound.scan', 'outbound.cancel', 'physical_code.assign_rfid', 'inventory.trace'];

    public function test_manifest_and_collection_only_target_existing_authenticated_owner_reads(): void
    {
        $contract = $this->contract();
        $this->assertSame(['GET'], $contract['methods']);
        $this->assertSame([], $contract['new_api_routes']);
        $this->assertSame([], $contract['fallbacks']);
        $this->assertSame('PENDING_OWNER_CONTRACT_AND_LIVE_TEST', $contract['status']);
        foreach (['production_activation', 'live_test_verified', 'ui_wiring_verified', 'hardware_verified'] as $flag) {
            $this->assertFalse($contract[$flag]);
        }
        $collection = json_decode(file_get_contents(base_path('contracts/p15/P15-system-read.postman_collection.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(count($contract['reads']), $collection['item']);
        $this->assertFalse($collection['protocolProfileBehavior']['followRedirects']);
        foreach ($collection['item'] as $item) {
            $spec = $contract['reads'][$item['name']];
            $this->assertSame('GET', $item['request']['method']);
            $this->assertArrayNotHasKey('body', $item['request']);
            $path = $spec['path'];
            foreach ($spec['path_vars'] ?? [] as $parameter => $variable) {
                $path = str_replace('{'.$parameter.'}', '{{'.$variable.'}}', $path);
            }
            $url = explode('?', $item['request']['url'], 2);
            $this->assertSame('{{api_origin}}'.$path, $url[0]);
            parse_str($url[1] ?? '', $query);
            $this->assertEqualsCanonicalizing($spec['query'], array_keys($query));
            foreach ($spec['fixed_query'] ?? [] as $key => $value) {
                $this->assertSame($value, $query[$key]);
            }
            $routePath = preg_replace('/\{[^}]+\}/', (string) Str::uuid(), $spec['path']);
            $route = Route::getRoutes()->match(Request::create($routePath, 'GET'));
            $this->assertContains('auth:api', $route->gatherMiddleware());
        }
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('~/p15(?:/|$)~', $route->uri());
        }
        $scripts = json_encode($collection['event'], JSON_THROW_ON_ERROR);
        foreach (['pm.sendRequest', 'pm.execution.runRequest', 'pm.execution.setNextRequest', 'pm.environment.set', 'pm.collectionVariables.set'] as $writer) {
            $this->assertStringNotContainsString($writer, $scripts);
        }
        foreach ($collection['variable'] as $variable) {
            $this->assertContains($variable['value'], ['', 'false']);
        }
        $this->assertNotContains('operation', $contract['reads']['P04_RECONCILE']['query']);
        $this->assertSame('OUTBOUND', $contract['reads']['P05_RECONCILE']['fixed_query']['operation']);
        $this->assertSame(['Idempotency-Key' => 'operation_key'], $contract['reads']['P07_CONFIRMATION']['headers']);
    }

    public function test_owner_checks_require_authentication_without_creating_any_receipt(): void
    {
        $before = $this->domainSnapshot();
        foreach (['P01_ME', 'P03_CONTEXT', 'P04_RECONCILE', 'P05_RECONCILE', 'P07_CONFIRMATION'] as $name) {
            $values = $this->identity((string) Str::uuid());
            $this->resetAuthentication();
            $this->getJson($this->readUrl($name, $values))->assertUnauthorized();
        }
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_absent_draft_receipts_stay_unknown_for_each_command_without_writing(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $before = $this->domainSnapshot();
        foreach (['P04_RECONCILE', 'P05_RECONCILE'] as $name) {
            foreach (['SAVE', 'CANCEL', 'RECORD'] as $command) {
                $values = $this->identity((string) $warehouse->id);
                $values['command'] = $command;
                $this->readOwner($name, $values, $token)->assertOk()
                    ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null)
                    ->assertJsonPath('data.response', null)->assertJsonPath('data.document', null)
                    ->assertJsonPath('data.request_id', $values['operation_key'])
                    ->assertJsonPath('data.command', $command)->assertJsonPath('data.retry', 'SAME_REQUEST_ONLY')
                    ->assertJsonPath('data.new_document_allowed', false)->assertJsonPath('data.cancel_allowed', false);
            }
        }
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_p04_receipt_version_and_command_are_not_replaced_by_current_document_state(): void
    {
        [$actor, $token, $warehouse, , $session] = $this->actorSession();
        [$values, $body] = $this->saveInbound($token, (string) $warehouse->id);
        $this->putJson('/api/v1/mini-app/inbound/drafts/'.$values['draft_id'], $body,
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"1"'])->assertOk();
        // P04 native includes the persisted owner; generic P03/P05 projection omits it.
        $this->assertDatabaseHas('mini_app_drafts', ['document_id' => $values['draft_id'], 'actor_id' => (string) $actor->user_id, 'auth_session_id' => $session]);
        $this->assertDatabaseHas('mini_app_draft_receipts', ['request_id' => $values['operation_key'], 'actor_id' => (string) $actor->user_id, 'auth_session_id' => $session]);
        $before = $this->domainSnapshot();
        for ($click = 0; $click < 2; $click++) {
            $this->readOwner('P04_RECONCILE', $values, $token)->assertOk()
                ->assertJsonPath('meta.contract', 'mini-app-p04-v1')->assertJsonPath('data.outcome', 'SUCCEEDED')
                ->assertJsonPath('data.receipt.request_id', $values['operation_key'])
                ->assertJsonPath('data.receipt.command', 'SAVE')->assertJsonPath('data.receipt.version', 1)
                ->assertJsonPath('data.document.version', 2)->assertJsonPath('data.state', 'DRAFT')
                ->assertJsonPath('data.document.actor_id', (string) $actor->user_id)
                ->assertJsonPath('data.document.auth_session_id', $session)->assertJsonPath('data.cancel_allowed', false);
        }
        $this->readOwner('P04_RECONCILE', [...$values, 'command' => 'RECORD'], $token)
            ->assertConflict()->assertJsonPath('error.code', 'REQUEST_IDENTITY_MISMATCH');
        $this->readOwner('P04_RECONCILE', [...$values, 'scan_session_id' => (string) Str::uuid()], $token)
            ->assertConflict()->assertJsonPath('error.code', 'REQUEST_IDENTITY_MISMATCH');
        $this->readOwner('P04_CURRENT_DRAFT', $values, $token)->assertOk()->assertHeader('ETag', '"2"');
        $this->getJson($this->readUrl('P04_CURRENT_DRAFT', $values), $this->headers($token) + ['If-Match' => '"1"'])
            ->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->getJson($this->readUrl('P04_RECONCILE', $values).'&operation=INBOUND', $this->headers($token))
            ->assertStatus(422)->assertJsonPath('error.code', 'UNEXPECTED_FIELD');
        $this->assertSame($before, $this->domainSnapshot());
        [, $otherToken] = $this->actorSession();
        $before = $this->domainSnapshot();
        $this->readOwner('P04_RECONCILE', $values, $otherToken)->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null)->assertJsonPath('data.document', null);
        $this->readOwner('P04_CURRENT_DRAFT', $values, $otherToken)->assertNotFound();
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_p04_owner_projection_cannot_substitute_a_different_viewer_for_the_persisted_actor(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        [$values] = $this->saveInbound($token, (string) $warehouse->id);
        $draft = $this->readOwner('P04_CURRENT_DRAFT', $values, $token)->assertOk()->json('data.document');
        [$other] = $this->actorSession();
        $request = Request::create('/isolated-owner-projection');
        $request->setUserResolver(fn () => $other);
        $method = new \ReflectionMethod(P04InboundController::class, 'decorateDraft');
        try {
            $method->invoke(app(P04InboundController::class), $request, $draft);
            $this->fail('Foreign viewer must not become the projected draft actor.');
        } catch (HttpResponseException $error) {
            $this->assertSame(404, $error->getResponse()->getStatusCode());
        }
    }

    public function test_p04_owner_projection_requires_the_persisted_family_and_scan_identity(): void
    {
        [$actor, $token, $warehouse] = $this->actorSession();
        [$values] = $this->saveInbound($token, (string) $warehouse->id);
        $original = $this->readOwner('P04_CURRENT_DRAFT', $values, $token)->assertOk()->json('data.document');
        $request = Request::create('/isolated-owner-projection');
        $request->setUserResolver(fn () => $actor);
        $method = new \ReflectionMethod(P04InboundController::class, 'decorateDraft');
        foreach (['auth_session_id', 'scan_session_id', 'warehouse_id', 'document_id'] as $field) {
            $draft = $original;
            $draft[$field] = (string) Str::uuid();
            try {
                $method->invoke(app(P04InboundController::class), $request, $draft);
                $this->fail('Projection must verify persisted '.$field.'.');
            } catch (HttpResponseException $error) {
                $this->assertSame(404, $error->getResponse()->getStatusCode());
            }
        }
    }

    public function test_p04_latest_save_can_authorize_cancel_but_does_not_rewrite_stored_transport_response(): void
    {
        [$actor, $token, $warehouse] = $this->actorSession();
        [$values] = $this->saveInbound($token, (string) $warehouse->id);
        $stored = DB::table('mini_app_draft_receipts')->where('request_id', $values['operation_key'])->value('response_body');
        $before = $this->domainSnapshot();
        $response = $this->readOwner('P04_RECONCILE', $values, $token)->assertOk()
            ->assertJsonPath('data.document.actor_id', (string) $actor->user_id)
            ->assertJsonPath('data.receipt.version', 1)->assertJsonPath('data.document.version', 1)
            ->assertJsonPath('data.cancel_allowed', true);
        $this->assertSame(json_decode($stored, true, 512, JSON_THROW_ON_ERROR), $response->json('data.response'));
        $this->assertArrayNotHasKey('actor_id', $response->json('data.response.data.document'));
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_controlled_parallel_family_never_inherits_another_family_p04_receipt_or_actor_projection(): void
    {
        config()->set('refresh_token.controlled_multi_session.enabled', true);
        config()->set('app.url', 'http://localhost');
        [$actor, $a, $warehouse, , $familyA] = $this->actorSession();
        [$values] = $this->saveInbound($a, (string) $warehouse->id);
        [$b, , $familyB] = $this->loginSession($actor, $warehouse);
        $this->assertNotSame($familyA, $familyB);
        $before = $this->domainSnapshot();
        $this->readOwner('P04_RECONCILE', $values, $b)->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN')
            ->assertJsonPath('data.receipt', null)->assertJsonPath('data.document', null)->assertJsonPath('data.cancel_allowed', false);
        $this->readOwner('P04_CURRENT_DRAFT', $values, $b)->assertNotFound();
        $this->readOwner('P04_RECONCILE', $values, $a)->assertOk()->assertJsonPath('data.document.actor_id', (string) $actor->user_id)
            ->assertJsonPath('data.document.auth_session_id', $familyA);
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_p03_outbound_read_transport_preserves_the_original_operation_key(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $values = $this->identity((string) $warehouse->id);
        // Transport coverage only. Real P05 source-linked RECORD is separately
        // exercised by P05OutboundContractTest, not inferred from this UI snapshot.
        $this->putJson('/api/v1/mini-app/drafts/'.$values['draft_id'], [
            'warehouse_id' => $values['warehouse_id'], 'operation' => 'OUTBOUND',
            'scan_session_id' => $values['scan_session_id'], 'codes' => [],
        ], $this->headers($token) + ['Idempotency-Key' => $values['operation_key'], 'If-Match' => '"0"'])->assertCreated();
        $before = $this->domainSnapshot();
        $trace = (string) Str::uuid();
        $this->getJson($this->readUrl('P05_RECONCILE', $values), $this->headers($token, $trace))->assertOk()
            ->assertJsonPath('meta.contract', 'mini-app-p03-v1')->assertJsonPath('meta.request_id', $trace)
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.operation', 'OUTBOUND')
            ->assertJsonPath('data.receipt.request_id', $values['operation_key'])->assertJsonPath('data.receipt.command', 'SAVE')
            ->assertJsonMissingPath('data.document.actor_id');
        $this->readOwner('P05_RECONCILE', [...$values, 'operation_key' => $trace], $token)
            ->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null);
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_refresh_preserves_receipts_but_new_login_never_rebinds_old_drafts(): void
    {
        [$actor, $token, $warehouse, $cookie, $session] = $this->actorSession();
        [$values] = $this->saveInbound($token, (string) $warehouse->id);
        $this->resetAuthentication();
        $refresh = $this->browserRefresh($cookie)->assertOk();
        $token = $refresh->json('data.access_token');
        $this->readOwner('P03_CONTEXT', [], $token)->assertOk()->assertJsonPath('data.auth_session_id', $session);
        $this->readOwner('P04_RECONCILE', $values, $token)->assertOk()->assertJsonPath('data.outcome', 'SUCCEEDED');
        [$newToken, , $newSession] = $this->loginSession($actor, $warehouse);
        $this->assertNotSame($session, $newSession);
        $before = $this->domainSnapshot();
        $this->readOwner('P04_RECONCILE', $values, $newToken)->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null)->assertJsonPath('data.document', null);
        $this->readOwner('P04_CURRENT_DRAFT', $values, $newToken)->assertNotFound();
        $this->assertSame($before, $this->domainSnapshot());
        $this->assertDatabaseHas('mini_app_drafts', ['document_id' => $values['draft_id'], 'auth_session_id' => $session]);
    }

    public function test_p07_unknown_missing_key_and_denial_do_not_prepare_link_or_release(): void
    {
        [$actor, $token, $warehouse] = $this->actorSession();
        $values = $this->identity((string) $warehouse->id);
        $values['item_id'] = $this->itemFixture($actor, $warehouse);
        $before = $this->domainSnapshot();
        $this->readOwner('P07_CONFIRMATION', $values, $token)->assertOk()
            ->assertJsonPath('data.state', 'UNKNOWN')->assertJsonPath('data.response', null)
            ->assertJsonPath('data.may_create_new_request', false);
        $this->getJson($this->readUrl('P07_CONFIRMATION', $values), $this->headers($token))
            ->assertStatus(428)->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');
        $this->readOwner('P07_CONFIRMATION', [...$values, 'operation_key' => 'bad'], $token)
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->readOwner('P07_CONFIRMATION', [...$values, 'warehouse_id' => (string) Str::uuid()], $token)
            ->assertConflict()->assertJsonPath('error.code', 'WAREHOUSE_CONTEXT_MISMATCH');
        $this->assertSame($before, $this->domainSnapshot());
        [, $limited] = $this->actorSession(['inventory.trace']);
        $before = $this->domainSnapshot();
        $this->readOwner('P07_CONFIRMATION', $values, $limited)->assertForbidden();
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_p07_operation_receipt_survives_mapping_change_but_not_session_rebinding(): void
    {
        [$actor, $token, $warehouse, , $session] = $this->actorSession();
        $values = $this->identity((string) $warehouse->id);
        $values['item_id'] = $this->itemFixture($actor, $warehouse);
        $url = '/api/v1/mini-app/items/'.$values['item_id'].'/nfc';
        // These are owner fixture operations in the disposable database. No real
        // device or bridge is contacted and no hardware acceptance is claimed.
        $prepared = $this->postJson($url.'/prepare', [], $this->headers($token))->assertOk();
        $trace = (string) Str::uuid();
        $confirmed = $this->patchJson($url, [
            'hardware_uid' => 'A1B2C3D4E5', 'written_payload' => $prepared->json('data.nfc_payload'),
            'reservation_token' => $prepared->json('data.reservation_token'),
        ], $this->headers($token, $trace) + ['Idempotency-Key' => $values['operation_key']])->assertCreated();
        $values['physical_code_id'] = $confirmed->json('data.nfc.physical_code_id');
        $this->postJson('/api/v1/mini-app/nfc-tags/'.$values['physical_code_id'].'/deactivate',
            ['status' => 'LOST', 'reason' => 'P15 isolated mapping change'], $this->headers($token))->assertOk();
        $before = $this->domainSnapshot();
        $this->readOwner('P07_CONFIRMATION', $values, $token)->assertOk()
            ->assertJsonPath('data.state', 'SUCCEEDED')
            ->assertJsonPath('data.response.data.confirmation.auth_session_id', $session)
            ->assertJsonPath('data.response.data.confirmation.item_id', $values['item_id'])
            ->assertJsonPath('data.response.data.confirmation.physical_code_id', $values['physical_code_id']);
        $this->readOwner('P07_CURRENT_TAG', $values, $token)->assertOk()->assertJsonPath('data.raw_status', 'LOST');
        $this->assertSame($before, $this->domainSnapshot());
        [$newToken] = $this->loginSession($actor, $warehouse);
        $before = $this->domainSnapshot();
        $this->readOwner('P07_CONFIRMATION', $values, $newToken)
            ->assertConflict()->assertJsonPath('error.code', 'NFC_CONFIRMATION_CONTEXT_MISMATCH');
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_support_remains_not_configured_and_reads_do_not_select_a_warehouse(): void
    {
        [, $token] = $this->actorSession(confirm: false);
        $before = $this->domainSnapshot();
        $this->readOwner('P01_ME', [], $token)->assertOk();
        $this->readOwner('P03_CONTEXT', [], $token)->assertOk()
            ->assertJsonPath('data.admin_contact.status', 'NOT_CONFIGURED')->assertJsonPath('data.admin_contact.channels', [])
            ->assertJsonPath('data.active_warehouse_id', null)->assertJsonPath('data.warehouse_context.status', 'REQUIRED');
        $this->assertSame($before, $this->domainSnapshot());
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    private function contract(): array
    {
        return json_decode(file_get_contents(base_path('contracts/p15/owner-read-v1.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function identity(string $warehouse): array
    {
        return ['warehouse_id' => $warehouse, 'draft_id' => (string) Str::uuid(), 'scan_session_id' => (string) Str::uuid(),
            'operation_key' => (string) Str::uuid(), 'item_id' => (string) Str::uuid(), 'command' => 'SAVE'];
    }

    private function readUrl(string $name, array $values): string
    {
        $spec = $this->contract()['reads'][$name];
        $path = $spec['path'];
        foreach ($spec['path_vars'] ?? [] as $parameter => $variable) {
            $path = str_replace('{'.$parameter.'}', rawurlencode($values[$variable]), $path);
        }
        $query = [];
        foreach ($spec['query'] as $key) {
            $query[$key] = $spec['fixed_query'][$key] ?? $values[$key];
        }

        return $path.($query === [] ? '' : '?'.http_build_query($query));
    }

    private function readOwner(string $name, array $values, string $token): TestResponse
    {
        $headers = $this->headers($token);
        foreach ($this->contract()['reads'][$name]['headers'] ?? [] as $header => $variable) {
            $headers[$header] = $values[$variable];
        }

        return $this->getJson($this->readUrl($name, $values), $headers);
    }

    private function saveInbound(string $token, string $warehouse): array
    {
        $values = $this->identity($warehouse);
        $body = ['warehouse_id' => $warehouse, 'scan_session_id' => $values['scan_session_id'], 'inbound_type' => 'receipt', 'items' => [], 'name' => 'P15 isolated snapshot'];
        $this->putJson('/api/v1/mini-app/inbound/drafts/'.$values['draft_id'], $body,
            $this->headers($token) + ['Idempotency-Key' => $values['operation_key'], 'If-Match' => '"0"'])->assertCreated();

        return [$values, $body];
    }

    private function actorSession(array $rights = self::RIGHTS, bool $confirm = true): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P15 isolated warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p15_'.$suffix, 'display_name' => 'P15 CI', 'email' => 'p15_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P15_'.$suffix, 'role_name' => 'P15 isolated role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($rights as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$token, $cookie, $session] = $this->loginSession($user, $warehouse, $confirm);

        return [$user, $token, $warehouse, $cookie, $session];
    }

    private function loginSession(User $user, object $warehouse, bool $confirm = true): array
    {
        $this->resetAuthentication();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $token = $login->json('data.access_token');
        $cookie = $this->refreshTokenFromCookie($login);
        if ($confirm) {
            $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id], $this->headers($token))->assertOk();
            $token = $selected->json('data.access_token');
        }
        $context = $this->getJson('/api/v1/mini-app/p03/context', $this->headers($token))->assertOk();
        $session = $context->json('data.auth_session_id');
        $this->assertTrue(Str::isUuid((string) $session));

        return [$token, $cookie, $session];
    }

    private function itemFixture(User $actor, object $warehouse): string
    {
        $suffix = Str::upper(Str::random(8));
        $sku = Sku::create(['sku_code' => 'P15-'.$suffix, 'sku_name' => 'P15 isolated SKU', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => true, 'status' => 'ACTIVE']);
        $item = $this->seedItemInStock($sku->sku_id);
        $id = (string) Str::uuid();
        $row = ['id' => $id, 'movement_no' => 'P15-'.Str::random(12), 'business_key' => 'P15-'.$id,
            'movement_type' => 'OPENING', 'warehouse_id' => $warehouse->id, 'product_id' => $sku->sku_id,
            'stock_status' => 'AVAILABLE', 'qty_before' => 0, 'qty_delta' => 1, 'qty_after' => 1,
            'created_by' => $actor->user_id, 'occurred_at' => now()->subHour(), 'created_at' => now()->subHour()];
        foreach (['item_id' => $item, 'sku_id' => $sku->sku_id, 'quantity' => 1, 'source_type' => 'OPENING', 'source_id' => $id] as $field => $value) {
            if (Schema::hasColumn('stock_movements', $field)) {
                $row[$field] = $value;
            }
        }
        DB::table('stock_movements')->insert($row);

        return $item;
    }

    private function resetAuthentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }

    private function headers(string $token, ?string $trace = null): array
    {
        $this->resetAuthentication();

        return ['Authorization' => 'Bearer '.$token, 'X-Request-ID' => $trace ?? (string) Str::uuid()];
    }

    private function domainSnapshot(): array
    {
        $result = [];
        foreach (['mini_app_drafts', 'mini_app_draft_receipts', 'mini_app_inbound_record_metadata', 'mini_app_work_shifts', 'stock_documents', 'stock_movements', 'inventory_balances', 'physical_codes', IdempotencyStore::TABLE] as $table) {
            $rows = DB::table($table)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $result[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $result;
    }
}
