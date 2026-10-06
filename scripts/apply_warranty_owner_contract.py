#!/usr/bin/env python3
"""Apply the warranty-owner-v1 rollout in an isolated checkout; never touches a live database."""
from pathlib import Path
import json
import re

ROOT = Path('.')
SUPPORT = 'Modules/Warehouse/app/Support/WarrantyWarehouseScope.php'
if Path(SUPPORT).exists():
    print('Warranty owner implementation is already present; no patch applied.')
    raise SystemExit(0)
changed = []

def write(path, text):
    p = ROOT / path
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(text.rstrip() + '\n', encoding='utf-8')
    changed.append(path)

def edit(path, old, new, count=1):
    p = ROOT / path
    text = p.read_text(encoding='utf-8')
    actual = text.count(old)
    if actual != count:
        raise RuntimeError(f'{path}: expected {count} exact anchor(s), got {actual}: {old[:100]!r}')
    write(path, text.replace(old, new))

write(SUPPORT, r'''<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Models\Warehouse;

/** Persisted receipt ownership; never inferred from the item's location or the first allowed warehouse. */
final class WarrantyWarehouseScope
{
    public const TABLE = 'warranty_case_warehouse_owners';
    public const VERSION = 'warranty-owner-v1';
    public const OPEN_STATUSES = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED'];

    public static function actor(Request $request): User
    {
        $user = $request->user() ?? auth('api')->user();
        if (! $user instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập để truy cập hồ sơ bảo hành.');
        }
        return $user;
    }

    /** Read APIs keep their existing access-token transport and do not require a work shift. */
    public static function readSelection(Request $request): array
    {
        $data = Validator::make($request->query(), ['warehouse_id' => ['sometimes', 'required', 'uuid']])->validate();
        $id = isset($data['warehouse_id']) ? strtolower($data['warehouse_id']) : null;
        $actor = self::actor($request);
        $scope = $actor->warehouseScopeIds();
        if ($id !== null) {
            if (! $actor->canAccessWarehouse($id)) {
                ApiError::abort(403, 'WARRANTY_WAREHOUSE_ACCESS_DENIED', 'Kho bảo hành nằm ngoài phạm vi được cấp.');
            }
            if (! Warehouse::query()->whereKey($id)->exists()) {
                ApiError::abort(404, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy kho.');
            }
        }
        return ['warehouse_id' => $id, 'scope_ids' => $scope];
    }

    public static function query(Request $request, string $alias = 'warranty_case'): Builder
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid internal warranty query alias.');
        }
        $query = DB::table($alias === 'warranty_case' ? 'warranty_case' : 'warranty_case as '.$alias);
        return self::apply($query, $request, $alias.'.warranty_case_id');
    }

    /** EXISTS preserves one row per case and does not discard TEMP_ONLY rows with null item/product IDs. */
    public static function apply(Builder $query, Request $request, string $caseColumn): Builder
    {
        $selection = self::readSelection($request);
        $id = $selection['warehouse_id'];
        $scope = $selection['scope_ids'];
        if ($id === null && $scope === null) {
            return $query; // Authorized all-warehouse view includes explicitly-labelled legacy unassigned cases.
        }
        return $query->whereExists(function (Builder $owners) use ($caseColumn, $id, $scope): void {
            $owners->selectRaw('1')->from(self::TABLE.' as warranty_owner')
                ->whereColumn('warranty_owner.warranty_case_id', $caseColumn);
            if ($id !== null) {
                $owners->where('warranty_owner.warehouse_id', $id);
            }
            if ($scope !== null) {
                $owners->whereIn('warranty_owner.warehouse_id', $scope);
            }
        });
    }

    public static function metadata(Request $request): array
    {
        $selection = self::readSelection($request);
        // Coverage flag only, never the IDs/count/PII of unassigned cases outside the caller's scope.
        $unassigned = DB::table('warranty_case as legacy')->whereNotExists(function (Builder $q): void {
            $q->selectRaw('1')->from(self::TABLE.' as owner')
                ->whereColumn('owner.warranty_case_id', 'legacy.warranty_case_id');
        })->exists();
        return [
            'contract' => self::VERSION,
            'warehouse_id' => $selection['warehouse_id'],
            'unassigned_policy' => $selection['warehouse_id'] === null && $selection['scope_ids'] === null ? 'INCLUDED' : 'EXCLUDED',
            'ownership_complete' => ! $unassigned,
        ];
    }

    public static function prepareCreate(Request $request): void
    {
        $request->attributes->set('warranty.create_owner', self::resolveCreate($request));
    }

    public static function creationHash(Request $request): string
    {
        $owner = $request->attributes->get('warranty.create_owner');
        return hash('sha256', \App\Support\IdempotencyStore::requestHash($request).'|'.self::VERSION.'|'.$owner['warehouse_id']);
    }

    /** Lock in the same user -> warehouse order as switch-context/start; verify again before creating a case. */
    public static function lockCreate(Request $request): void
    {
        $expected = $request->attributes->get('warranty.create_owner');
        $locked = self::resolveCreate($request, true);
        if (! is_array($expected) || $expected['warehouse_id'] !== $locked['warehouse_id']
            || $expected['auth_session_id'] !== $locked['auth_session_id']) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Ngữ cảnh kho đã thay đổi; tải lại phiên trước khi tiếp nhận.');
        }
        $request->attributes->set('warranty.locked_owner', $locked);
    }

    public static function bindNew(Request $request, string $caseId): void
    {
        $owner = $request->attributes->get('warranty.locked_owner');
        if (! is_array($owner) || DB::transactionLevel() < 1) {
            throw new \LogicException('Warranty ownership must be captured in the case creation transaction.');
        }
        DB::table(self::TABLE)->insert([
            'warranty_case_id' => $caseId,
            ...$owner,
            'assigned_at' => now()->utc(),
            'evidence' => null,
        ]);
    }

    private static function resolveCreate(Request $request, bool $lock = false): array
    {
        $user = self::actor($request);
        if ($lock) {
            $user = User::query()->whereKey($user->user_id)->lockForUpdate()->first();
        }
        if ($user === null || $user->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn khả dụng.');
        }
        self::validateOwnerInput($request);
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $explicit = isset($body['warehouse_id']) ? strtolower($body['warehouse_id']) : null;
        $sessions = app(StableAuthSession::class);
        $sessionId = $sessions->id($user);
        if ($sessionId === null && app(RefreshTokenService::class)->currentSessionId() !== null) {
            $sessions->requireId($user); // A revoked refresh family must not fall back to an explicit body.
        }
        $context = app(WarehouseContext::class)->payload($user, $sessionId, 'AUTH_SESSION_REQUIRED');
        $state = $context['warehouse_context']['status'];
        if ($state === 'UNAVAILABLE') {
            $code = $context['warehouse_context']['error_code'];
            ApiError::abort($code === 'WAREHOUSE_ACCESS_DENIED' ? 403 : 409, $code, 'Ngữ cảnh kho không còn hợp lệ.');
        }
        $selected = $context['active_warehouse_id'];
        if ($selected !== null && $explicit !== null && $selected !== $explicit) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'warehouse_id không khớp kho đã xác nhận trong phiên.');
        }
        $id = $selected ?? $explicit;
        if ($id === null) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_REQUIRED', 'Xác nhận kho làm việc hoặc gửi warehouse_id đã được xác nhận trước khi tiếp nhận bảo hành.');
        }
        if (! $user->canAccessWarehouse($id)) {
            ApiError::abort(403, 'WARRANTY_WAREHOUSE_ACCESS_DENIED', 'Kho bảo hành nằm ngoài phạm vi được cấp.');
        }
        $warehouseQuery = Warehouse::query()->whereKey($id);
        if ($lock) {
            $warehouseQuery->lockForUpdate();
        }
        $warehouse = $warehouseQuery->first();
        if ($warehouse === null) {
            ApiError::abort(404, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy kho.');
        }
        if ($warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho đang tạm dừng hoạt động.');
        }
        return [
            'warehouse_id' => (string) $warehouse->id,
            'source' => $selected !== null ? 'CURRENT_CONTEXT' : 'EXPLICIT_REQUEST',
            'auth_session_id' => $sessionId,
            'actor_id' => (string) $user->user_id,
        ];
    }

    public static function validateOwnerInput(Request $request): void
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $forbidden = array_values(array_intersect(array_keys($body), [
            'warehouse_code', 'warehouse_name', 'warehouse_status', 'warehouse_ownership', 'owner_warehouse_id', 'warehouse_context',
        ]));
        if ($forbidden !== []) {
            ApiError::abort(422, 'WARRANTY_OWNER_READ_ONLY', 'Thông tin sở hữu kho do máy chủ xác định.', ['fields' => $forbidden]);
        }
        Validator::make($body, ['warehouse_id' => ['sometimes', 'required', 'uuid']])->validate();
    }

    /** Guard BEFORE cached/idempotency responses, including events and attachment downloads. */
    public static function assertCase(Request $request, string $id, bool $write = false): void
    {
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hồ sơ bảo hành.');
        }
        $actor = self::actor($request);
        $selection = self::readSelection($request);
        $owner = DB::table(self::TABLE)->where('warranty_case_id', $id)->first();
        if ($owner !== null && ! $actor->canAccessWarehouse((string) $owner->warehouse_id)) {
            ApiError::abort(403, 'WARRANTY_WAREHOUSE_ACCESS_DENIED', 'Không có quyền truy cập kho sở hữu hồ sơ bảo hành.');
        }
        if ($owner === null && $selection['scope_ids'] !== null) {
            ApiError::abort(403, 'WARRANTY_WAREHOUSE_ACCESS_DENIED', 'Không có quyền truy cập hồ sơ trong phạm vi kho này.');
        }
        if (! DB::table('warranty_case')->where('warranty_case_id', $id)->exists()) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hồ sơ bảo hành.');
        }
        if ($selection['warehouse_id'] !== null && ($owner === null || $selection['warehouse_id'] !== (string) $owner->warehouse_id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hồ sơ trong kho đã lọc.');
        }
        if (! $write) {
            return;
        }
        self::validateOwnerInput($request);
        if ($owner === null) {
            ApiError::abort(409, 'WARRANTY_WAREHOUSE_UNASSIGNED', 'Hồ sơ cũ chưa xác nhận kho sở hữu; cần quản trị đối chiếu trước khi thao tác.');
        }
        $bodyId = $request->input('warehouse_id');
        if ($bodyId !== null && strtolower($bodyId) !== (string) $owner->warehouse_id) {
            ApiError::abort(409, 'WARRANTY_WAREHOUSE_IMMUTABLE', 'Không được thay đổi kho sở hữu bằng thao tác cập nhật hồ sơ.');
        }
        $sessionId = app(StableAuthSession::class)->id($actor);
        if ($sessionId === null && app(RefreshTokenService::class)->currentSessionId() !== null) {
            app(StableAuthSession::class)->requireId($actor);
        }
        if ($sessionId !== null) {
            $context = app(WarehouseContext::class)->payload($actor, $sessionId, 'AUTH_SESSION_REQUIRED');
            if ($context['warehouse_context']['status'] === 'UNAVAILABLE') {
                ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Ngữ cảnh kho không còn hợp lệ; tải lại phiên.');
            }
            if ($context['active_warehouse_id'] !== null && $context['active_warehouse_id'] !== (string) $owner->warehouse_id) {
                ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho của hồ sơ không khớp kho đang làm việc.');
            }
        }
        $warehouse = Warehouse::query()->find($owner->warehouse_id);
        if ($warehouse === null || $warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho sở hữu hồ sơ không còn hoạt động; chỉ được đọc.');
        }
    }

    public static function fields(string $caseId): array
    {
        return self::fieldsFor([$caseId])[$caseId] ?? self::unassignedFields();
    }

    public static function fieldsFor(array $ids): array
    {
        $rows = $ids === [] ? collect() : DB::table(self::TABLE.' as owner')
            ->leftJoin('warehouses as warehouse', 'warehouse.id', '=', 'owner.warehouse_id')
            ->whereIn('owner.warranty_case_id', array_values(array_unique($ids)))
            ->get(['owner.warranty_case_id', 'owner.warehouse_id', 'owner.source', 'warehouse.code', 'warehouse.name', 'warehouse.status']);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->warranty_case_id] = [
                'warehouse_id' => (string) $row->warehouse_id,
                'warehouse_code' => $row->code,
                'warehouse_name' => $row->name,
                'warehouse_status' => $row->status,
                'warehouse_ownership' => (string) $row->source,
            ];
        }
        return $result;
    }

    public static function enrich(iterable $rows): void
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) $row->warranty_case_id;
        }
        $fields = self::fieldsFor($ids);
        foreach ($rows as $row) {
            foreach ($fields[(string) $row->warranty_case_id] ?? self::unassignedFields() as $key => $value) {
                $row->{$key} = $value;
            }
        }
    }

    private static function unassignedFields(): array
    {
        return ['warehouse_id' => null, 'warehouse_code' => null, 'warehouse_name' => null, 'warehouse_status' => null, 'warehouse_ownership' => 'UNASSIGNED'];
    }
}
''')

