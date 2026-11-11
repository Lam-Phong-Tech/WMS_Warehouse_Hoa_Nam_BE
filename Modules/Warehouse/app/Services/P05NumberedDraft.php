<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;

/** Opt-in representation over the existing P03 owner; form is never executed. */
final class P05NumberedDraft
{
    public const HEADER = 'X-Outbound-Numbering-Contract';

    public const CONTRACT = 'p05-save-numbering-v1';

    public const REVISION = 'p05-save-numbering-r1';

    public function __construct(private readonly MiniAppDraftService $drafts, private readonly OutboundDocumentNumber $numbers) {}

    public static function allowed(User $actor, string $warehouse, string $action): bool
    {
        $roles = $actor->activeRoles()->where(fn ($q) => $q->whereNull('user_role.scope_ref')->orWhere('user_role.scope_ref', '')->orWhere('user_role.scope_ref', $warehouse))
            ->with(['permissions' => fn ($q) => $q->where('permission.status', 'ACTIVE')])->get();
        $rights = $roles->pluck('permissions')->flatten()->merge($actor->grantedPermissions()->where('permission.status', 'ACTIVE')->get())
            ->pluck('permission_key')->unique()->all();
        $has = fn (string $key): bool => in_array($key, $rights, true);

        return $actor->status === 'ACTIVE' && match ($action) {
            'read' => $has('outbound.view'),
            'cancel' => $has('outbound.cancel'),
            default => ($has('outbound.request.manual_create') || $has('outbound.import')) && $has('outbound.scan'),
        };
    }

    public static function authorize(Request $request, string $warehouse, string $action): void
    {
        $actor = $request->user() instanceof User ? User::whereKey($request->user()->user_id)->first() : null;
        if ($actor === null || ! self::allowed($actor, $warehouse, $action)) {
            ApiError::abort(403, 'P05_NUMBERING_DENIED', 'Không có grant thực cho thao tác PX của kho này.');
        }
    }

    public function capability(Request $request, ?string $warehouse): array
    {
        return ['contract' => self::CONTRACT, 'revision' => self::REVISION, 'mode' => 'MANUAL_CREATE_ONLY',
            'schema_ready' => $this->numbers->ready(),
            'allowed' => $warehouse !== null && $this->numbers->ready() && self::allowed($request->user(), $warehouse, 'save'),
            'save' => ['method' => 'PUT', 'path' => '/api/v1/mini-app/drafts/{draft_id}', 'operation' => 'OUTBOUND',
                'headers' => [self::HEADER => self::CONTRACT], 'response_field' => 'data.document.document_no'],
            'format' => 'PX-YYYYMMDDHHmmss', 'timezone' => 'Asia/Ho_Chi_Minh', 'cancel_policy' => 'RETAIN_NEVER_REUSE',
            'source_linked_policy' => 'KEEP_SOURCE_DOCUMENT_NO', 'stock_effect' => 'NONE'];
    }

