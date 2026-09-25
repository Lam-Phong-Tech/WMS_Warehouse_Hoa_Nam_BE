<?php

namespace Tests\Feature;

use App\Support\SqliteUnaccentFunction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\PublicQuoteRequest;
use Modules\Warehouse\Models\PublicQuoteRequestItem;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Services\PublicCatalogReadModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** HTTP regression coverage. Synthetic data lives only in a disposable test DB. */
class PublicStorefrontHandoffTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/public';

    protected function setUp(): void
    {
        parent::setUp();
        SqliteUnaccentFunction::forget();
        config()->set('public_api.domains', [
            'POWER_TOOLS' => ['name' => 'UAT Power', 'categories' => ['UAT_POWER']],
            'HAND_TOOLS' => ['name' => 'UAT Hand', 'categories' => ['UAT_HAND']],
            'ACCESSORIES' => ['name' => 'UAT Empty', 'categories' => []],
        ]);
        config()->set('public_api.privacy.version', '1.0.0');
        config()->set('public_api.maintenance.enabled', false);
        config()->set('public_api.feature_flags.quote_request', true);
        config()->set('public_api.quote_request.max_items', 20);
        config()->set('public_api.quote_request.max_quantity', 9999);
    }

    public static function replayChanges(): array
    {
        return [
            'hidden product' => [true, false, false],
            'changed privacy' => [false, true, false],
            'both changes' => [true, true, false],
            'legacy single product' => [true, true, true],
        ];
    }

    #[DataProvider('replayChanges')]
    public function test_replay_recovers_the_committed_request_after_catalogue_or_policy_changes(bool $hide, bool $changePrivacy, bool $legacy): void
    {
        $sku = $this->publishedSku('HANDOFF-REPLAY', 'Synthetic replay product');
        $payload = $this->payload([['product_id' => $sku->sku_id]]);
        if ($legacy) {
            unset($payload['items']);
            $payload['product_id'] = $sku->sku_id;
        }
        $headers = ['Idempotency-Key' => 'uat-handoff-replay-000001'];
        $first = $this->postJson(self::BASE.'/quote-requests', $payload, $headers)
            ->assertCreated()->assertJsonPath('data.status', 'RECEIVED');
        $before = DB::table('public_quote_request_items')->orderBy('id')->get()->toJson();

        if ($hide) {
            DB::table('sku')->where('sku_id', $sku->sku_id)->update(['published_to_app' => false]);
        }
        if ($changePrivacy) {
            config()->set('public_api.privacy.version', '2.0.0');
        }

        $replay = $this->postJson(self::BASE.'/quote-requests', $payload, $headers)->assertOk();
        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame(1, PublicQuoteRequest::query()->count());
        $this->assertSame($before, DB::table('public_quote_request_items')->orderBy('id')->get()->toJson());
    }

    public function test_replay_does_not_resolve_a_variant_that_has_since_been_hidden(): void
    {
        $product = $this->publishedSku('HANDOFF-VAR-A', 'Synthetic variant parent');
        $variant = $this->publishedSku('HANDOFF-VAR-B', 'Synthetic variant sibling');
        DB::table('sku')->whereIn('sku_id', [$product->sku_id, $variant->sku_id])
            ->update(['family_name' => 'UAT-HANDOFF-FAMILY']);
        $payload = $this->payload([['product_id' => $product->sku_id, 'variant_id' => $variant->sku_id]]);
        $headers = ['Idempotency-Key' => 'uat-handoff-variant-000001'];
        $first = $this->postJson(self::BASE.'/quote-requests', $payload, $headers)->assertCreated();
        DB::table('sku')->where('sku_id', $variant->sku_id)->update(['published_to_app' => false]);

        $this->postJson(self::BASE.'/quote-requests', $payload, $headers)
            ->assertOk()->assertJsonPath('data.request_id', $first->json('data.request_id'));
        $this->postJson(self::BASE.'/quote-requests', $payload, ['Idempotency-Key' => 'uat-handoff-variant-000002'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertSame(1, PublicQuoteRequest::query()->count());
    }

    public static function conflictingChanges(): array
    {
        return [
            'note' => ['note'],
            'quantity' => ['quantity'],
            'selection order' => ['order'],
            'privacy version' => ['privacy'],
            'different hidden selection' => ['selection'],
        ];
    }

    #[DataProvider('conflictingChanges')]
    public function test_changed_payload_is_a_conflict_even_when_current_business_checks_would_fail(string $change): void
    {
        $a = $this->publishedSku('HANDOFF-CONFLICT-A', 'Synthetic conflict A');
        $b = $this->publishedSku('HANDOFF-CONFLICT-B', 'Synthetic conflict B');
        $payload = $this->payload([['product_id' => $a->sku_id], ['product_id' => $b->sku_id]]);
        $headers = ['Idempotency-Key' => 'uat-handoff-conflict-000001'];
        $this->postJson(self::BASE.'/quote-requests', $payload, $headers)->assertCreated();
        DB::table('sku')->whereIn('sku_id', [$a->sku_id, $b->sku_id])->update(['published_to_app' => false]);
        config()->set('public_api.privacy.version', '2.0.0');

        switch ($change) {
            case 'note':
                $payload['note'] = 'Synthetic changed note';
                break;
            case 'quantity':
                $payload['items'][0]['quantity'] = 2;
                break;
            case 'order':
                $payload['items'] = array_reverse($payload['items']);
                break;
            case 'privacy':
                $payload['privacy_version'] = '2.0.0';
                break;
            case 'selection':
                $payload['items'] = [['product_id' => (string) Str::uuid()]];
                break;
        }

        $this->postJson(self::BASE.'/quote-requests', $payload, $headers)
            ->assertConflict()->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, PublicQuoteRequest::query()->count());
        $this->assertSame(2, PublicQuoteRequestItem::query()->count());
    }

    public function test_new_keys_still_require_current_policy_and_public_products(): void
    {
        $sku = $this->publishedSku('HANDOFF-NEW', 'Synthetic new request');
        $payload = $this->payload([['product_id' => $sku->sku_id]]);
        $this->postJson(self::BASE.'/quote-requests', $payload, ['Idempotency-Key' => 'uat-handoff-new-00000001'])->assertCreated();
        config()->set('public_api.privacy.version', '2.0.0');
        $this->postJson(self::BASE.'/quote-requests', $payload, ['Idempotency-Key' => 'uat-handoff-new-00000002'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'PRIVACY_VERSION_MISMATCH');
        $payload['privacy_version'] = '2.0.0';
        DB::table('sku')->where('sku_id', $sku->sku_id)->update(['published_to_app' => false]);
        $this->postJson(self::BASE.'/quote-requests', $payload, ['Idempotency-Key' => 'uat-handoff-new-00000003'])
            ->assertNotFound()->assertJsonPath('error_code', 'PRODUCT_NOT_FOUND');
        $this->assertSame(1, PublicQuoteRequest::query()->count());
    }

    public function test_replay_keeps_consent_and_maintenance_guards(): void
    {
        $sku = $this->publishedSku('HANDOFF-GUARD', 'Synthetic guard');
        $payload = $this->payload([['product_id' => $sku->sku_id]]);
        $headers = ['Idempotency-Key' => 'uat-handoff-guard-0000001'];
        $this->postJson(self::BASE.'/quote-requests', $payload, $headers)->assertCreated();
        $this->postJson(self::BASE.'/quote-requests', ['consent' => false] + $payload, $headers)
            ->assertUnprocessable()->assertJsonPath('error_code', 'CONSENT_REQUIRED');
        config()->set('public_api.maintenance.enabled', true);
        $this->postJson(self::BASE.'/quote-requests', $payload, $headers)
            ->assertStatus(503)->assertJsonPath('error_code', 'SERVICE_UNAVAILABLE');
        $this->assertSame(1, PublicQuoteRequest::query()->count());
    }

    public function test_config_exposes_the_same_limits_that_new_quotes_enforce(): void
    {
        config()->set('public_api.quote_request.max_items', 2);
        config()->set('public_api.quote_request.max_quantity', 5);
        $this->getJson(self::BASE.'/config')->assertOk()
            ->assertJsonPath('data.quote_request.max_items', 2)
            ->assertJsonPath('data.quote_request.max_quantity', 5);
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = ['product_id' => $this->publishedSku('HANDOFF-LIMIT-'.$i, 'Synthetic limit '.$i)->sku_id];
        }
        $this->postJson(self::BASE.'/quote-requests', $this->payload($items), ['Idempotency-Key' => 'uat-handoff-limit-000001'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $items = array_slice($items, 0, 1);
        $items[0]['quantity'] = 6;
        $this->postJson(self::BASE.'/quote-requests', $this->payload($items), ['Idempotency-Key' => 'uat-handoff-limit-000002'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $items[0]['quantity'] = 5;
        $this->postJson(self::BASE.'/quote-requests', $this->payload($items), ['Idempotency-Key' => 'uat-handoff-limit-000003'])->assertCreated();
        $this->assertSame(1, PublicQuoteRequest::query()->count());
    }

    public function test_twenty_items_succeed_and_twenty_one_items_write_nothing_extra(): void
    {
        $items = [];
        for ($i = 0; $i < 21; $i++) {
            $items[] = ['product_id' => $this->publishedSku('HANDOFF-20-'.$i, 'Synthetic boundary '.$i)->sku_id];
        }
        $this->getJson(self::BASE.'/config')->assertOk()->assertJsonPath('data.quote_request.max_items', 20);
        $this->postJson(self::BASE.'/quote-requests', $this->payload(array_slice($items, 0, 20)), ['Idempotency-Key' => 'uat-handoff-twenty-00001'])
            ->assertCreated()->assertJsonPath('data.status', 'RECEIVED');
        $this->postJson(self::BASE.'/quote-requests', $this->payload($items), ['Idempotency-Key' => 'uat-handoff-twentyone-01'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertSame(1, PublicQuoteRequest::query()->count());
        $this->assertSame(20, PublicQuoteRequestItem::query()->count());
    }

    public static function sorts(): array
    {
        return [['featured'], ['updated_desc'], ['name_asc']];
    }

    #[DataProvider('sorts')]
    public function test_total_uses_all_filters_before_cursor_and_is_not_page_size(string $sort): void
    {
        $power = $this->category('UAT_POWER');
        $hand = $this->category('UAT_HAND');
        for ($i = 0; $i < 4; $i++) {
            $sku = $this->publishedSku('HANDOFF-PUMP-'.$i, 'Synthetic pump '.$i, $power);
            DB::table('sku')->where('sku_id', $sku->sku_id)->update(['specification' => json_encode(['power' => $i < 3 ? '750W' : '250W'])]);
        }
        $this->publishedSku('HANDOFF-OTHER', 'Synthetic pump other category', $hand);
        $hidden = $this->publishedSku('HANDOFF-HIDDEN', 'Synthetic pump hidden', $power);
        DB::table('sku')->where('sku_id', $hidden->sku_id)->update(['published_to_app' => false]);
        $base = self::BASE.'/products?domain=POWER_TOOLS&category=UAT_POWER&q=pump&spec.power=750W&limit=2&sort='.$sort;
        $first = $this->getJson($base)->assertOk()
            ->assertJsonPath('data.page_info.total', 3)
            ->assertJsonPath('data.page_info.has_more', true)
            ->assertJsonCount(2, 'data.items');
        $cursor = $first->json('data.page_info.next_cursor');
        $this->assertIsString($cursor);
        $second = $this->getJson($base.'&cursor='.rawurlencode($cursor))->assertOk()
            ->assertJsonPath('data.page_info.total', 3)
            ->assertJsonPath('data.page_info.has_more', false)
            ->assertJsonPath('data.page_info.next_cursor', null)
            ->assertJsonCount(1, 'data.items');
        $ids = array_merge(array_column($first->json('data.items'), 'id'), array_column($second->json('data.items'), 'id'));
        $this->assertCount(3, array_unique($ids));
        $this->assertSame(['PREORDER', 'PREORDER'], array_column($first->json('data.items'), 'availability'));
        $this->assertArrayNotHasKey('total', $first->json('meta'));
        $this->assertArrayNotHasKey('next_cursor', $first->json('meta'));
        $this->getJson(str_replace('q=pump', 'q=no-such-synthetic-product', $base))
            ->assertOk()->assertJsonPath('data.page_info.total', 0)
            ->assertJsonPath('data.page_info.next_cursor', null)->assertJsonCount(0, 'data.items');
        $this->getJson(str_replace('q=pump', 'q=changed', $base).'&cursor='.rawurlencode($cursor))
            ->assertBadRequest()->assertJsonPath('error_code', 'INVALID_CURSOR');
    }

    public function test_unscoped_total_counts_uncategorized_skus_and_ignores_unpublished_rows(): void
    {
        $this->publishedSku('HANDOFF-NOCAT', 'Synthetic without category');
        $hidden = $this->publishedSku('HANDOFF-NOCAT-HIDDEN', 'Synthetic hidden without category');
        DB::table('sku')->where('sku_id', $hidden->sku_id)->update(['published_to_app' => false]);
        $this->getJson(self::BASE.'/products')->assertOk()
            ->assertJsonPath('data.page_info.total', 1)->assertJsonCount(1, 'data.items');
        $this->getJson(self::BASE.'/products?domain=ACCESSORIES')->assertOk()
            ->assertJsonPath('data.page_info.total', 0)->assertJsonCount(0, 'data.items');
    }

    public function test_related_total_excludes_self_and_is_stable_across_its_pages(): void
    {
        $category = $this->category('UAT_POWER');
        for ($i = 0; $i < 4; $i++) {
            $this->publishedSku('HANDOFF-RELATED-'.$i, 'Synthetic related '.$i, $category);
        }
        $base = self::BASE.'/products/handoff-related-0/related?limit=2';
        $first = $this->getJson($base)->assertOk()->assertJsonPath('data.page_info.total', 3)->assertJsonCount(2, 'data.items');
        $this->getJson($base.'&cursor='.rawurlencode($first->json('data.page_info.next_cursor')))->assertOk()
            ->assertJsonPath('data.page_info.total', 3)->assertJsonPath('data.page_info.has_more', false)->assertJsonCount(1, 'data.items');
    }

    public function test_saved_ids_keep_their_non_paginated_contract_and_home_skips_extra_counts(): void
    {
        $a = $this->publishedSku('HANDOFF-SAVED-A', 'Synthetic saved A');
        $b = $this->publishedSku('HANDOFF-SAVED-B', 'Synthetic saved B');
        $missing = (string) Str::uuid();
        $body = $this->getJson(self::BASE.'/products?ids[]='.$b->sku_id.'&ids[]='.$a->sku_id.'&ids[]='.$missing)->assertOk()->json('data');
        $this->assertSame([$b->sku_id, $a->sku_id], array_column($body['items'], 'id'));
        $this->assertSame([$missing], $body['missing_ids']);
        $this->assertArrayNotHasKey('page_info', $body);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(PublicCatalogReadModel::class)->listProducts([], 'featured', null, 2, includeTotal: false);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertArrayNotHasKey('total', $result['page_info']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/count\s*\(\s*distinct/i', $query['query']);
        }
    }

    private function publishedSku(string $code, string $name, ?string $categoryId = null): Sku
    {
        $sku = Sku::create([
            'sku_code' => $code, 'sku_name' => $name, 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
            'category_id' => $categoryId,
        ]);
        $headers = $this->superAdminHeaders();
        unset($headers['__user_id']);
        $this->patchJson('/api/v1/pv/products/'.$sku->sku_id.'/publish', ['published' => true], $headers + ['If-Match' => '0'])->assertOk();

        return $sku;
    }

    private function category(string $code): string
    {
        $id = (string) Str::uuid();
        DB::table('product_categories')->insert([
            'id' => $id, 'category_code' => $code, 'category_name' => $code,
            'status' => 'ACTIVE', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (DB::getDriverName() !== 'pgsql') {
            DB::table('product_masters')->insert([
                'master_id' => $id, 'master_type' => 'CATEGORY', 'code' => $code,
                'name' => $code, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function payload(array $items): array
    {
        return [
            'items' => $items,
            'full_name' => 'SYNTHETIC UAT ONLY',
            'phone' => str_repeat('0', 10),
            'note' => 'Isolated automated test; no real contact.',
            'consent' => true,
            'privacy_version' => '1.0.0',
        ];
    }
}