write('database/migrations/2026_10_06_000002_add_warranty_case_warehouse_owners.php', r'''<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL exposes compatibility views; foreign keys must reference physical tables.
        $pgsql = DB::getDriverName() === 'pgsql';
        Schema::create('warranty_case_warehouse_owners', function (Blueprint $table) use ($pgsql): void {
            $table->uuid('warranty_case_id')->primary();
            $table->uuid('warehouse_id')->index();
            $table->string('source', 32);
            $table->uuid('auth_session_id')->nullable();
            $table->uuid('actor_id');
            $table->timestampTz('assigned_at');
            $table->text('evidence')->nullable();
            $table->foreign('warranty_case_id')->references($pgsql ? 'id' : 'warranty_case_id')->on($pgsql ? 'warranty_cases' : 'warranty_case')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('actor_id')->references($pgsql ? 'id' : 'user_id')->on($pgsql ? 'app_users' : 'app_user')->restrictOnDelete();
        });
        // Deliberately NO inference/backfill from item location, creator roles or the currently active warehouse.
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_case_warehouse_owners');
    }
};
''')

write('Modules/Warehouse/app/Http/Middleware/EnforceWarrantyWarehouseScope.php', r'''<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Symfony\Component\HttpFoundation\Response;

/** API-group registration survives canonical runtime route overrides and Mini App aliases. */
final class EnforceWarrantyWarehouseScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        $write = ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
        if (preg_match('~^api/v1/(?:mini-app/)?warranty-cases/([^/]+)(?:/|$)~D', $path, $match)) {
            WarrantyWarehouseScope::assertCase($request, $match[1], $write);
        } elseif (preg_match('~^api/v1/(?:mini-app/)?warranty-attachments/([^/]+)(?:/|$)~D', $path, $match)) {
            if (! Str::isUuid($match[1])) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy tệp đính kèm.');
            }
            $caseId = DB::table('warranty_attachments')->where('id', $match[1])->value('warranty_case_id');
            if ($caseId === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy tệp đính kèm.');
            }
            WarrantyWarehouseScope::assertCase($request, (string) $caseId, $write);
        }
        return $next($request);
    }
}
''')

