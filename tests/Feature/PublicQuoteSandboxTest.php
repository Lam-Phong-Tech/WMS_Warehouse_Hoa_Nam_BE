<?php

namespace Tests\Feature;

use App\Console\Commands\PublicQuoteSandbox;
use App\Support\SqliteUnaccentFunction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\PublicQuoteRequest;
use Modules\Warehouse\Models\PublicQuoteRequestItem;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;

class PublicQuoteSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SqliteUnaccentFunction::forget();
        config()->set('public_sandbox.enabled', true);
        config()->set('mail.default', 'array');
        config()->set('queue.default', 'sync');
        config()->set('broadcasting.default', 'null');
        config()->set('public_api.privacy.version', '1.0.0');
        config()->set('public_api.feature_flags.quote_request', true);
        config()->set('public_api.maintenance.enabled', false);
        config()->set('public_api.quote_request.max_items', 20);
    }

    public function test_prepare_is_repeatable_and_returns_twenty_one_real_public_ids(): void
    {
        $first = $this->prepare();
        $second = $this->prepare();
        $this->assertSame($first['product_ids'], $second['product_ids']);
        $this->assertCount(21, array_unique($first['product_ids']));
        $this->assertSame(21, Sku::query()->count());
        $this->assertFalse($first['deployment_verified']);
        $this->assertSame('0000000000', $first['quote_template']['phone']);
        $this->getJson('/api/v1/public/products?q=UAT-QUOTE&limit=20')
            ->assertOk()->assertJsonCount(20, 'data.items')->assertJsonPath('data.page_info.total', 21);
        $this->assertSame(0, PublicQuoteRequest::query()->count());
    }

    public function test_fixture_supports_real_quote_create_replay_conflict_and_twenty_item_boundary(): void
    {
        $manifest = $this->prepare();
        $payload = $manifest['quote_template'];
        $headers = ['Idempotency-Key' => PublicQuoteSandbox::KEY_PREFIX.'contract-0001'];
        $first = $this->postJson('/api/v1/public/quote-requests', $payload, $headers)
            ->assertCreated()->assertJsonPath('data.status', 'RECEIVED');
        $this->postJson('/api/v1/public/quote-requests', $payload, $headers)
            ->assertOk()->assertJsonPath('data.request_id', $first->json('data.request_id'));
        $this->postJson('/api/v1/public/quote-requests', ['note' => 'Changed synthetic note'] + $payload, $headers)
            ->assertConflict()->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, PublicQuoteRequest::query()->count());
        $this->assertSame(1, PublicQuoteRequestItem::query()->count());

        $payload['items'] = array_map(static fn (string $id): array => ['product_id' => $id], $manifest['product_ids']);
        $this->postJson('/api/v1/public/quote-requests', $payload, ['Idempotency-Key' => PublicQuoteSandbox::KEY_PREFIX.'boundary-21'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_FAILED');
        array_pop($payload['items']);
        $this->postJson('/api/v1/public/quote-requests', $payload, ['Idempotency-Key' => PublicQuoteSandbox::KEY_PREFIX.'boundary-20'])
            ->assertCreated();
        $this->assertSame(2, PublicQuoteRequest::query()->count());
        $this->assertSame(21, PublicQuoteRequestItem::query()->count());
    }

    public function test_reset_requires_confirmation_and_only_removes_sandbox_key_prefix(): void
    {
        $payload = $this->prepare()['quote_template'];
        foreach ([PublicQuoteSandbox::KEY_PREFIX.'reset-000001', 'non-sandbox-control-000001'] as $key) {
            $this->postJson('/api/v1/public/quote-requests', $payload, ['Idempotency-Key' => $key])->assertCreated();
        }
        $this->assertSame(1, Artisan::call('wms:public-quote-sandbox', ['action' => 'reset-quotes']));
        $this->assertSame(2, PublicQuoteRequest::query()->count());
        $this->assertSame(0, Artisan::call('wms:public-quote-sandbox', [
            'action' => 'reset-quotes', '--confirm' => 'RESET_SANDBOX_QUOTES',
        ]));
        $this->assertSame(1, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['reset_deleted_requests']);
        $this->assertSame('non-sandbox-control-000001', PublicQuoteRequest::query()->sole()->idempotency_key);
        $this->assertSame(1, PublicQuoteRequestItem::query()->count());
        $this->assertSame(21, Sku::query()->count());
    }

    public function test_disabled_sandbox_cannot_prepare_or_reset(): void
    {
        config()->set('public_sandbox.enabled', false);
        foreach (['prepare', 'manifest', 'reset-quotes'] as $action) {
            $this->assertSame(1, Artisan::call('wms:public-quote-sandbox', ['action' => $action]));
        }
        $this->assertSame(0, Sku::query()->count());
    }

    public function test_non_testing_environments_cannot_enable_the_command(): void
    {
        foreach (['production', 'prod', 'uat', 'staging'] as $environment) {
            $this->app->detectEnvironment(static fn (): string => $environment);
            $this->assertSame(1, Artisan::call('wms:public-quote-sandbox', ['action' => 'prepare']));
        }
        $this->app->detectEnvironment(static fn (): string => 'testing');
        $this->assertSame(0, Sku::query()->count());
    }

    public function test_null_broadcast_env_value_is_safe_and_can_prepare(): void
    {
        // Laravel parses the environment literal null into PHP null.
        config()->set('broadcasting.default', null);
        $this->assertCount(21, $this->prepare()['product_ids']);
    }

    public function test_real_notification_or_queue_drivers_are_refused(): void
    {
        foreach (['mail.default' => 'smtp', 'queue.default' => 'redis', 'broadcasting.default' => 'reverb'] as $key => $value) {
            $previous = config($key);
            config()->set($key, $value);
            $this->assertSame(1, Artisan::call('wms:public-quote-sandbox', ['action' => 'prepare']));
            config()->set($key, $previous);
        }
        $this->assertSame(0, Sku::query()->count());
    }

    public function test_existing_non_sandbox_products_are_never_overwritten(): void
    {
        $sku = Sku::create([
            'sku_code' => 'NOT-A-SANDBOX-SKU', 'sku_name' => 'Keep unchanged',
            'sku_type' => 'PRODUCT', 'unit' => 'cai', 'status' => 'ACTIVE',
            'is_serial_tracked' => false,
        ]);
        $before = DB::table('sku')->get()->toJson();
        $this->assertSame(1, Artisan::call('wms:public-quote-sandbox', ['action' => 'prepare']));
        $this->assertSame($before, DB::table('sku')->get()->toJson());
        $this->assertSame('Keep unchanged', $sku->fresh()->sku_name);
    }

    public function test_unapproved_database_name_is_refused_before_a_query(): void
    {
        $original = config('database.default');
        config()->set('database.connections.denied_sandbox', [
            'driver' => 'sqlite', 'database' => '/tmp/not-an-approved-sandbox.sqlite', 'prefix' => '',
        ]);
        config()->set('database.default', 'denied_sandbox');
        try {
            $this->assertSame(1, Artisan::call('wms:public-quote-sandbox', ['action' => 'prepare']));
            $this->assertStringContainsString('Refusing database', Artisan::output());
        } finally {
            config()->set('database.default', $original);
            DB::purge('denied_sandbox');
        }
        $this->assertSame(0, Sku::query()->count());
    }

    private function prepare(): array
    {
        $exit = Artisan::call('wms:public-quote-sandbox', ['action' => 'prepare']);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertJson($output);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
