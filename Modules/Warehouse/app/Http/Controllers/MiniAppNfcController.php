<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\Database\SchemaCache;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\WarrantyPiiMasker;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\ProductIdentityResolver;
use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use Modules\Warehouse\Support\NfcTimestamp;

/**
 * Mini App NFC adapter — gán chip NFC cho từng hiện vật (item_instance) và tra
 * cứu ngược hiện vật từ chip.
 *
 * NGUYÊN TẮC (không được phá vỡ ở các thay đổi sau):
 *
 * 1. NFC KHÔNG phải một identity thứ hai. Chip chỉ mang lại đúng payload mà QR
 *    của hiện vật đang mang (`HN1|SKU=<sku_code>|ITEM=<item_unique>`, DEC-QR-001).
 *    Nhờ vậy MỌI endpoint scan sẵn có (`/scan/classify`, inbound, outbound,
 *    warranty) nhận được nội dung đọc từ chip mà không cần sửa một dòng nào:
 *    quét NFC = quét QR.
 *
 * 2. Bản ghi NFC là một dòng `physical_codes` carrier_type=NFC gắn vào item, với
 *    `hardware_uid` = UID phần cứng của chip (khoá chống trùng thật sự) và
 *    `code_value` = `NFC-<hardware_uid>` (alias tra cứu, duy nhất toàn hệ thống).
 *
 * 3. Ghi chip là thao tác VẬT LÝ không hoàn tác được, nên tách hai bước:
 *    `prepare` giữ chỗ (reservation có thời hạn) trước khi nhân viên ghi NDEF, và
 *    `confirm` chốt mapping sau khi đã ghi + đọc lại thành công. Reservation chỉ
 *    là hàng rào trải nghiệm; hàng rào đúng đắn vẫn là unique(hardware_uid) +
 *    lockForUpdate trong transaction của `confirm`.
 *
 * 4. Toàn bộ nhóm này STOCK-NEUTRAL tuyệt đối — không tạo movement, không đổi
 *    inventory_balances, không phải gate của Post Receipt/Post Issue (cùng lớp
 *    quyết định với DEC-RFID-FALLBACK-001 cho RFID).
 */