write('app/Console/Commands/AssignWarrantyWarehouseOwners.php', r'''<?php

namespace App\Console\Commands;

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
            // Report caches include the ownership table revision, so audited backfills cannot leave old totals cached.
            $this->info(($apply ? 'Assigned: ' : 'Dry-run; eligible: ').$count);
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
''')

# Scope the actual SQL before filtering, counting and pagination; enrich only already-authorized rows.
p = 'Modules/Warehouse/app/Http/Controllers/CanonicalMvpController.php'
edit(p, '$query = DB::table($table);', "$query = $table === 'warranty_case'\n            ? \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::query($request)\n            : DB::table($table);")
edit(p, 'WarrantyCasePii::decodeRows($rows);', 'WarrantyCasePii::decodeRows($rows);\n                \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::enrich($rows);')
edit(p, 'return $this->respondPaginated($page);', "$response = $this->respondPaginated($page);\n        if ($table === 'warranty_case') {\n            $body = $response->getData(true);\n            $body['meta']['warehouse_scope'] = \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::metadata($request);\n            $response->setData($body);\n        }\n        return $response;")

p = 'Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php'
edit(p, '        $execute = function () use ($validated, $request): array {', '        \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::prepareCreate($request);\n\n        $execute = function () use ($validated, $request): array {')
text = Path(p).read_text()
needle = "                'create_warranty_case',\n                $this->currentActorId(),\n                IdempotencyStore::requestHash($request),"
edit(p, needle, needle.replace('IdempotencyStore::requestHash($request)', '\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::creationHash($request)'))
edit(p, '        return DB::transaction(function () use ($validated, $request): string {\n            $item =', '        return DB::transaction(function () use ($validated, $request): string {\n            \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::lockCreate($request);\n            $item =')
edit(p, '            $this->syncWarrantyDefects($caseId, $defectIds);', '            \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::bindNew($request, $caseId);\n            $this->syncWarrantyDefects($caseId, $defectIds);')
edit(p, "            'id' => (string) $case->warranty_case_id,\n            'warranty_case_id' => (string) $case->warranty_case_id,", "            ...\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::fields($id),\n            'id' => (string) $case->warranty_case_id,\n            'warranty_case_id' => (string) $case->warranty_case_id,")

