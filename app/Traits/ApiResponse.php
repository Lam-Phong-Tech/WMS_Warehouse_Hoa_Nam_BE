<?php

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

trait ApiResponse
{
    protected function respondSuccess($data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        // A few legacy callers may pass a paginator through respondSuccess().
        // Normalize it here so the convention middleware does not need a second
        // JSON decode/encode pass merely to discover pagination metadata.
        if ($data instanceof LengthAwarePaginator) {
            return $this->respondPaginated($data, $message);
        }

        $payload = ['success' => true];
        if ($message !== null) {
            $payload['message'] = $message;
        }
        $payload['data'] = $data;
        $payload['meta'] = [
            'request_id' => $this->currentRequestId(),
            'timestamp' => now()->toIso8601String(),
        ];

        return $this->standardJsonResponse($payload, $code);
    }

    protected function currentRequestId(): string
    {
        $requestId = request()?->attributes->get('request_id');

        return is_string($requestId) ? $requestId : (string) Str::uuid();
    }

    protected function respondCreated($data = null, ?string $message = null): JsonResponse
    {
        return $this->respondSuccess($data, $message, 201);
    }

    protected function respondPaginated(LengthAwarePaginator $paginator, ?string $message = null): JsonResponse
    {
        $payload = ['success' => true];
        if ($message !== null) {
            $payload['message'] = $message;
        }
        $payload['data'] = $paginator->items();
        $payload['meta'] = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'request_id' => $this->currentRequestId(),
            'timestamp' => now()->toIso8601String(),
        ];
        $payload['links'] = [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        return $this->standardJsonResponse($payload, 200);
    }

    protected function respondError(?string $message = null, $errors = [], int $code = 400, ?string $errorCode = null, array $extra = []): JsonResponse
    {
        $defaultErrorCodes = [
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'ACCESS_DENIED',
            404 => 'RESOURCE_NOT_FOUND',
            409 => 'STATE_CONFLICT',
            412 => 'STALE_VERSION',
            422 => 'BUSINESS_RULE_ERROR',
            428 => 'PRECONDITION_REQUIRED',
            500 => 'INTERNAL_SERVER_ERROR',
        ];

        $codeStr = $errorCode ?? ($defaultErrorCodes[$code] ?? 'ERROR');
        $payload = [
            'success' => false,
            'message' => $message,
            'error_code' => $codeStr,
            'errors' => $errors,
            'error' => ['code' => $codeStr, 'details' => $errors],
            'meta' => [
                'request_id' => $this->currentRequestId(),
                'timestamp' => now()->toIso8601String(),
            ],
        ];

        return $this->standardJsonResponse(array_merge($payload, $extra), $code);
    }

    protected function respondNotFound(?string $message = null): JsonResponse
    {
        return $this->respondError($message ?? 'Không tìm thấy dữ liệu', [], 404, 'RESOURCE_NOT_FOUND');
    }

    protected function respondConflict(?string $message = null, $errors = [], ?string $errorCode = null, array $extra = []): JsonResponse
    {
        return $this->respondError($message, $errors, 409, $errorCode ?? 'STATE_CONFLICT', $extra);
    }

    /**
     * Mark envelopes already produced by this trait. ApiConventionMiddleware
     * consumes and removes this private transport marker so it can skip an
     * otherwise redundant json_decode() + json_encode() cycle.
     */
    private function standardJsonResponse(array $payload, int $code): JsonResponse
    {
        $response = response()->json($payload, $code);
        $response->headers->set('X-WMS-Envelope-Ready', '1');

        return $response;
    }
}
