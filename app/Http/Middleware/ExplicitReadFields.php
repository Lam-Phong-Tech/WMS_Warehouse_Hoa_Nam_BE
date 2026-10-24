<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use App\Support\ExplicitReadFields as Projection;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Support\P18BoxReadModel;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/** Outside envelope formatting, opt-in only. Never rewrites a native command receipt. */
final class ExplicitReadFields
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader(Projection::HEADER)) {
            return $next($request);
        }
        try {
            return $this->read($request, $next);
        } catch (HttpResponseException $error) {
            $response = $error->getResponse();
            if ($response instanceof JsonResponse) {
                $id = data_get($response->getData(true), 'meta.request_id');
                if (is_string($id) && preg_match('/^[a-zA-Z0-9\-._:\/=+]{2,255}$/D', $id)) {
                    $response->headers->set('X-Request-ID', $id);
                }
            }
            $response->setVary(['Authorization', 'Origin', Projection::HEADER], false);

            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        }
    }

    private function read(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || $request->header(Projection::HEADER) !== Projection::CONTRACT) {
            ApiError::abort(422, 'READ_FIELDS_CONTRACT_UNSUPPORTED', 'The explicit field contract supports declared GET reads only, never commands.');
        }
        if ($request->hasHeader('If-None-Match') || $request->hasHeader('If-Modified-Since')) {
            ApiError::abort(422, 'READ_FIELDS_CONDITIONAL_CACHE_UNSUPPORTED', 'Request a fresh no-store representation, not a cached legacy body.');
        }
        $response = $next($request); // Existing actor/session/warehouse/field ACL always runs first.
        if (! $response->isSuccessful()) {
            return $response;
        }
        $route = $request->route()?->uri() ?? '';
        $allowed = in_array($route, [
            'api/v1/auth/me', 'api/v1/mini-app/documents',
            'api/v1/notifications', 'api/v1/notifications/unread-count', 'api/v1/notifications/{id}',
            'api/v1/mini-app/dashboard/summary',
            'api/v1/mini-app/nfc-tags', 'api/v1/mini-app/nfc-tags/{physicalCodeId}',
            'api/v1/mini-app/nfc-tags/{physicalCodeId}/events',
            'api/v1/mini-app/inbound-documents', 'api/v1/mini-app/inbound-documents/{id}',
            'api/v1/mini-app/outbound-documents', 'api/v1/mini-app/outbound-documents/{id}',
            'api/v1/mini-app/warranty-cases', 'api/v1/mini-app/warranty-cases/{id}',
            'api/v1/mini-app/scan-sessions',
            'api/v1/mini-app/history/records', 'api/v1/mini-app/history/records/{recordId}',
            'api/v1/mini-app/history/summary', 'api/v1/mini-app/history/scan-sessions/{sessionId}',
            'api/v1/mini-app/work-shifts/current', 'api/v1/mini-app/work-shifts/{shiftId}/close-check',
            'api/v1/mini-app/work-shifts/{shiftId}/summary',
            'api/v1/mini-app/catalog/context', 'api/v1/mini-app/catalog/skus',
            'api/v1/mini-app/catalog/skus/{id}', 'api/v1/mini-app/catalog/skus/{id}/stock',
            'api/v1/mini-app/catalog/skus/{id}/gallery', 'api/v1/mini-app/catalog/skus/{id}/timeline',
            'api/v1/mini-app/catalog/items', 'api/v1/mini-app/catalog/items/{id}', 'api/v1/mini-app/catalog/items/{id}/timeline',
        ], true);
        $box = in_array($route, ['api/v1/containers', 'api/v1/containers/{id}'], true)
            && $request->header(P18BoxReadModel::HEADER) === P18BoxReadModel::CONTRACT;
        if (! $allowed && ! $box) {
            ApiError::abort(422, 'READ_FIELDS_ROUTE_UNSUPPORTED', 'No availability projection is defined for this route; native receipts/binary reads keep their owner contract.');
        }
        if (! $response instanceof JsonResponse) {
            ApiError::abort(503, 'READ_FIELDS_SOURCE_INVALID', 'The authorized owner did not return a JSON object.');
        }
        $body = $response->getData();
        if (! $body instanceof stdClass || ($body->success ?? null) !== true) {
            ApiError::abort(503, 'READ_FIELDS_SOURCE_INVALID', 'Owner success envelope is invalid; no empty fallback.');
        }
        if (! property_exists($body, 'data') || ($body->data === null
            && ($body->meta->shift_admission->current_state ?? null) !== 'NO_ACTIVE_SHIFT')) {
            ApiError::abort(503, 'READ_FIELDS_ROOT_UNAVAILABLE', 'Owner returned no root result without an authoritative no-active-shift state; no empty success is fabricated.');
        }
        $body = Projection::project($body);
        if ($response->headers->has('ETag')) {
            $etag = $response->headers->get('ETag');
            if (! is_string($etag) || trim($etag) === '') {
                ApiError::abort(503, 'READ_FIELDS_OWNER_PRECONDITION_INVALID', 'Owner supplied an invalid write precondition; no replacement ETag is invented.');
            }
            // The original literal still belongs to the write owner. It is not
            // a strong validator for these transformed bytes; never invent a new one.
            $body->meta->read_fields->owner_precondition = ['etag' => $etag, 'source' => 'EXACT_OWNER_RESPONSE_HEADER',
                'use' => 'ORIGINAL_OWNER_IF_MATCH_ONLY_NOT_HTTP_CACHE_VALIDATOR'];
        }
        foreach (['ETag', 'Last-Modified', 'Content-Length', 'Content-MD5', 'Digest'] as $name) {
            $response->headers->remove($name);
        }
        $response->setVary(Projection::HEADER, false);

        return $response->setData($body)->header('X-Read-Fields-Contract', Projection::CONTRACT)->header('Cache-Control', 'private, no-store');
    }
}
