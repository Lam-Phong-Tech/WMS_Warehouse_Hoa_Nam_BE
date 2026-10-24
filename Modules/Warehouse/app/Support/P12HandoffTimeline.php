<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Http\Request;

/** Opt-in read of persisted handoff time, never validation/receipt-derived CHECK evidence. */
final class P12HandoffTimeline
{
    public const HEADER = 'X-P12-Timeline-Contract';

    public const CONTRACT = 'p12-timeline-handoff-v1';

    public static function decorate(Request $request, array $body, string $source, string $mode): array
    {
        if (! $request->hasHeader(self::HEADER)) {
            return $body;
        }
        if ($request->header(self::HEADER) !== self::CONTRACT || $mode !== 'DETAIL'
            || ! in_array($source, ['INBOUND', 'OUTBOUND'], true)) {
            ApiError::abort(422, 'P12_TIMELINE_CONTRACT_UNSUPPORTED', 'Contract timeline chỉ hỗ trợ GET/HEAD chi tiết phiếu nhập hoặc xuất hiện hữu.');
        }
        $data = $body['data'] ?? [];
        $identity = $data['p12']['identity'] ?? [];
        if (! is_string($identity['id'] ?? null) || ($data['id'] ?? null) !== $identity['id']
            || strtolower((string) $request->route('id')) !== $identity['id']
            || ($identity['source'] ?? null) !== $source || ! is_string($identity['warehouse_id'] ?? null)) {
            ApiError::abort(503, 'P12_TIMELINE_IDENTITY_INVALID', 'Không xác minh được identity phiếu cho timeline.');
        }
        $status = $data['p13']['current_handoff_availability'] ?? 'SOURCE_UNAVAILABLE';
        if (! in_array($status, ['RECORDED', 'NOT_ESTABLISHED'], true)) {
            ApiError::abort(503, 'P12_TIMELINE_SOURCE_UNAVAILABLE', 'Nguồn handoff không sẵn sàng; không dựng mốc gửi Web.');
        }
        $milestone = [
            'availability' => 'NOT_RECORDED', 'occurred_at' => null, 'timestamp_source' => null, 'event_id' => null,
            'actor' => null, 'actor_status' => 'NOT_RECORDED_IN_HANDOFF_SOURCE',
            'description' => null, 'description_status' => 'NOT_RECORDED_IN_HANDOFF_SOURCE',
            'evidence_kind' => 'PERSISTED_SUBMISSION_INTENT_NOT_CURRENT_WAITING_OR_DELIVERY_ACK',
        ];
        if ($status === 'RECORDED') {
            $handoff = $data['p13']['current_handoff'] ?? [];
            $time = $handoff['submitted_at'] ?? null;
            if (($handoff['contract'] ?? null) !== P13WebPostHandoff::CONTRACT
                || ($handoff['warehouse_id'] ?? null) !== $identity['warehouse_id']
                || ($handoff['source'] ?? null) !== P13WebPostHandoff::TABLE.'+stock_documents.status'
                || ! is_int($handoff['document_version_at_record'] ?? null) || $handoff['document_version_at_record'] < 1
                || ! is_string($time) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D', $time)) {
                ApiError::abort(503, 'P12_TIMELINE_SOURCE_INVALID', 'Handoff thiếu identity, version hoặc timestamp được xác minh.');
            }
            $milestone = array_replace($milestone, ['availability' => 'RECORDED', 'occurred_at' => $time,
                'timestamp_source' => P13WebPostHandoff::TABLE.'.submitted_at',
                'source_record' => ['table' => P13WebPostHandoff::TABLE, 'document_id' => $identity['id'],
                    'warehouse_id' => $identity['warehouse_id'], 'document_version_at_record' => $handoff['document_version_at_record']]]);
        }
        $body['data']['p12']['timeline']['contract'] = self::CONTRACT;
        $body['data']['p12']['timeline']['milestones']['sent_to_web'] = $milestone;
        $body['meta']['p12']['timeline_contract'] = self::CONTRACT;

        return $body;
    }
}
