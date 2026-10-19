<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\DocumentTimestamp;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;

/** Fresh, read-only metadata after owner authorization; never put PII in shared read cache. */
final class P12StockOwnerProjection
{
    public const CONTRACT = 'p12-owner-projection-v1';

    public static function read(Request $request, string $source): array
    {
        $id = (string) $request->route('id');
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nguồn.');
        }
        [$type, $warehouseField, $permission] = match ($source) {
            'INBOUND' => ['receipt', 'dst_warehouse_id', 'inbound.view'],
            'OUTBOUND' => ['issue', 'src_warehouse_id', 'outbound.view'],
            default => throw new \InvalidArgumentException('Unsupported stock projection source'),
        };
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission($permission)) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Không có quyền đọc phiếu nguồn.');
        }
        $parent = DB::table('stock_documents')->where('id', strtolower($id))
            ->where('doc_type', $type)->whereNull('deleted_at')->first(['id', $warehouseField, 'created_by']);
        if ($parent === null || ! Str::isUuid((string) $parent->{$warehouseField})) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nguồn trong phạm vi hiện tại.');
        }
        $warehouse = (string) $parent->{$warehouseField};
        if (! $actor->canAccessWarehouse($warehouse)) {
            ApiError::abort(403, 'DATA_SCOPE_DENIED', 'Phiếu nguồn nằm ngoài phạm vi kho.');
        }
        $selected = $request->query('warehouse_id');
        if ($selected !== null && (! is_string($selected) || ! Str::isUuid($selected))) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'warehouse_id phải là UUID.');
        }
        if ($selected !== null && strtolower($selected) !== strtolower($warehouse)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nguồn trong kho yêu cầu.');
        }

        return [
            'contract' => self::CONTRACT,
            'revision' => 'p12-owner-projection-r1',
            'document_id' => (string) $parent->id,
            'warehouse_id' => $warehouse,
            'source' => $source,
            'supplier' => $source === 'INBOUND' ? self::supplier((string) $parent->id) : self::missingSupplier('NOT_APPLICABLE'),
            'creator' => self::creator($parent->created_by, $actor->hasPermission('iam.user.view')),
        ];
    }

    private static function supplier(string $id): array
    {
        try {
            // Savepoints keep PostgreSQL usable if optional projection storage is unavailable.
            $metadata = DB::transaction(fn () => DB::table('mini_app_inbound_record_metadata')
                ->where('document_id', $id)->first(['supplier_id', 'supplier_name', 'supplier_version', 'recorded_at']));
        } catch (QueryException) {
            return self::missingSupplier('SOURCE_UNAVAILABLE');
        }
        if ($metadata === null) {
            return self::missingSupplier('NOT_RECORDED');
        }
        $name = self::text($metadata->supplier_name);
        $status = $metadata->supplier_id === null && $name === null ? 'NOT_SELECTED' : 'SNAPSHOT_RECORDED';

        return [
            'status' => $status,
            'id' => $metadata->supplier_id,
            'name' => $name,
            'name_status' => $name !== null ? 'SNAPSHOT_RECORDED'
                : ($status === 'NOT_SELECTED' ? 'NOT_SELECTED' : 'NOT_RECORDED'),
            'version' => $metadata->supplier_version === null ? null : (int) $metadata->supplier_version,
            'recorded_at' => DocumentTimestamp::utc($metadata->recorded_at),
            'source' => 'mini_app_inbound_record_metadata',
        ];
    }

    private static function missingSupplier(string $status): array
    {
        return ['status' => $status, 'id' => null, 'name' => null, 'name_status' => $status, 'version' => null,
            'recorded_at' => null, 'source' => null];
    }

    private static function creator(?string $id, bool $canReadDirectory): array
    {
        $result = ['actor_id' => $id, 'source' => $id === null ? null : 'stock_documents.created_by',
            'display_name' => null, 'display_name_status' => 'HISTORICAL_SNAPSHOT_NOT_RECORDED',
            'role' => null, 'role_status' => 'HISTORICAL_SNAPSHOT_NOT_RECORDED',
            'current_display_name' => null,
            'current_display_name_status' => $id === null ? 'ACTOR_NOT_RECORDED' : 'REDACTED_PII_PERMISSION',
            'current_display_name_source' => null];
        if ($id === null || ! $canReadDirectory) {
            return $result;
        }
        try {
            $user = DB::transaction(fn () => DB::table('app_user')->where('user_id', $id)
                ->where('status', 'ACTIVE')->whereNull('deleted_at')->first(['display_name']));
        } catch (QueryException) {
            $result['current_display_name_status'] = 'SOURCE_UNAVAILABLE';

            return $result;
        }
        $result['current_display_name'] = self::text($user?->display_name);
        $result['current_display_name_status'] = $result['current_display_name'] === null
            ? 'NOT_RECORDED' : 'CURRENT_DIRECTORY_LABEL_NOT_HISTORICAL_SNAPSHOT';
        $result['current_display_name_source'] = 'app_user.display_name';

        return $result;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