    public function save(Request $request, string $id): JsonResponse
    {
        $this->request($request, true);

        return DB::transaction(function () use ($request, $id): JsonResponse {
            $response = $this->drafts->save($request, $id);
            $body = $response->getData(true);
            $document = $body['data']['document'];
            $document['form'] = $response->getData()->data->document->form;
            self::authorize($request, $document['warehouse_id'], 'save');
            if (DB::table('stock_documents')->where('id', $id)->exists()) {
                ApiError::abort(409, 'P05_NUMBER_SOURCE_LINKED_UNSUPPORTED', 'Không cấp PX mới cho định danh phiếu Web.');
            }
            $replay = $response->headers->get('Idempotent-Replay') === 'true';
            if (! $replay) {
                $this->numbers->reserve($id);
            }
            $body['data']['document'] = $this->document($document, $replay);
            $this->meta($body);
            if (! $replay) {
                DB::table(MiniAppDraftService::RECEIPTS)->where('document_id', $id)
                    ->where('request_id', $body['data']['receipt']['request_id'])->update(['response_body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            }

            return $response->setData($body);
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->request($request);

        return DB::transaction(function () use ($request, $id): JsonResponse {
            $response = $this->drafts->show($request, $id);
            $body = $response->getData(true);
            $body['data']['form'] = $response->getData()->data->form;
            self::authorize($request, $body['data']['warehouse_id'], 'read');
            $body['data'] = $this->document($body['data']);
            $this->meta($body);

            return $response->setData($body);
        });
    }

    public function index(Request $request): JsonResponse
    {
        $this->request($request);

        return DB::transaction(function () use ($request): JsonResponse {
            $response = $this->drafts->index($request);
            $body = $response->getData(true);
            self::authorize($request, $body['meta']['warehouse_id'], 'read');
            $rawDrafts = $response->getData()->data;
            foreach ($body['data'] as $index => &$draft) {
                $draft['form'] = $rawDrafts[$index]->form;
            }
            unset($draft);
            $body['data'] = array_map(fn (array $d): array => $this->document($d), $body['data']);
            $this->meta($body);

            return $response->setData($body);
        });
    }

    public function reconcile(Request $request, string $id, string $key): JsonResponse
    {
        $this->request($request);

        return DB::transaction(function () use ($request, $id, $key): JsonResponse {
            $response = $this->drafts->reconcile($request, $id, $key);
            $body = $response->getData(true);
            $body['data']['response'] = $response->getData()->data->response;
            self::authorize($request, $body['data']['warehouse_id'], 'read');
            if ($body['data']['document'] !== null) {
                $body['data']['document']['form'] = $response->getData()->data->document->form;
                $body['data']['document'] = $this->document($body['data']['document']);
            }
            if ($body['data']['outcome'] === 'UNKNOWN') {
                $body['data']['retry'] = 'CHECK_ONLY';
            }
            $actor = User::whereKey($request->user()->user_id)->first();
            $body['data']['cancel_allowed'] = $body['data']['cancel_allowed'] && $actor !== null
                && self::allowed($actor, $body['data']['warehouse_id'], 'cancel');
            $this->meta($body);

            return $response->setData($body);
        });
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->request($request, true);

        return DB::transaction(function () use ($request, $id): JsonResponse {
            $response = $this->drafts->cancel($request, $id);
            $body = $response->getData(true);
            $body['data']['document']['form'] = $response->getData()->data->document->form;
            self::authorize($request, $body['data']['document']['warehouse_id'], 'cancel');
            $body['data']['document'] = $this->document($body['data']['document']);
            $this->meta($body);

            return $response->setData($body);
        });
    }

    private function request(Request $request, bool $body = false): void
    {
        if ($request->header(self::HEADER) !== self::CONTRACT) {
            ApiError::abort(409, 'P05_NUMBERING_CONTRACT_UNSUPPORTED', 'Cần đúng contract cấp PX khi SAVE.');
        }
        if (! $this->numbers->ready()) {
            ApiError::abort(503, 'P05_NUMBERING_SCHEMA_UNAVAILABLE', 'Nguồn giữ số PX chưa sẵn sàng.');
        }
        $input = $body ? $request->json()->all() : $request->query();
        if (($input['operation'] ?? null) !== 'OUTBOUND') {
            ApiError::abort(422, 'P05_NUMBERING_OPERATION_REQUIRED', 'Contract này chỉ dành cho OUTBOUND tạo mới.');
        }
        $form = $input['form'] ?? [];
        foreach (['p05_source', 'source_document_id', 'source_version', 'source_id'] as $field) {
            if (array_key_exists($field, $input) || (is_array($form) && array_key_exists($field, $form))) {
                ApiError::abort(409, 'P05_NUMBER_SOURCE_LINKED_UNSUPPORTED', 'Phiếu Web giữ nguyên doc_no, không cấp PX thay thế.');
            }
        }
    }

    private function document(array $draft, bool $historical = false): array
    {
        $number = $historical ? ($draft['document_no'] ?? null) : $this->numbers->forDraft($draft['document_id']);
        $draft['document_no'] = $number;
        $draft['numbering_status'] = $number === null ? 'AWAITING_EXPLICIT_SAVE' : 'ASSIGNED';

        return $draft;
    }

    private function meta(array &$body): void
    {
        $body['meta']['numbering_contract'] = self::CONTRACT;
        $body['meta']['numbering_revision'] = self::REVISION;
    }
}
