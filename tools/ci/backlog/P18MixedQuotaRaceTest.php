<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Warehouse\Services\AttachmentUploadReceipts;
use Tests\Feature\Concerns\BuildsP18QuotaFixtures;
use Tests\Feature\Concerns\RunsIsolatedPostgresRace;
use Tests\TestCase;

/** Invoked only by the guarded PostgreSQL CI stage, never on a live server. */
class P18MixedQuotaRaceTest extends TestCase
{
    use BuildsP18QuotaFixtures;
    use RunsIsolatedPostgresRace;

    public function test_mixed_legacy_and_receipt_writers_share_the_last_slot_in_both_orders(): void
    {
        [$token, $warehouse] = $this->quotaActor();
        foreach (['INBOUND', 'OUTBOUND', 'WARRANTY'] as $owner) {
            foreach ([false, true] as $receiptFirst) {
                $id = $this->quotaParent($owner, $token, $warehouse);
                $limit = $this->primeQuota($owner, $id, $token);
                $key = (string) Str::uuid();
                $upload = function (bool $receipt) use ($owner, $id, $token, $warehouse, $key): array {
                    $file = UploadedFile::fake()->image('race.png');
                    $headers = $receipt ? $this->quotaUploadHeaders($token, $warehouse, $file, $key) : $this->quotaHeaders($token);
                    $response = $this->post($this->quotaPath($owner, $id, ! $receipt), ['file' => $file], $headers);

                    return ['status' => $response->status(), 'error' => $response->json('error.code'),
                        'outcome' => $response->json('meta.upload_receipt.outcome')];
                };
                [$winner, $loser] = $this->runDatabaseRace($owner === 'WARRANTY' ? 'warranty_case' : 'stock_documents',
                    fn (): array => $upload($receiptFirst), fn (): array => $upload(! $receiptFirst));
                $this->assertSame(201, $winner['status']);
                $this->assertSame(422, $loser['status']);
                $this->assertSame('ATTACHMENT_LIMIT_EXCEEDED', $loser['error']);
                $this->assertSame($limit, $this->quotaRows($owner, $id)->where('status', 'ACTIVE')->count());
                $this->assertDatabaseHas(AttachmentUploadReceipts::TABLE, [
                    'operation_key' => $key, 'state' => $receiptFirst ? 'SUCCEEDED' : 'REJECTED',
                ]);
                $this->assertDatabaseCount('stock_movements', 0);
            }
        }
    }
}