p = 'Modules/Warehouse/app/Http/Controllers/WarrantyController.php'
edit(p, "                    return [\n                        'warranty_case_id' => $case->warranty_case_id,", "                    return [\n                        ...\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::fields((string) $case->warranty_case_id),\n                        'warranty_case_id' => $case->warranty_case_id,")

# Group-level middleware is deliberately after permission checks and covers runtime overrides.
p = 'bootstrap/app.php'
edit(p, '$middleware->api(append: [EnsureWarehouseOperational::class]);', '$middleware->api(append: [EnsureWarehouseOperational::class, \\Modules\\Warehouse\\Http\\Middleware\\EnforceWarrantyWarehouseScope::class]);')
edit(p, '            CheckPermission::class,', '            CheckPermission::class,\n            \\Modules\\Warehouse\\Http\\Middleware\\EnforceWarrantyWarehouseScope::class,')

# Every warranty row/aggregate query in these report builders uses the SAME owner relation.
for p in ['Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php', 'Modules/Warehouse/app/Http/Controllers/BaM1ReportController.php']:
    text = Path(p).read_text()
    n = text.count("DB::table('warranty_case as wc')")
    if n < 1:
        raise RuntimeError('Missing report query anchor: '+p)
    write(p, text.replace("DB::table('warranty_case as wc')", "\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::query($request, 'wc')"))
