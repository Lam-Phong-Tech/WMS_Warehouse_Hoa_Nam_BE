<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;

/**
 * GĐ1 recipient master backed by historical agencies table.
 * Exactly four business recipient classes are allowed.
 */
class BaM1RecipientController extends Controller
{
    public const TYPES = ['DISTRIBUTOR', 'AGENCY', 'PROJECT_CUSTOMER', 'RETAIL_CUSTOMER'];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'recipient_type' => ['nullable', Rule::in(self::TYPES)],
            'market' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'keyword' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = DB::table('agencies');
        foreach (['recipient_type', 'market', 'region', 'status'] as $field) {
            if (! empty($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower(trim((string) $validated['keyword'])).'%';
            $query->where(function ($nested) use ($keyword): void {
                $nested->whereRaw('LOWER(agency_code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(agency_name) LIKE ?', [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(contact_name, '')) LIKE ?", [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(phone, '')) LIKE ?", [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(market, '')) LIKE ?", [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(region, '')) LIKE ?", [$keyword]);
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);
        $total = (clone $query)->count();
        $rows = $query->orderBy('agency_name')->forPage($page, $perPage)->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'recipient_types' => self::TYPES,
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, null, false);
        $id = (string) Str::uuid();
        $now = now();
        DB::table('agencies')->insert([
            'agency_id' => $id,
            'agency_code' => $validated['agency_code'],
            'agency_name' => $validated['agency_name'],
            'recipient_type' => $validated['recipient_type'],
            'market' => $validated['market'] ?? null,
            'region' => $validated['region'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'status' => $validated['status'] ?? 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $after = DB::table('agencies')->where('agency_id', $id)->first();
        $this->audit('RECIPIENT_CREATE', $id, $request, null, $after);

        return response()->json(['success' => true, 'data' => $after], 201);
    }

    public function show(string $id)
    {
        $row = DB::table('agencies')->where('agency_id', $id)->first();
        if ($row === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy đối tượng nhận hàng.');
        }

        return response()->json(['success' => true, 'data' => $row]);
    }

    public function update(Request $request, string $id)
    {
        $before = DB::table('agencies')->where('agency_id', $id)->first();
        if ($before === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy đối tượng nhận hàng.');
        }
        $validated = $this->validatePayload($request, $id, true);
        if ($validated !== []) {
            $validated['updated_at'] = now();
            DB::table('agencies')->where('agency_id', $id)->update($validated);
        }
        $after = DB::table('agencies')->where('agency_id', $id)->first();
        $this->audit('RECIPIENT_UPDATE', $id, $request, $before, $after);

        return response()->json(['success' => true, 'data' => $after]);
    }

    private function validatePayload(Request $request, ?string $id, bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'agency_code' => [
                $required, 'string', 'max:50',
                Rule::unique('agencies', 'agency_code')->ignore($id, 'agency_id'),
            ],
            'agency_name' => [$required, 'string', 'max:255'],
            'recipient_type' => [$required, Rule::in(self::TYPES)],
            'market' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^0\d{9}$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
    }

    private function audit(string $action, string $id, Request $request, mixed $before, mixed $after): void
    {
        AuditLogger::record(
            action: $action,
            objectType: 'recipient',
            objectId: $id,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            beforeData: $before === null ? [] : (array) $before,
            afterData: $after === null ? [] : (array) $after,
        );
    }
}
