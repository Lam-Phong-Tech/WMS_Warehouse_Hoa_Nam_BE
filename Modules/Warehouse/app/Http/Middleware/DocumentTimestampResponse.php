<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use App\Support\DocumentTimestamp;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** Explicit document reads only; never edits an owner mutation/receipt response. */
final class DocumentTimestampResponse
{
    public const READ_ROUTES = [
        'api/v1/mini-app/inbound-documents',
        'api/v1/mini-app/inbound-documents/{id}',
        'api/v1/mini-app/outbound-documents',
        'api/v1/mini-app/outbound-documents/{id}',
        'api/v1/mini-app/inbound/documents/{id}',
        'api/v1/mini-app/outbound/documents/{id}',
        'api/v1/mini-app/outbound/sources',
        'api/v1/mini-app/outbound/sources/{id}',
        'api/v1/mini-app/warranty-cases',
        'api/v1/mini-app/warranty-cases/{id}',
    ];

    private const FIELDS = ['created_at', 'updated_at', 'submitted_at', 'approved_at', 'posted_at',
        'closed_at', 'reversed_at', 'rejected_at', 'cancelled_at', 'received_at', 'returned_at', 'expires_at'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->isMethodSafe() || ! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response;
        }
        $body = $response->getData(true);
        if (($body['success'] ?? null) !== true || ! is_array($body['data'] ?? null)) {
            return $response;
        }
        try {
            $data = $body['data'];
            if (array_is_list($data)) {
                $data = array_map(self::record(...), $data);
            } elseif (isset($data['document']) && is_array($data['document'])) {
                $data['document'] = self::record($data['document']);
            } else {
                $data = self::record($data);
            }
            $body['data'] = $data;
            $body['meta']['timestamp_contract'] = DocumentTimestamp::CONTRACT;
            $body['meta']['timestamps'] = DocumentTimestamp::metadata();
        } catch (InvalidArgumentException) {
            ApiError::abort(503, 'DOCUMENT_TIMESTAMP_INVALID', 'Thời gian nguồn không hợp lệ; không thay bằng thời gian hiện tại.');
        }

        return $response->setData($body);
    }

    private static function record(array $record): array
    {
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $record)) {
                $record[$field] = DocumentTimestamp::utc($record[$field]);
            }
        }

        foreach (['created', 'posted'] as $milestone) {
            if (isset($record['p12']['timeline']['milestones'][$milestone])
                && array_key_exists('occurred_at', $record['p12']['timeline']['milestones'][$milestone])) {
                $record['p12']['timeline']['milestones'][$milestone]['occurred_at'] = DocumentTimestamp::utc(
                    $record['p12']['timeline']['milestones'][$milestone]['occurred_at'],
                );
            }
        }

        return $record;
    }
}
