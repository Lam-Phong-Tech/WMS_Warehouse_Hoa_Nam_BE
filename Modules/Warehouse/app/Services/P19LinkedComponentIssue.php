<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/** Opt-in batch contract inside the existing P03 transaction, not another stock writer. */
final class P19LinkedComponentIssue
{
    public const VERSION = 'p19-component-issue-linked-v1';

    public const HEADER = 'X-Component-Issue-Contract';

    /** Match the existing staging owner cap; P03 SAVE capacity is not Post capacity. */
    public const MAX_RECORD_ITEMS = 500;

    /** Called only after P03 has locked and checked actor/session/warehouse/draft/version. */
    public function handle(Request $request, Closure $next): Response
    {
        $draft = DB::table(MiniAppDraftService::TABLE)
            ->where('document_id', strtolower((string) $request->header('X-Draft-ID')))->first();
        $form = $draft === null ? [] : json_decode($draft->form, true, 512, JSON_THROW_ON_ERROR);
        $tagged = is_array($form) && array_key_exists('p19', $form);
        if (! $request->hasHeader(self::HEADER) && ! $tagged) {
            return $next($request); // Preserve unrelated pre-existing linked callers.
        }
        if ($request->header(self::HEADER) !== self::VERSION) {
            ApiError::abort(409, 'P19_CONTRACT_REQUIRED', 'Bản nháp P19 cần đúng contract xuất linh kiện đã liên kết.');
        }
        if ($draft === null || $draft->operation !== 'COMPONENT_ISSUE' || ! $tagged || ! is_array($form['p19'])) {
            ApiError::abort(409, 'P19_DRAFT_SNAPSHOT_REQUIRED', 'Lưu snapshot P19 vào bản nháp trước khi xác nhận xuất.');
        }
        if ($request->query() !== [] || ! $request->isJson()) {
            ApiError::abort(422, 'P19_PAYLOAD_INVALID', 'P19 record chỉ nhận JSON body, không nhận query.');
        }
        if ($request->hasHeader('If-Match')) {
            ApiError::abort(422, 'P19_VERSION_HEADER_INVALID', 'Record dùng X-Draft-Version; If-Match chỉ dùng khi lưu bản nháp.');
        }
        $body = $request->json()->all();
        $this->keys($body, ['warehouse_id', 'warranty_case_id', 'post_immediately', 'document_date', 'note', 'items']);
        $input = Validator::make($body, [
            'warehouse_id' => ['required', 'uuid'], 'warranty_case_id' => ['required', 'uuid'],
            'post_immediately' => ['required', 'boolean'], 'document_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_RECORD_ITEMS],
            'items.*' => ['required', 'array:raw_code,quantity,scan_source'],
            'items.*.raw_code' => ['required', 'string', 'max:512'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.scan_source' => ['required', 'in:CAMERA,SCANNER,HARDWARE,MANUAL'],
        ])->validate();
        if (($input['post_immediately'] ?? null) !== true) {
            ApiError::abort(422, 'P19_POST_CONFIRMATION_REQUIRED', 'P19 record là xác nhận Post; lưu dở bằng P03 SAVE, không record false.');
        }
        $metadata = $form['p19'];
        $this->keys($metadata, ['contract', 'warranty_case_id', 'document_date', 'note']);
        if (($metadata['contract'] ?? null) !== self::VERSION
            || ($metadata['warranty_case_id'] ?? null) !== $input['warranty_case_id']
            || ($metadata['document_date'] ?? null) !== ($input['document_date'] ?? null)
            || ($metadata['note'] ?? null) !== ($input['note'] ?? null)
            || $draft->warehouse_id !== $input['warehouse_id']) {
            ApiError::abort(409, 'P19_DRAFT_METADATA_MISMATCH', 'Hồ sơ, kho hoặc metadata không khớp snapshot đã lưu.');
        }
        $saved = json_decode($draft->codes, true, 512, JSON_THROW_ON_ERROR);
        if ($this->rows($saved) !== $this->rows($input['items'])) {
            ApiError::abort(409, 'P19_DRAFT_ITEMS_MISMATCH', 'Mã, số lượng, thứ tự hoặc nguồn quét không khớp snapshot đã lưu.');
        }
        // Lock the persisted case before invoking the owner. No cross-warehouse
        // fallback from a customer's text, a SKU or the user's first warehouse.
        $case = DB::table('warranty_case')->where('warranty_case_id', $input['warranty_case_id'])->lockForUpdate()->first();
        $owner = DB::table('warranty_case_warehouse_owners')->where('warranty_case_id', $input['warranty_case_id'])
            ->where('warehouse_id', $draft->warehouse_id)->lockForUpdate()->first();
        if ($case === null || $owner === null) {
            ApiError::abort(404, 'WARRANTY_CASE_NOT_FOUND', 'Không có hồ sơ bảo hành thuộc kho của bản nháp.');
        }
        if (! in_array($case->status, ['CHECKING', 'REPAIRING'], true)) {
            ApiError::abort(422, 'WARRANTY_CASE_STATUS_NOT_ALLOWED', 'Chỉ hồ sơ đang kiểm tra hoặc đang sửa chữa mới được xuất linh kiện.');
        }
        $response = $next($request);
        if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response; // P03 rolls back non-success owner writes.
        }

        return $this->postedResponse($request, $response, $draft, $input);
    }