p = 'Modules/Warehouse/app/Http/Controllers/BaM1ReportController.php'
edit(p, "->join('warranty_case as wc', 'wc.warranty_case_id', '=', 'wcd.warranty_case_id')", "->join('warranty_case as wc', 'wc.warranty_case_id', '=', 'wcd.warranty_case_id')\n            ->whereIn('wc.warranty_case_id', \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::query($request)->select('warranty_case.warranty_case_id'))", count=2)
p = 'Modules/Warehouse/app/Http/Controllers/OperationalReportController.php'
text = Path(p).read_text()
if text.count("DB::table('warranty_case')") != 5:
    raise RuntimeError('Dashboard warranty query inventory changed')
write(p, text.replace("DB::table('warranty_case')", "\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::query(request())").replace('// warranty_case không mang warehouse_id (ERD) nên KPI bảo hành là GLOBAL theo\n        // thiết kế dữ liệu, kể cả với người dùng bị giới hạn kho. Đã khai rõ trong OpenAPI.', '// Warranty aggregates now follow persisted case ownership, including TEMP_ONLY.'))

# Cache revision changes both at rollout and after an explicit backfill. No auth/cache flush.
p = 'Modules/Warehouse/app/Http/Controllers/Concerns/CachesReportPayload.php'
edit(p, "        return CacheKey::of($namespace, [", "        $warrantyRevision = null;\n        if (str_contains($namespace, 'warranty') || str_contains($namespace, 'dashboard')) {\n            \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::readSelection(request());\n            $revision = \\Illuminate\\Support\\Facades\\DB::table(\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::TABLE)\n                ->selectRaw('COUNT(*) as total')->selectRaw('MAX(assigned_at) as latest')->first();\n            $warrantyRevision = [\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::VERSION, $revision->total, $revision->latest];\n        }\n        return CacheKey::of($namespace, [\n            'warranty_owner_revision' => $warrantyRevision,")

# Mini App P02 receives an additive warranty block; existing stock KPI meaning stays unchanged.
p = 'Modules/Warehouse/app/Http/Controllers/MiniAppDashboardController.php'
edit(p, '        return $this->respondSuccess($payload);', "        $canReadWarranty = $request->user()?->hasPermission('warranty.view') || $request->user()?->hasPermission('warranty.manage');\n        $payload['warranty'] = $canReadWarranty ? [\n            'open_count' => \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::query($request)\n                ->whereIn('status', \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::OPEN_STATUSES)->count(),\n            'open_statuses' => \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::OPEN_STATUSES,\n            'warehouse_scope' => \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::metadata($request),\n        ] : null;\n        return $this->respondSuccess($payload);")

# The existing P02 test uses an access-only client: explicit UUID is its confirmation, not an inferred default.
p = 'tests/Feature/P02ReadContractTest.php'
edit(p, "        $this->ensureActiveWarehouse('P02-A');\n        $token = $this->token();\n        $ids = [];", "        $warehouse = $this->ensureActiveWarehouse('P02-A');\n        $token = $this->token();\n        $ids = [];")
edit(p, "                'manual_product_description' => 'P02 isolated fixture',", "                'manual_product_description' => 'P02 isolated fixture',\n                'warehouse_id' => (string) $warehouse->id,")

Path('/tmp/warranty-owner-changed.json').write_text(json.dumps(sorted(set(changed))))
print('Applied exact reviewed changes to', len(set(changed)), 'files')
