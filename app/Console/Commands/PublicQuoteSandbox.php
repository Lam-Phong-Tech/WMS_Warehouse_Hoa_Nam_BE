<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Services\PublicCatalogReadModel;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/** Explicit, CLI-only fixtures for a dedicated disposable database. */
class PublicQuoteSandbox extends Command
{
    public const SCENARIO = 'public-quote-21-v1';

    public const DATABASE = 'hn_public_quote_test';

    public const KEY_PREFIX = 'hn-uat-quote-';

    protected $signature = 'wms:public-quote-sandbox
        {action=manifest : prepare, manifest, or reset-quotes}
        {--confirm= : Required for reset-quotes: RESET_SANDBOX_QUOTES}';

    protected $description = 'Prepare 21 isolated public SKU fixtures and output a non-PII Quote test manifest';

    public function handle(): int
    {
        try {
            $this->assertIsolated();
            $action = (string) $this->argument('action');
            if (! in_array($action, ['prepare', 'manifest', 'reset-quotes'], true)) {
                throw new RuntimeException('Action must be prepare, manifest, or reset-quotes.');
            }

            $ids = self::productIds();
            $deleted = 0;
            DB::transaction(function () use ($action, $ids, &$deleted): void {
                // Even a mislabelled database must not be filled with fixtures.
                if (Sku::query()->whereNotIn('sku_id', $ids)->exists()) {
                    throw new RuntimeException('Refusing: this database contains products outside this sandbox scenario.');
                }
                if ($action === 'prepare') {
                    foreach ($ids as $index => $id) {
                        $code = sprintf('UAT-QUOTE-%03d', $index + 1);
                        $sku = Sku::query()->find($id);
                        if ($sku !== null && $sku->sku_code !== $code) {
                            throw new RuntimeException('Sandbox ID collision; no existing product was overwritten.');
                        }
                        $sku ??= new Sku;
                        $sku->forceFill([
                            'sku_id' => $id, 'sku_code' => $code,
                            'sku_name' => 'SYNTHETIC QUOTE TEST '.$code,
                            'sku_type' => 'PRODUCT', 'unit' => 'cai',
                            'is_serial_tracked' => false, 'warranty_applicable' => false,
                            'status' => 'ACTIVE', 'published_to_app' => true,
                        ])->save();
                    }
                }
                if ($action === 'reset-quotes') {
                    if ($this->option('confirm') !== 'RESET_SANDBOX_QUOTES') {
                        throw new RuntimeException('Reset requires --confirm=RESET_SANDBOX_QUOTES.');
                    }
                    $requests = DB::table('public_quote_requests')
                        ->where('idempotency_key', 'like', self::KEY_PREFIX.'%')
                        ->whereIn('product_id', $ids)
                        ->whereNotExists(function ($query) use ($ids): void {
                            $query->selectRaw('1')->from('public_quote_request_items as qi')
                                ->whereColumn('qi.request_id', 'public_quote_requests.id')
                                ->whereNotIn('qi.product_id', $ids);
                        })->pluck('id')->all();
                    foreach (array_chunk($requests, 100) as $chunk) {
                        DB::table('public_quote_request_items')->whereIn('request_id', $chunk)->delete();
                        $deleted += DB::table('public_quote_requests')->whereIn('id', $chunk)->delete();
                    }
                }
                // Exercise the actual public read model, not a synthetic API response.
                if (count(app(PublicCatalogReadModel::class)->visibleProductSnapshots($ids)) !== 21) {
                    throw new RuntimeException('The 21 sandbox products are not all public. Run prepare first.');
                }
            });

            $this->line((string) json_encode([
                'scenario' => self::SCENARIO,
                'synthetic' => true,
                'declared_build_sha' => config('public_sandbox.build_sha'),
                'deployment_verified' => false,
                'base_url' => rtrim((string) config('app.url'), '/').'/api/v1',
                'product_ids' => $ids,
                'idempotency_key_prefix' => self::KEY_PREFIX,
                'quote_template' => [
                    'full_name' => 'SYNTHETIC UAT ONLY',
                    'phone' => str_repeat('0', 10),
                    'note' => 'Sandbox test only. Do not contact.',
                    'consent' => true,
                    'privacy_version' => (string) config('public_api.privacy.version'),
                    'items' => [['product_id' => $ids[0]]],
                ],
                'reset_deleted_requests' => $deleted,
                'warning' => 'Only use against the verified isolated sandbox, never a live/dev warehouse database.',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<string> */
    public static function productIds(): array
    {
        return array_map(
            static fn (int $index): string => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'urn:hoa-nam:'.self::SCENARIO.':'.$index),
            range(1, 21),
        );
    }

    private function assertIsolated(): void
    {
        // All checks precede writes. Neither a force switch nor a public HTTP
        // endpoint can bypass this gate; UAT/production names are NOT accepted.
        if (! app()->environment(['local', 'testing']) || ! config('public_sandbox.enabled')) {
            throw new RuntimeException('Sandbox requires local/testing and PUBLIC_QUOTE_SANDBOX_ENABLED=true.');
        }
        if (config('mail.default') !== 'array' || config('queue.default') !== 'sync'
            || config('broadcasting.default') !== 'null') {
            throw new RuntimeException('Sandbox requires MAIL_MAILER=array, QUEUE_CONNECTION=sync, BROADCAST_CONNECTION=null.');
        }
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();
        if ($driver === 'pgsql' && $database === self::DATABASE) {
            // Resolve the database on the actual connection as well as config,
            // so a URL override cannot silently redirect fixture writes.
            if ($connection->selectOne('select current_database() as name')->name === self::DATABASE) {
                return;
            }
        }
        if ($driver === 'sqlite') {
            if ($database === ':memory:' && app()->runningUnitTests()) {
                return;
            }
            if (basename($database) === self::DATABASE.'.sqlite') {
                return;
            }
        }
        throw new RuntimeException('Refusing database: use hn_public_quote_test (PostgreSQL) or hn_public_quote_test.sqlite.');
    }
}
