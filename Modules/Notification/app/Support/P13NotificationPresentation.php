<?php

namespace Modules\Notification\Support;

use Illuminate\Support\Str;
use Modules\Notification\Models\NotificationRecipient;

/** Event identity projection only. A notification never authorizes its subject. */
final class P13NotificationPresentation
{
    public const REVISION = 'p13-notifications-r2';

    /** Only event/subject pairs emitted by existing canonical listeners are mapped. */
    public const EVENTS = [
        'inbound.created' => ['stock_document', 'INBOUND', 'inbound-documents'],
        'inbound.scan_started' => ['stock_document', 'INBOUND', 'inbound-documents'],
        'inbound.completed' => ['stock_document', 'INBOUND', 'inbound-documents'],
        'inbound.cancelled' => ['stock_document', 'INBOUND', 'inbound-documents'],
        'inbound.sku_info_pending' => ['stock_document', 'INBOUND', 'inbound-documents'],
        'outbound.created' => ['stock_document', 'OUTBOUND', 'outbound-documents'],
        'outbound.scan_started' => ['stock_document', 'OUTBOUND', 'outbound-documents'],
        'outbound.posted' => ['stock_document', 'OUTBOUND', 'outbound-documents'],
        'outbound.cancelled' => ['stock_document', 'OUTBOUND', 'outbound-documents'],
        'warranty.case_created' => ['warranty_case', 'WARRANTY', 'warranty-cases'],
        'warranty.status_changed' => ['warranty_case', 'WARRANTY', 'warranty-cases'],
    ];

    public static function subject(NotificationRecipient $recipient): array
    {
        $notification = $recipient->notification;
        $result = [
            'requires_subject_revalidation' => true,
            'web_url' => null,
            'web_url_status' => 'NOT_CONFIGURED',
            'mapping_revision' => self::REVISION,
            'mapping_status' => 'UNMAPPED_EVENT',
            'business_type' => null,
            'business_id' => null,
            'warehouse_id' => null,
            'identity_source' => null,
            'read_target' => null,
            'navigation_status' => 'NOT_AVAILABLE',
        ];
        if ($notification === null) {
            return array_replace($result, ['mapping_status' => 'NOTIFICATION_UNAVAILABLE']);
        }
        $mapping = self::EVENTS[$notification->event_key] ?? null;
        if ($mapping === null) {
            return $result;
        }
        [$subjectType, $businessType, $path] = $mapping;
        $payload = is_array($notification->payload) ? $notification->payload : [];
        $id = self::uuid($notification->subject_id);
        $payloadId = self::uuid($payload[$subjectType === 'stock_document' ? 'document_id' : 'case_id'] ?? null);
        $expectedType = $businessType === 'INBOUND' ? 'receipt' : 'issue';
        if ($notification->source_module !== 'warehouse' || $notification->subject_type !== $subjectType
            || $id === null || $payloadId !== $id
            || ($subjectType === 'stock_document' && array_key_exists('type', $payload) && $payload['type'] !== $expectedType)) {
            return array_replace($result, ['mapping_status' => 'SUBJECT_IDENTITY_MISMATCH']);
        }
        $warehouse = self::uuid($payload['warehouse_id'] ?? null);
        $result = array_replace($result, [
            'mapping_status' => 'MAPPED',
            'business_type' => $businessType,
            'business_id' => $id,
            'warehouse_id' => $warehouse,
            'identity_source' => 'PERSISTED_NOTIFICATION_EVENT_AND_PAYLOAD',
            'navigation_status' => $warehouse === null ? 'WAREHOUSE_SOURCE_REQUIRED' : 'OWNER_REVALIDATION_REQUIRED',
        ]);
        // Do not fill a missing historical warehouse with the currently selected one.
        // In particular, old warranty notifications have no warehouse source.
        if ($warehouse !== null) {
            $result['read_target'] = [
                'method' => 'GET',
                'path' => '/api/v1/mini-app/'.$path.'/'.$id,
                'query' => ['warehouse_id' => $warehouse],
            ];
        }

        return $result;
    }

    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }
}
