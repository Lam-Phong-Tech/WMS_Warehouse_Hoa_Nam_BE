<?php

namespace App\Console\Commands;

use App\Support\Cache\CacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Throwable;

/** Explicit, audited legacy mapping. Dry-run by default; never guesses ownership. */
final class AssignWarrantyWarehouseOwners extends Command
{
    protected $signature = 'warranty:assign-warehouse {mapping : JSON array of warranty_case_id/warehouse_id pairs} {--actor= : Existing authorized operator UUID} {--reason= : Evidence for the reviewed mapping} {--apply : Persist the validated mapping atomically}';

    protected $description = 'Đối chiếu và gán kho cho hồ sơ bảo hành lịch sử chưa có chủ sở hữu.';

    public function handle(): int
    {
        try {
            $path = (string) $this->argument('mapping');
            if (! is_file($path) || filesize($path) > 2 * 1024 * 1024) {
                throw new \RuntimeException('Mapping must be a local JSON file no larger than 2 MiB.');
            }
            $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $input = Validator::make(['rows' => $rows, 'actor' => $this->option('actor'), 'reason' => $this->option('reason')], [
                'rows' => ['required', 'array', 'min:1', 'max:5000'],
                'rows.*.warranty_case_id' => ['required', 'uuid', 'distinct'],
                'rows.*.warehouse_id' => ['required', 'uuid'],
                'actor' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:10', 'max:2000'],
            ])->validate();
            $apply = (bool) $this->option('apply');
            $count = DB::transaction(function () use ($input, $apply): int {
                $actor = User::query()->whereKey($input['actor'])->lockForUpdate()->first();
                if ($actor === null || $actor->status !== 'ACTIVE' || ! $actor->hasPermission('warranty.manage')) {
                    throw new \RuntimeException('Actor must be an active user with warranty.manage.');
                }
                $count = 0;
                foreach ($input['rows'] as $row) {
                    $caseId = strtolower($row['warranty_case_id']);
                    $warehouseId = strtolower($row['warehouse_id']);
                    if (! $actor->canAccessWarehouse($warehouseId) || ! Warehouse::query()->whereKey($warehouseId)->exists()) {
                        throw new \RuntimeException('Mapping contains an unavailable or unauthorized warehouse.');
                    }
                    if (! DB::table('warranty_case')->where('warranty_case_id', $caseId)->lockForUpdate()->exists()) {
                        throw new \RuntimeException('Mapping contains a nonexistent warranty case.');
                    }
                    $existing = DB::table(WarrantyWarehouseScope::TABLE)->where('warranty_case_id', $caseId)->first();
                    if ($existing !== null) {
                        if ((string) $existing->warehouse_id !== $warehouseId) {
                            throw new \RuntimeException('Existing ownership is immutable; this command cannot transfer a case.');
                        }

                        continue;
                    }
                    $count++;
                    if (! $apply) {
                        continue;
                    }
                    DB::table(WarrantyWarehouseScope::TABLE)->insert([
                        'warranty_case_id' => $caseId, 'warehouse_id' => $warehouseId,
                        'source' => 'VERIFIED_BACKFILL', 'auth_session_id' => null,
                        'actor_id' => (string) $actor->user_id, 'assigned_at' => now()->utc(), 'evidence' => $input['reason'],
                    ]);
                    AuditLogger::record(action: 'WARRANTY_WAREHOUSE_ASSIGNED', objectType: 'warranty_case', objectId: $caseId,
                        actorId: (string) $actor->user_id, reason: $input['reason'],
                        beforeData: ['warehouse_id' => null], afterData: ['warehouse_id' => $warehouseId, 'source' => 'VERIFIED_BACKFILL']);
                }

                return $count;
            });
            if ($apply && $count > 0) {
                app(CacheService::class)->bumpVersion('stock:version');
            }
            // Report caches also include the ownership table revision.
            $this->info(($apply ? 'Assigned: ' : 'Dry-run; eligible: ').$count);

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