    /** Preserve exactly the requested quantity and order. No inferred stock availability. */
    private function rows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'raw_code' => $row['raw_code'] ?? null,
            'quantity' => $row['quantity'] ?? null,
            'scan_source' => $row['scan_source'] ?? null,
        ], $rows);
    }

    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            ApiError::abort(422, 'P19_UNEXPECTED_FIELD', 'Có trường ngoài contract P19; không gửi ID/số phiếu hoặc quyền do client suy ra.');
        }
    }

    /** Build the historical result BEFORE P03 commits the document, stock and receipt together. */
    private function postedResponse(Request $request, JsonResponse $response, object $draft, array $input): JsonResponse
    {
        $body = $response->getData(true);
        $id = data_get($body, 'data.component_issue_document_id');
        $document = is_string($id) ? DB::table('component_issue_documents')->where('id', $id)->first() : null;
        if ($document === null || $document->status !== 'POSTED' || $document->posted_at === null
            || $document->warehouse_id !== $draft->warehouse_id || $document->warranty_case_id !== $input['warranty_case_id']
            || (string) $document->posted_by !== (string) $draft->actor_id) {
            ApiError::abort(502, 'P19_POST_RESULT_INVALID', 'Không xác minh được kết quả Post; toàn bộ giao dịch được hoàn tác.');
        }
        $lines = DB::table('component_issue_lines')->where('component_issue_document_id', $id)->orderBy('line_no')->get();
        $movements = DB::table('stock_movements')->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')
            ->where('source_id', $id)->where('movement_type', 'COMPONENT_ISSUE')->orderBy('source_line_id')->orderBy('id')->get();
        $total = 0;
        foreach ($lines as $line) {
            $effects = $movements->where('source_line_id', $line->id);
            if ((int) $line->required_qty <= 0 || (int) $line->required_qty !== (int) $line->scanned_qty
                || $effects->count() !== 1 || (int) $effects->sum('qty_delta') !== -(int) $line->required_qty
                || $effects->first()->warehouse_id !== $draft->warehouse_id
                || $effects->first()->sku_id !== $line->sku_id
                || (string) $effects->first()->created_by !== (string) $document->posted_by) {
                ApiError::abort(502, 'P19_POST_RESULT_INVALID', 'Movement không khớp dòng/số lượng/kho; giao dịch được hoàn tác.');
            }
            $total += (int) $line->required_qty;
        }
        if ($lines->isEmpty() || $movements->count() !== $lines->count()
            || $total !== array_sum(array_column($input['items'], 'quantity'))) {
            ApiError::abort(502, 'P19_POST_RESULT_INVALID', 'Tổng Post không khớp snapshot; giao dịch được hoàn tác.');
        }
        $body['data']['p19_result'] = [
            'contract' => self::VERSION, 'result_kind' => 'HISTORICAL_POST_RESULT',
            'draft_id' => $draft->document_id, 'scan_session_id' => $draft->scan_session_id,
            'actor_id' => $draft->actor_id, 'auth_session_id' => $draft->auth_session_id,
            'warehouse_id' => $document->warehouse_id, 'warranty_case_id' => $document->warranty_case_id,
            'business_document_id' => $document->id, 'document_no' => $document->component_issue_no,
            'document_version' => (int) $document->version, 'status' => $document->status,
            'posted_at' => $this->instant($document->posted_at), 'posted_by' => $document->posted_by,
            'stock_effect' => 'DECREASE', 'quantity_total' => $total,
            'operation_key' => $request->attributes->get('p19.original_operation_key'),
            'trace_request_id' => $request->attributes->get('request_id'),
            'lines' => $lines->map(static fn (object $line): array => [
                'line_id' => $line->id, 'line_no' => (int) $line->line_no, 'sku_id' => $line->sku_id,
                'required_qty' => (int) $line->required_qty, 'scanned_qty' => (int) $line->scanned_qty,
                'version' => (int) $line->version,
            ])->all(),
            'movements' => $movements->map(fn (object $movement): array => [
                'movement_id' => $movement->id, 'line_id' => $movement->source_line_id,
                'sku_id' => $movement->sku_id, 'item_id' => $movement->item_id,
                'qty_delta' => (int) $movement->qty_delta, 'occurred_at' => $this->instant($movement->occurred_at),
            ])->all(),
            'current_state_read' => '/api/v1/mini-app/warranty-component-issues/'.$document->id,
        ];

        return $response->setData($body)->header('Cache-Control', 'private, no-store');
    }

    private function instant(string $value): string
    {
        return Carbon::parse($value, (string) config('app.timezone', 'UTC'))->toIso8601String();
    }
}
