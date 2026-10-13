<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Warehouse\Http\Middleware\AttachmentQuotaFence;
use Modules\Warehouse\Services\AttachmentUploadReceipts;
use Tests\Feature\Concerns\BuildsP18QuotaFixtures;
use Tests\TestCase;

class P18MixedQuotaFenceTest extends TestCase
{
    use BuildsP18QuotaFixtures;
    use RefreshDatabase;

    public function test_exact_owner_upload_inventory_is_fenced_inside_receipt_handling(): void
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $expected = in_array('POST', $route->methods(), true)
                && isset(AttachmentQuotaFence::UPLOAD_ROUTES[$route->uri()]);
            $this->assertSame($expected, in_array(AttachmentQuotaFence::class, $middleware, true), $route->uri());
            if ($expected) {
                $found[] = $route->uri();
                if (isset(AttachmentUploadReceipts::UPLOAD_ROUTES[$route->uri()])) {
                    $this->assertLessThan(array_search(AttachmentQuotaFence::class, $middleware, true),
                        array_search(AttachmentUploadReceipts::class, $middleware, true));
                }
            }
        }
        sort($found);
        $expected = array_keys(AttachmentQuotaFence::UPLOAD_ROUTES);
        sort($expected);
        $this->assertSame($expected, $found);
    }

    public function test_web_legacy_and_receipt_modes_keep_each_owner_quota_and_exact_replay(): void
    {
        [$token, $warehouse] = $this->quotaActor();
        foreach (['INBOUND', 'OUTBOUND', 'WARRANTY'] as $owner) {
            foreach ([false, true] as $receiptFirst) {
                $id = $this->quotaParent($owner, $token, $warehouse);
                $limit = $this->primeQuota($owner, $id, $token);
                $file = UploadedFile::fake()->image('last-slot.png');
                $key = (string) Str::uuid();
                $baseLevel = DB::transactionLevel();
                $observing = true;
                $levels = [];
                DB::listen(function (QueryExecuted $event) use (&$observing, &$levels): void {
                    $sql = strtolower($event->sql);
                    if ($observing && str_contains($sql, 'count(')
                        && (str_contains($sql, 'document_attachments') || str_contains($sql, 'warranty_attachments'))) {
                        $levels[] = $event->connection->transactionLevel();
                    }
                });
                $first = $this->post($this->quotaPath($owner, $id, ! $receiptFirst), ['file' => $file],
                    $receiptFirst ? $this->quotaUploadHeaders($token, $warehouse, $file, $key) : $this->quotaHeaders($token))
                    ->assertCreated();
                $loserKey = (string) Str::uuid();
                $this->post($this->quotaPath($owner, $id, $receiptFirst), ['file' => $file],
                    $receiptFirst ? $this->quotaHeaders($token) : $this->quotaUploadHeaders($token, $warehouse, $file, $loserKey))
                    ->assertStatus(422)->assertJsonPath('error.code', 'ATTACHMENT_LIMIT_EXCEEDED');
                $observing = false;
                $this->assertNotEmpty($levels);
                foreach ($levels as $level) {
                    $this->assertGreaterThan($baseLevel, $level, 'Quota must be read inside the added parent transaction');
                }
                $this->assertSame($limit, $this->quotaRows($owner, $id)->where('status', 'ACTIVE')->count());
                if ($receiptFirst) {
                    $this->post($this->quotaPath($owner, $id), ['file' => $file],
                        $this->quotaUploadHeaders($token, $warehouse, $file, $key))
                        ->assertCreated()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.id', $first->json('data.id'));
                } else {
                    $this->assertDatabaseHas(AttachmentUploadReceipts::TABLE,
                        ['operation_key' => $loserKey, 'state' => 'REJECTED']);
                }
                $this->assertSame($limit, $this->quotaRows($owner, $id)->where('status', 'ACTIVE')->count());
            }
        }
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_legacy_quarantine_survives_known_rejection_without_active_quota_or_receipt(): void
    {
        [$token, $warehouse] = $this->quotaActor();
        foreach (['INBOUND', 'WARRANTY'] as $owner) {
            $id = $this->quotaParent($owner, $token, $warehouse);
            config(['documents.attachment_scan_command' => 'false', 'warranty.attachment_scan_command' => 'false']);
            $this->post($this->quotaPath($owner, $id, true), ['file' => UploadedFile::fake()->image('quarantine.png')],
                $this->quotaHeaders($token))->assertStatus(422)->assertJsonPath('error.code', 'ATTACHMENT_MALWARE_DETECTED');
            $this->assertSame(1, $this->quotaRows($owner, $id)->where('status', 'QUARANTINED')->count());
            $this->assertSame(0, $this->quotaRows($owner, $id)->where('status', 'ACTIVE')->count());
        }
        $this->assertDatabaseCount(AttachmentUploadReceipts::TABLE, 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