class MiniAppNfcController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;
    use ResolvesActor;

    /**
     * Gán/thu hồi chip NFC là ghi STOCK-NEUTRAL: không qua mutateBalance() nên không
     * ai bump 'stock:version' hộ, trong khi read cache tra cứu theo mã (Mini App bảo
     * hành, truy vết) đọc thẳng physical_codes vừa ghi.
     */
    private function invalidateReadCache(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));
    }

    /** Tiền tố alias `code_value` cho dòng physical_codes của chip NFC. */
    private const CODE_VALUE_PREFIX = 'NFC-';

    /** Prefix key cache của reservation ghi chip. */
    private const RESERVATION_KEY = 'wms:nfc:write-reservation:';

    public function __construct(
        private readonly ProductQrPayloadParser $parser,
        private readonly ProductIdentityResolver $identityResolver,
    ) {}

    /**
     * Danh sách hiện vật đang quản lý kèm trạng thái chip — worklist của màn gán
     * NFC. Lọc `nfc_status=UNASSIGNED` để lấy đúng những máy còn phải dán chip.
     */
    public function listItems(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:200'],
            'sku_id' => ['nullable', 'uuid'],
            'item_status' => ['nullable', 'string', 'max:40'],
            'container_id' => ['nullable', 'uuid'],
            'nfc_status' => ['nullable', Rule::in(['UNASSIGNED', 'ASSIGNED', 'ALL'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Chip ACTIVE của hiện vật được nạp Ở BƯỚC SAU cho đúng trang, không join
        // ở đây: một hiện vật về lý thuyết có thể mang nhiều dòng NFC ACTIVE và
        // join thẳng sẽ nhân đôi dòng item. Gom bằng aggregate cũng không được —
        // Postgres không có max(uuid) (SQLite thì nuốt, nên lỗi chỉ lộ ở runtime).
        $query = DB::table('item_instances as i')
            ->join('sku as s', 's.sku_id', '=', 'i.sku_id')
            ->select([
                'i.item_id', 'i.serial_number', 'i.item_status', 'i.item_condition', 'i.container_id',
                's.sku_id', 's.sku_code', 's.sku_name', 's.unit',
            ]);

        $itemStatus = strtoupper(trim((string) ($validated['item_status'] ?? 'IN_STOCK')));
        if ($itemStatus !== 'ALL') {
            $query->where('i.item_status', $itemStatus);
        }
        if (! empty($validated['sku_id'])) {
            $query->where('i.sku_id', (string) $validated['sku_id']);
        }
        if (! empty($validated['container_id'])) {
            $query->where('i.container_id', (string) $validated['container_id']);
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower(trim((string) $validated['keyword'])).'%';
            $query->where(function ($nested) use ($keyword): void {
                $nested->whereRaw('LOWER(i.serial_number) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(s.sku_code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(s.sku_name) LIKE ?', [$keyword]);
            });
        }
        // Chỉ tính chip ACTIVE: chip đã LOST/DAMAGED/INACTIVE nghĩa là hiện vật
        // đang trần và phải quay lại worklist, không phải "đã gán".
        $nfcFilter = strtoupper((string) ($validated['nfc_status'] ?? 'ALL'));
        if ($nfcFilter === 'UNASSIGNED' || $nfcFilter === 'ASSIGNED') {
            $exists = function ($sub): void {
                $sub->from('physical_codes as pc')
                    ->whereColumn('pc.item_id', 'i.item_id')
                    ->where('pc.carrier_type', 'NFC')
                    ->where('pc.status', 'ACTIVE')
                    ->selectRaw('1');
            };
            $nfcFilter === 'ASSIGNED' ? $query->whereExists($exists) : $query->whereNotExists($exists);
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);
        $total = (clone $query)->count();
        $rows = $query->orderBy('s.sku_code')->orderBy('i.serial_number')->forPage($page, $perPage)->get();

        // item_unique và chip của cả trang lấy trong hai truy vấn phụ thay vì mỗi
        // dòng một lần — nếu không, một trang 100 dòng thành 200 round-trip.
        $uniqueMap = $this->itemUniqueMap($rows->all());
        $tagMap = $this->activeTagMap($rows->pluck('item_id')->all());

        $data = $rows->map(function (object $row) use ($uniqueMap, $tagMap): array {
            $tag = $tagMap[(string) $row->item_id] ?? null;

            return [
                'item_id' => (string) $row->item_id,
                'serial_number' => $row->serial_number,
                'item_status' => $row->item_status,
                'item_condition' => $row->item_condition,
                'container_id' => $row->container_id,
                'sku' => [
                    'sku_id' => (string) $row->sku_id,
                    'sku_code' => (string) $row->sku_code,
                    'sku_name' => $row->sku_name,
                    'unit' => $row->unit,
                ],
                'nfc_status' => $tag === null ? 'UNASSIGNED' : 'ASSIGNED',
                'nfc' => $this->tagPayload($tag),
                'nfc_payload' => $this->buildPayload((string) $row->sku_code, $uniqueMap[(string) $row->item_id] ?? null),
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'request_id' => $request->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Quét QR sản phẩm để lấy `item_id` cho bước prepare. Read-only tuyệt đối —
     * đây là cầu nối duy nhất giữa "cầm cái máy trên tay" và "biết item_id".
     */
    public function resolveCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'raw_code' => ['required', 'string', 'min:1', 'max:'.$this->maxPayloadLength()],
        ]);

        $rawCode = trim((string) $validated['raw_code']);
        $item = $this->resolveItemByRawCode($rawCode);

        if ($item === null) {
            return $this->respondSuccess([
                'raw_code' => $rawCode,
                'resolved' => false,
                'assignable' => false,
                'assign_block_code' => 'ITEM_NOT_FOUND',
                'item' => null,
                'sku' => null,
                'nfc_status' => null,
                'nfc' => null,
                'nfc_payload' => null,
                'stock_effect' => 'NONE',
                'movement_created' => false,
                'physical_code_created' => false,
            ], 'Không tìm thấy hiện vật khớp mã vừa quét; chưa ghi nhận gì.');
        }

        $sku = DB::table('sku')->where('sku_id', $item->sku_id)->first();
        $payload = $this->nfcPayloadFor($item, $sku);
        $existing = $this->activeNfcTagForItem((string) $item->item_id);
        [$assignable, $blockCode] = $this->assignability($item, $payload, $existing);

        return $this->respondSuccess([
            'raw_code' => $rawCode,
            'resolved' => true,
            'assignable' => $assignable,
            'assign_block_code' => $blockCode,
            'item' => $this->itemPayload($item),
            'sku' => $this->skuPayload($sku),
            'nfc_status' => $existing === null ? 'UNASSIGNED' : 'ASSIGNED',
            'nfc' => $this->tagPayload($existing),
            'nfc_payload' => $payload,
            'stock_effect' => 'NONE',
            'movement_created' => false,
            'physical_code_created' => false,
        ], $assignable
            ? 'Đã resolve hiện vật; gọi prepare để giữ chỗ trước khi ghi chip.'
            : 'Đã resolve hiện vật nhưng chưa đủ điều kiện gán chip NFC mới.');
    }

    /**
     * Bước 1 của ghi chip: kiểm tra điều kiện và GIỮ CHỖ có thời hạn cho hiện vật
     * này, rồi trả chuỗi phải ghi vào chip.
     *
     * Reservation tồn tại vì bước ghi NDEF diễn ra ngoài hệ thống và không hoàn
     * tác được: nếu hai máy cùng chuẩn bị một hiện vật, một bên sẽ ghi và dán chip
     * xong mới biết mình thua ở bước confirm — chip đó thành rác vật lý.
     */
    public function prepare(Request $request, string $itemId): JsonResponse
    {
        $item = DB::table('item_instances')->where('item_id', $itemId)->first();
        if ($item === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hiện vật.');
        }

        $sku = DB::table('sku')->where('sku_id', $item->sku_id)->first();
        $payload = $this->nfcPayloadFor($item, $sku);
        $existing = $this->activeNfcTagForItem($itemId);
        [$assignable, $blockCode] = $this->assignability($item, $payload, $existing);

        if (! $assignable) {
            $status = $blockCode === 'ITEM_ALREADY_HAS_NFC' ? 409 : 422;
            ApiError::abort($status, $blockCode, match ($blockCode) {
                'ITEM_ALREADY_HAS_NFC' => 'Hiện vật đã có chip NFC đang hoạt động; thu hồi chip cũ hoặc confirm kèm replace_existing.',
                'ITEM_IDENTITY_NOT_RESOLVABLE' => 'Hiện vật chưa có serial/mã vật lý ổn định nên không dựng được payload để ghi vào chip.',
                default => 'Trạng thái hiện vật không cho phép gán chip NFC.',
            }, [
                'assign_block_code' => $blockCode,
                'nfc' => $this->tagPayload($existing),
            ]);
        }

        $actorId = $this->actorId();
        $reservation = $this->acquireReservation($itemId, $actorId);

        AuditLogger::record(
            action: 'PREPARE_ITEM_NFC',
            objectType: 'item_instance',
            objectId: $itemId,
            actorId: $actorId,
            requestId: $request->attributes->get('request_id'),
            afterData: [
                'reservation_token' => $reservation['token'],
                'expires_at' => $reservation['expires_at'],
                'stock_effect' => 'NONE',
            ],
        );

        return $this->respondSuccess([
            'item' => $this->itemPayload($item),
            'sku' => $this->skuPayload($sku),
            'assignable' => true,
            'assign_block_code' => 'ASSIGNABLE',
            'nfc_status' => 'UNASSIGNED',
            'nfc_payload' => $payload,
            'reservation_token' => $reservation['token'],
            'expires_at' => $reservation['expires_at'],
            'ttl_seconds' => $this->reservationTtl(),
            'stock_effect' => 'NONE',
            'movement_created' => false,
            'physical_code_created' => false,
        ], 'Đã giữ chỗ ghi chip; ghi nfc_payload vào chip rồi gọi PATCH .../nfc để xác nhận.');
    }

    /**
     * Bước 2 của ghi chip: chip đã ghi và đọc lại thành công, chốt mapping
     * (item ↔ hardware_uid).
     *
     * Hai lớp idempotency chồng nhau có chủ ý: `Idempotency-Key` chặn double-submit
     * của cùng một lần bấm, còn khoá tự nhiên `hardware_uid` chặn cả trường hợp
     * client sinh key mới rồi chạm lại đúng chip cũ.
     */
    public function confirm(Request $request, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'hardware_uid' => ['required', 'string', 'min:1', 'max:150'],
            'code_value' => ['nullable', 'string', 'max:100'],
            'written_payload' => ['nullable', 'string', 'max:'.$this->maxPayloadLength()],
            'reservation_token' => ['nullable', 'string', 'max:100'],
            'replace_existing' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = DB::table('item_instances')->where('item_id', $itemId)->first();
        if ($item === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hiện vật.');
        }

        $sku = DB::table('sku')->where('sku_id', $item->sku_id)->first();
        $expectedPayload = $this->nfcPayloadFor($item, $sku);
        if ($expectedPayload === null) {
            ApiError::abort(422, 'ITEM_IDENTITY_NOT_RESOLVABLE', 'Hiện vật chưa có serial/mã vật lý ổn định nên không dựng được payload để ghi vào chip.');
        }

        // Chip đã ghi rồi mới gọi endpoint này, nên nếu client gửi kèm nội dung
        // thật đã đọc lại từ chip thì phải khớp item — lệch nghĩa là dán nhầm chip
        // lên sản phẩm khác, chặn tại đây thay vì để sai lệch trôi vào dữ liệu.
        if (! empty($validated['written_payload'])) {
            $writtenItem = $this->resolveItemByRawCode(trim((string) $validated['written_payload']));
            if ($writtenItem === null || (string) $writtenItem->item_id !== $itemId) {
                ApiError::abort(422, 'NFC_PAYLOAD_ITEM_MISMATCH', 'Nội dung đọc lại từ chip không trỏ về đúng hiện vật đang gán.', [
                    'expected_payload' => $expectedPayload,
                ]);
            }
        }

        $actorId = $this->actorId();
        $this->assertReservationUsable($itemId, $actorId, $validated['reservation_token'] ?? null);

        $hardwareUid = strtoupper(trim((string) $validated['hardware_uid']));
        $codeValue = strtoupper(trim((string) ($validated['code_value'] ?? (self::CODE_VALUE_PREFIX.$hardwareUid))));
        $replaceExisting = (bool) ($validated['replace_existing'] ?? false);
        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : null;
        if ($replaceExisting && ($reason === null || $reason === '')) {
            ApiError::abort(422, 'REPLACE_REASON_REQUIRED', 'Thay chip cũ bắt buộc khai lý do để truy vết.');
        }

        $idempotencyKey = IdempotencyKeyGuard::require($request);
        $requestHash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle(
            $idempotencyKey,
            'mini_app_nfc_confirm',
            $actorId,
            $requestHash,
            function () use ($itemId, $item, $sku, $hardwareUid, $codeValue, $replaceExisting, $reason, $actorId, $request, $expectedPayload): array {
                $result = DB::transaction(function () use ($itemId, $hardwareUid, $codeValue, $replaceExisting, $reason, $actorId, $request, $expectedPayload): array {
                    $sameTag = DB::table('physical_codes')
                        ->where('hardware_uid', $hardwareUid)
                        ->lockForUpdate()
                        ->first();

                    if ($sameTag !== null) {
                        if ((string) ($sameTag->item_id ?? $sameTag->assigned_object_id ?? '') !== $itemId) {
                            ApiError::abort(409, 'NFC_TAG_ALREADY_ASSIGNED', 'Chip NFC này đã gắn cho hiện vật khác; bóc chip cũ hoặc dùng chip mới.', [
                                'physical_code_id' => (string) $sameTag->physical_code_id,
                            ]);
                        }
                        if (strtoupper((string) $sameTag->status) !== 'ACTIVE') {
                            ApiError::abort(409, 'NFC_TAG_INACTIVE', 'Chip NFC này đã bị thu hồi (mất/hỏng); không tái sử dụng được.', [
                                'physical_code_id' => (string) $sameTag->physical_code_id,
                                'status' => (string) $sameTag->status,
                            ]);
                        }

                        return ['tag' => $sameTag, 'created' => false, 'replaced' => null];
                    }

                    $currentTag = DB::table('physical_codes')
                        ->where('item_id', $itemId)
                        ->where('carrier_type', 'NFC')
                        ->where('status', 'ACTIVE')
                        ->lockForUpdate()
                        ->first();
                    if ($currentTag !== null && ! $replaceExisting) {
                        ApiError::abort(409, 'ITEM_ALREADY_HAS_NFC', 'Hiện vật đã có chip NFC đang hoạt động; gửi replace_existing=true kèm reason nếu thay chip.', [
                            'physical_code_id' => (string) $currentTag->physical_code_id,
                            'hardware_uid' => (string) $currentTag->hardware_uid,
                        ]);
                    }

                    if (DB::table('physical_codes')->where('code_value', $codeValue)->exists()) {
                        ApiError::abort(409, 'DUPLICATE_CODE_VALUE', 'code_value của chip đã tồn tại trong hệ thống.', [
                            'code_value' => $codeValue,
                        ]);
                    }

                    $physicalCodeId = (string) Str::uuid();
                    DB::table('physical_codes')->insert($this->tagInsertRow($physicalCodeId, $itemId, $codeValue, $hardwareUid, $actorId));

                    if ($currentTag !== null) {
                        // Chip cũ không bị xoá: chuyển INACTIVE + trỏ replaced_by để
                        // lịch sử "hiện vật này từng đeo chip nào" đọc lại được.
                        DB::table('physical_codes')->where('physical_code_id', $currentTag->physical_code_id)->update([
                            'status' => 'INACTIVE',
                            'is_primary' => false,
                            'replaced_by_code_id' => $physicalCodeId,
                            'deactivated_at' => NfcTimestamp::write(now()),
                            'deactivation_reason' => $reason,
                            'version' => (int) ($currentTag->version ?? 1) + 1,
                            'updated_by' => $actorId,
                            'updated_at' => NfcTimestamp::write(now()),
                        ]);
                    }

                    AuditLogger::record(
                        action: 'ASSIGN_ITEM_NFC',
                        objectType: 'physical_code',
                        objectId: $physicalCodeId,
                        actorId: $actorId,
                        requestId: $request->attributes->get('request_id'),
                        reason: $reason,
                        afterData: [
                            'item_id' => $itemId,
                            'hardware_uid' => $hardwareUid,
                            'code_value' => $codeValue,
                            'written_payload' => $expectedPayload,
                            'replaced_physical_code_id' => $currentTag?->physical_code_id,
                            'stock_effect' => 'NONE',
                        ],
                    );
                    // Bump nằm trong closure IdempotencyStore nên replay không xoá
                    // cache vô ích.
                    $this->invalidateReadCache();

                    return [
                        'tag' => DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->first(),
                        'created' => true,
                        'replaced' => $currentTag?->physical_code_id,
                    ];
                }, 3);

                $this->releaseReservation($itemId);

                $payload = [
                    'success' => true,
                    'message' => $result['created']
                        ? 'Đã gán chip NFC cho hiện vật; tồn kho không đổi.'
                        : 'Chip NFC đã gán trước đó cho đúng hiện vật này.',
                    'data' => [
                        'item' => $this->itemPayload($item),
                        'sku' => $this->skuPayload($sku),
                        'nfc_status' => 'ASSIGNED',
                        'nfc' => $this->tagPayload($result['tag']),
                        'nfc_payload' => $expectedPayload,
                        'replaced_physical_code_id' => $result['replaced'],
                        'stock_effect' => 'NONE',
                        'movement_created' => false,
                        'nfc_required_for_post' => false,
                    ],
                    'meta' => [
                        'timestamp_contract' => NfcTimestamp::CONTRACT,
                        'request_id' => $this->currentRequestId(),
                        'timestamp' => now()->toIso8601String(),
                    ],
                ];

                return [$result['created'] ? 201 : 200, $payload];
            }
        );

        return response()->json($body, $code);
    }

    /**
     * Tra cứu ngược từ chip: dùng cho màn "quét NFC thay QR" sau khi hàng đã rời
     * kho — trả về hiện vật, SKU, phiếu xuất gần nhất (ai nhận) và hồ sơ bảo hành
     * đang mở.
     */
    public function resolveTag(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hardware_uid' => ['nullable', 'string', 'max:150'],
            'raw_code' => ['nullable', 'string', 'max:'.$this->maxPayloadLength()],
        ]);

        $hardwareUid = isset($validated['hardware_uid']) ? strtoupper(trim((string) $validated['hardware_uid'])) : '';
        $rawCode = isset($validated['raw_code']) ? trim((string) $validated['raw_code']) : '';
        if ($hardwareUid === '' && $rawCode === '') {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Dữ liệu đầu vào không hợp lệ.', [
                'hardware_uid' => ['Phải gửi hardware_uid (UID chip) hoặc raw_code (nội dung đọc từ chip).'],
            ]);
        }

        $tag = $hardwareUid !== ''
            ? DB::table('physical_codes')->where('hardware_uid', $hardwareUid)->first()
            : null;

        $item = null;
        if ($tag !== null) {
            $itemId = (string) ($tag->item_id ?? $tag->assigned_object_id ?? '');
            $item = $itemId !== '' ? DB::table('item_instances')->where('item_id', $itemId)->first() : null;
        }
        if ($item === null && $rawCode !== '') {
            $item = $this->resolveItemByRawCode($rawCode);
            if ($item !== null && $tag === null) {
                $tag = $this->activeNfcTagForItem((string) $item->item_id);
            }
        }

        if ($item === null) {
            return $this->respondSuccess([
                'hardware_uid' => $hardwareUid !== '' ? $hardwareUid : null,
                'raw_code' => $rawCode !== '' ? $rawCode : null,
                'resolved' => false,
                'resolve_code' => $tag === null ? 'NFC_TAG_NOT_REGISTERED' : 'NFC_TAG_NOT_ASSIGNED',
                'item' => null,
                'sku' => null,
                'nfc' => $this->tagPayload($tag),
                'last_issue' => null,
                'warranty' => null,
                'stock_effect' => 'NONE',
            ], 'Chip chưa gắn với hiện vật nào trong hệ thống.');
        }

        $sku = DB::table('sku')->where('sku_id', $item->sku_id)->first();

        return $this->respondSuccess([
            'hardware_uid' => $tag?->hardware_uid ?? ($hardwareUid !== '' ? $hardwareUid : null),
            'raw_code' => $rawCode !== '' ? $rawCode : null,
            'resolved' => true,
            'resolve_code' => 'RESOLVED',
            'item' => $this->itemPayload($item),
            'sku' => $this->skuPayload($sku),
            'nfc' => $this->tagPayload($tag),
            'nfc_payload' => $this->nfcPayloadFor($item, $sku),
            'last_issue' => $this->lastIssuePayload($request, (string) $item->item_id),
            'warranty' => $this->activeWarrantyPayload((string) $item->item_id),
            'stock_effect' => 'NONE',
        ], 'Đã tra cứu hiện vật từ chip NFC.');
    }

    /** Danh sách chip đã gán — lọc theo hiện vật, UID hoặc trạng thái. */
    public function listTags(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['nullable', 'uuid'],
            'hardware_uid' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'LOST', 'DAMAGED'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('physical_codes as pc')
            ->leftJoin('item_instances as i', 'i.item_id', '=', 'pc.item_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'i.sku_id')
            ->where('pc.carrier_type', 'NFC')
            ->select([
                'pc.physical_code_id', 'pc.code_value', 'pc.hardware_uid', 'pc.status',
                'pc.is_primary', 'pc.assigned_at', 'pc.replaced_by_code_id', 'pc.version',
                'pc.item_id', 'i.serial_number', 'i.item_status',
                's.sku_id', 's.sku_code', 's.sku_name',
            ]);

        if (! empty($validated['item_id'])) {
            $query->where('pc.item_id', (string) $validated['item_id']);
        }
        if (! empty($validated['hardware_uid'])) {
            $query->where('pc.hardware_uid', strtoupper(trim((string) $validated['hardware_uid'])));
        }
        if (! empty($validated['status'])) {
            $query->where('pc.status', (string) $validated['status']);
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('pc.assigned_at')->forPage($page, $perPage)->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn (object $row): array => NfcTimestamp::record((array) $row))->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'request_id' => $request->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /** Thu hồi chip (rơi/hỏng/gỡ ra). Không đụng tồn kho, không xoá lịch sử. */
    public function deactivate(Request $request, string $physicalCodeId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['INACTIVE', 'LOST', 'DAMAGED'])],
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $actorId = $this->actorId();

        $after = DB::transaction(function () use ($physicalCodeId, $validated, $actorId, $request): object {
            $tag = DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->lockForUpdate()->first();
            if ($tag === null || strtoupper((string) $tag->carrier_type) !== 'NFC') {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chip NFC.');
            }
            if (strtoupper((string) $tag->status) !== 'ACTIVE') {
                ApiError::abort(409, 'NFC_TAG_INACTIVE', 'Chip NFC đã ở trạng thái thu hồi từ trước.', [
                    'status' => (string) $tag->status,
                ]);
            }

            DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->update([
                'status' => (string) $validated['status'],
                'is_primary' => false,
                'deactivated_at' => NfcTimestamp::write(now()),
                'deactivation_reason' => trim((string) $validated['reason']),
                'version' => (int) ($tag->version ?? 1) + 1,
                'updated_by' => $actorId,
                'updated_at' => NfcTimestamp::write(now()),
            ]);

            AuditLogger::record(
                action: 'REVOKE_ITEM_NFC',
                objectType: 'physical_code',
                objectId: $physicalCodeId,
                actorId: $actorId,
                requestId: $request->attributes->get('request_id'),
                reason: trim((string) $validated['reason']),
                beforeData: ['status' => (string) $tag->status],
                afterData: ['status' => (string) $validated['status'], 'stock_effect' => 'NONE'],
            );
            $this->invalidateReadCache();

            return DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->first();
        }, 3);

        return $this->respondSuccess([
            'nfc_status' => 'UNASSIGNED',
            'nfc' => $this->tagPayload($after),
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ], 'Đã thu hồi chip NFC; hiện vật có thể gán chip mới.');
    }

    /**
     * Lịch sử gán/thu hồi của MỘT chip, đọc từ nhật ký kiểm toán. Không có bảng lịch
     * sử NFC riêng: mỗi lần confirm/deactivate đã ghi audit với object_type =
     * physical_code, nên đọc lại đúng nguồn đó thay vì nhân đôi dữ liệu.
     */
    public function tagEvents(Request $request, string $physicalCodeId): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // id rác trên PostgreSQL ném 22P02 khi so cột uuid -> 500. Trả 404 như chip không có.
        if (! Str::isUuid($physicalCodeId)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chip NFC.');
        }
        $tag = DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->first();
        if ($tag === null || strtoupper((string) $tag->carrier_type) !== 'NFC') {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chip NFC.');
        }

        // Đọc qua quan hệ mà AuditLogger GHI vào (model AuditLog), không đọc thẳng bảng
        // audit_logs: trên PostgreSQL app ghi qua view `audit_log` với tên cột khác.
        $auditTable = (new AuditLog)->getTable();

        $page = DB::table($auditTable.' as a')
            ->leftJoin('app_user as u', 'u.user_id', '=', 'a.actor_id')
            ->where('a.object_type', 'physical_code')
            ->where('a.object_id', $physicalCodeId)
            ->orderByDesc('a.created_at')
            ->select([
                'a.id', 'a.action', 'a.result', 'a.reason', 'a.actor_id',
                'u.display_name as actor_name', 'a.before_data', 'a.after_data', 'a.created_at',
            ])
            ->paginate((int) ($validated['per_page'] ?? 20));

        $page->setCollection($page->getCollection()->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'action' => (string) $row->action,
            'result' => $row->result,
            'reason' => $row->reason,
            'actor_id' => $row->actor_id !== null ? (string) $row->actor_id : null,
            'actor_name' => $row->actor_name,
            'before_data' => $this->decodeAuditJson($row->before_data),
            'after_data' => $this->decodeAuditJson($row->after_data),
            'created_at' => NfcTimestamp::read($row->created_at),
        ]));

        return $this->respondPaginated($page);
    }

    private function decodeAuditJson(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    // ── Reservation ──────────────────────────────────────────────────────

    private function reservationTtl(): int
    {
        return max(30, (int) config('warehouse.nfc.write_reservation_ttl_seconds', 300));
    }

    /**
     * @return array{token: string, expires_at: string}
     */
    private function acquireReservation(string $itemId, string $actorId): array
    {
        $key = self::RESERVATION_KEY.$itemId;
        $ttl = $this->reservationTtl();
        $entry = [
            'token' => (string) Str::uuid(),
            'actor_id' => $actorId,
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ];

        // Cache::add là compare-and-set nguyên tử: chỉ MỘT máy giành được chỗ.
        if (Cache::add($key, $entry, $ttl)) {
            return ['token' => $entry['token'], 'expires_at' => $entry['expires_at']];
        }

        $held = Cache::get($key);
        if (is_array($held) && ($held['actor_id'] ?? null) === $actorId) {
            // Cùng người bấm lại (mất sóng, mở lại màn): gia hạn chỗ đang giữ thay
            // vì bắt họ chờ hết TTL của chính mình.
            $held['expires_at'] = now()->addSeconds($ttl)->toIso8601String();
            Cache::put($key, $held, $ttl);

            return ['token' => (string) $held['token'], 'expires_at' => (string) $held['expires_at']];
        }

        ApiError::abort(409, 'NFC_WRITE_RESERVED_BY_OTHER', 'Hiện vật đang được một máy khác chuẩn bị ghi chip; thử lại sau khi hết hạn giữ chỗ.', [
            'expires_at' => is_array($held) ? ($held['expires_at'] ?? null) : null,
        ]);
    }

    /**
     * Reservation là hàng rào TRẢI NGHIỆM, không phải hàng rào đúng đắn: cache có
     * thể bị flush, và confirm vẫn phải đúng khi đó. Vì vậy không có reservation
     * thì cho đi tiếp — chỉ chặn khi thấy rõ chỗ đang thuộc về người khác.
     */
    private function assertReservationUsable(string $itemId, string $actorId, ?string $token): void
    {
        $held = Cache::get(self::RESERVATION_KEY.$itemId);
        if (! is_array($held)) {
            return;
        }

        $sameActor = ($held['actor_id'] ?? null) === $actorId;
        $sameToken = $token !== null && $token !== '' && ($held['token'] ?? null) === $token;
        if ($sameActor || $sameToken) {
            return;
        }

        ApiError::abort(409, 'NFC_WRITE_RESERVED_BY_OTHER', 'Hiện vật đang được một máy khác giữ chỗ ghi chip; không thể xác nhận từ phiên này.', [
            'expires_at' => $held['expires_at'] ?? null,
        ]);
    }

    private function releaseReservation(string $itemId): void
    {
        Cache::forget(self::RESERVATION_KEY.$itemId);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function maxPayloadLength(): int
    {
        return (int) config('warehouse.product_qr.max_payload_length', 512);
    }

    /**
     * Resolve hiện vật từ một raw payload bất kỳ: composite QR/NFC, alias
     * `NFC-<uid>`, serial thuần hoặc code_value cũ. Cùng thứ tự ưu tiên với
     * MiniAppWarrantyController::resolveItem để mọi kênh nhìn một sự thật.
     */
    private function resolveItemByRawCode(string $rawCode): ?object
    {
        $rawCode = trim($rawCode);
        if ($rawCode === '') {
            return null;
        }

        if ($this->parser->looksComposite($rawCode)) {
            $payload = $this->parser->parse($rawCode);
            if (! $payload->isValid() || $payload->skuCode === null || $payload->itemUnique === null) {
                return null;
            }
            $sku = $this->identityResolver->requireActiveSkuByCode($payload->skuCode);
            $item = $this->identityResolver->findItemByUnique($payload->itemUnique);
            if ($item === null) {
                return null;
            }
            $this->identityResolver->assertItemMatchesSku($item, $sku, $payload);

            return $item;
        }

        return $this->identityResolver->findItemByUnique($rawCode);
    }

    /**
     * Chuỗi PHẢI ghi vào chip NFC = đúng payload QR của hiện vật (DEC-QR-001),
     * nhờ đó chip và QR thay thế được nhau ở mọi endpoint scan sẵn có.
     */
    private function nfcPayloadFor(object $item, ?object $sku): ?string
    {
        if ($sku === null || empty($sku->sku_code)) {
            return null;
        }

        return $this->buildPayload((string) $sku->sku_code, $this->itemUnique($item));
    }

    private function buildPayload(string $skuCode, ?string $itemUnique): ?string
    {
        if ($itemUnique === null || $itemUnique === '') {
            return null;
        }

        $prefix = strtoupper((string) config('warehouse.product_qr.composite_prefix', 'HN'));
        $version = (int) (config('warehouse.product_qr.supported_versions', [1])[0] ?? 1);

        return $prefix.$version.'|SKU='.strtoupper($skuCode).'|ITEM='.$itemUnique;
    }

    /**
     * `item_unique` phải là chuỗi mà ProductIdentityResolver::findItemByUnique
     * resolve ngược được: serial_number trước, rồi code_value không-composite của
     * mã vật lý QR/BARCODE đang ACTIVE (composite lồng composite không resolve được).
     */
    private function itemUnique(object $item): ?string
    {
        $serial = strtoupper(trim((string) ($item->serial_number ?? '')));
        if ($serial !== '') {
            return $serial;
        }

        $codeValues = DB::table('physical_codes')
            ->where('item_id', (string) $item->item_id)
            ->where('status', 'ACTIVE')
            ->whereIn('carrier_type', ['QR', 'BARCODE'])
            ->orderByDesc('is_primary')
            ->pluck('code_value');

        foreach ($codeValues as $codeValue) {
            $normalized = strtoupper(trim((string) $codeValue));
            if ($normalized !== '' && ! $this->parser->looksComposite($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Bản batch của {@see itemUnique()} cho một trang danh sách.
     *
     * @param  array<int, object>  $rows
     * @return array<string, string>
     */
    private function itemUniqueMap(array $rows): array
    {
        $map = [];
        $needFallback = [];
        foreach ($rows as $row) {
            $serial = strtoupper(trim((string) ($row->serial_number ?? '')));
            if ($serial !== '') {
                $map[(string) $row->item_id] = $serial;
            } else {
                $needFallback[] = (string) $row->item_id;
            }
        }

        if ($needFallback === []) {
            return $map;
        }

        $fallback = DB::table('physical_codes')
            ->whereIn('item_id', $needFallback)
            ->where('status', 'ACTIVE')
            ->whereIn('carrier_type', ['QR', 'BARCODE'])
            ->orderByDesc('is_primary')
            ->get(['item_id', 'code_value']);

        foreach ($fallback as $row) {
            $itemId = (string) $row->item_id;
            if (isset($map[$itemId])) {
                continue;
            }
            $normalized = strtoupper(trim((string) $row->code_value));
            if ($normalized !== '' && ! $this->parser->looksComposite($normalized)) {
                $map[$itemId] = $normalized;
            }
        }

        return $map;
    }

    /**
     * Bản batch của {@see activeNfcTagForItem()} cho một trang danh sách.
     *
     * @param  array<int, mixed>  $itemIds
     * @return array<string, object>
     */
    private function activeTagMap(array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_map('strval', $itemIds)));
        if ($itemIds === []) {
            return [];
        }

        $map = [];
        $rows = DB::table('physical_codes')
            ->whereIn('item_id', $itemIds)
            ->where('carrier_type', 'NFC')
            ->where('status', 'ACTIVE')
            ->orderBy('assigned_at')
            ->get();

        foreach ($rows as $row) {
            // orderBy tăng dần + ghi đè: dòng cuối (mới nhất) thắng, khớp
            // orderByDesc('assigned_at')->first() của bản đơn lẻ.
            $map[(string) $row->item_id] = $row;
        }

        return $map;
    }

    private function activeNfcTagForItem(string $itemId): ?object
    {
        return DB::table('physical_codes')
            ->where('item_id', $itemId)
            ->where('carrier_type', 'NFC')
            ->where('status', 'ACTIVE')
            ->orderByDesc('assigned_at')
            ->first();
    }

    /** @return array{0: bool, 1: string} */
    private function assignability(object $item, ?string $payload, ?object $existing): array
    {
        if ($payload === null) {
            return [false, 'ITEM_IDENTITY_NOT_RESOLVABLE'];
        }
        if ($existing !== null) {
            return [false, 'ITEM_ALREADY_HAS_NFC'];
        }
        if (in_array(strtoupper((string) $item->item_status), ['LOST', 'ADJUSTED_OUT'], true)) {
            return [false, 'ITEM_STATUS_NOT_TAGGABLE'];
        }

        return [true, 'ASSIGNABLE'];
    }

    /** @return array<string, mixed> */
    private function tagInsertRow(string $physicalCodeId, string $itemId, string $codeValue, string $hardwareUid, string $actorId): array
    {
        $timestamp = NfcTimestamp::write(now());

        return [
            'physical_code_id' => $physicalCodeId,
            'code_value' => $codeValue,
            'code_type' => 'ITEM',
            'carrier_type' => 'NFC',
            'hardware_uid' => $hardwareUid,
            'item_id' => $itemId,
            'container_id' => null,
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $itemId,
            'encoded_payload_version' => (string) (config('warehouse.product_qr.supported_versions', [1])[0] ?? 1),
            'is_primary' => true,
            'assigned_at' => $timestamp,
            'assigned_by' => $actorId,
            'status' => 'ACTIVE',
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /** @return array<string, mixed>|null */
    private function tagPayload(?object $tag): ?array
    {
        if ($tag === null) {
            return null;
        }

        return [
            'physical_code_id' => (string) $tag->physical_code_id,
            'code_value' => (string) $tag->code_value,
            'carrier_type' => (string) $tag->carrier_type,
            'hardware_uid' => $tag->hardware_uid !== null ? (string) $tag->hardware_uid : null,
            'status' => (string) $tag->status,
            'is_primary' => (bool) ($tag->is_primary ?? false),
            'assigned_at' => NfcTimestamp::read($tag->assigned_at ?? null),
            'created_at' => NfcTimestamp::read($tag->created_at ?? null),
            'updated_at' => NfcTimestamp::read($tag->updated_at ?? null),
            'deactivated_at' => NfcTimestamp::read($tag->deactivated_at ?? null),
            'replaced_by_code_id' => $tag->replaced_by_code_id ?? null,
            'version' => (int) ($tag->version ?? 1),
        ];
    }

    /** @return array<string, mixed> */
    private function itemPayload(object $item): array
    {
        return [
            'item_id' => (string) $item->item_id,
            'serial_number' => $item->serial_number ?? null,
            'item_status' => $item->item_status ?? null,
            'item_condition' => $item->item_condition ?? null,
            'container_id' => $item->container_id ?? null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function skuPayload(?object $sku): ?array
    {
        if ($sku === null) {
            return null;
        }

        return [
            'sku_id' => (string) $sku->sku_id,
            'sku_code' => (string) $sku->sku_code,
            'sku_name' => $sku->sku_name ?? null,
            'unit' => $sku->unit ?? null,
            'status' => $sku->status ?? null,
            'warranty_applicable' => isset($sku->warranty_applicable) ? (bool) $sku->warranty_applicable : null,
        ];
    }

    /**
     * Phiếu xuất đã Post gần nhất chứa hiện vật — trả lời "ai đang giữ sản phẩm
     * này". PII bên nhận chỉ trả thô cho actor có outbound.view; ngoài ra mask
     * theo §13.8 và khai cờ pii_masked để client không phải tự đoán.
     *
     * @return array<string, mixed>|null
     */
    private function lastIssuePayload(Request $request, string $itemId): ?array
    {
        $row = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->leftJoin('outbound_requests as r', 'r.id', '=', 'd.outbound_request_id')
            ->where('m.item_id', $itemId)
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->whereRaw('UPPER(d.status) = ?', ['POSTED'])
            ->orderByDesc('d.posted_at')
            ->select([
                'd.id as outbound_document_id', 'd.doc_no', 'd.doc_date', 'd.posted_at',
                'd.recipient_name', 'd.recipient_address', 'd.recipient_contact_phone',
                'r.request_no',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        $canSeeRaw = (bool) $request->user()?->hasPermission('outbound.view');
        $name = (string) ($row->recipient_name ?? '');
        $address = (string) ($row->recipient_address ?? '');
        $phone = (string) ($row->recipient_contact_phone ?? '');

        return [
            'outbound_document_id' => (string) $row->outbound_document_id,
            'doc_no' => $row->doc_no ?? null,
            'request_no' => $row->request_no ?? null,
            'doc_date' => $row->doc_date ?? null,
            'posted_at' => $row->posted_at ?? null,
            'recipient_name' => $canSeeRaw ? ($name !== '' ? $name : null) : ($name !== '' ? WarrantyPiiMasker::name($name) : null),
            'recipient_address' => $canSeeRaw ? ($address !== '' ? $address : null) : ($address !== '' ? WarrantyPiiMasker::address($address) : null),
            'recipient_contact_phone' => $canSeeRaw ? ($phone !== '' ? $phone : null) : ($phone !== '' ? WarrantyPiiMasker::phone($phone) : null),
            'pii_masked' => ! $canSeeRaw,
        ];
    }

    /** @return array<string, mixed>|null */
    private function activeWarrantyPayload(string $itemId): ?array
    {
        if ((! SchemaCache::hasTable('warranty_case') && ! SchemaCache::hasView('warranty_case'))
            || ! SchemaCache::hasRelationColumn('warranty_case', 'item_id')) {
            return null;
        }

        $case = DB::table('warranty_case')
            ->where('item_id', $itemId)
            ->whereNotIn('status', ['RETURNED', 'CANCELLED'])
            ->orderByDesc('created_at')
            ->select(['warranty_case_id', 'warranty_case_code', 'status', 'identification_status', 'created_at'])
            ->first();

        if ($case === null) {
            return null;
        }

        return [
            'warranty_case_id' => (string) $case->warranty_case_id,
            'warranty_case_code' => $case->warranty_case_code ?? null,
            'status' => $case->status ?? null,
            'identification_status' => $case->identification_status ?? null,
            'created_at' => $case->created_at ?? null,
        ];
    }
}
