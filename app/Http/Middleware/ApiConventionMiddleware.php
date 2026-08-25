<?php

namespace App\Http\Middleware;

use App\Support\IdempotencyStore;
use App\Support\UserFacingMessageLocalizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

class ApiConventionMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = $request->header('X-Request-ID');
        if (empty($requestId) || ! is_string($requestId) || ! preg_match('/^[a-zA-Z0-9\-\._:\/=\+]{2,255}$/', $requestId)) {
            $requestId = (string) Str::uuid();
            $request->headers->set('X-Request-ID', $requestId);
        }

        $request->attributes->set('request_id', $requestId);
        if (class_exists(Context::class)) {
            Context::add('request_id', $requestId);
        }

        if ($request->isJson()) {
            $requestContent = $request->getContent();
            if (trim($requestContent) !== '') {
                json_decode($requestContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Dữ liệu JSON không hợp lệ hoặc sai định dạng yêu cầu.',
                        'error_code' => 'MALFORMED_JSON',
                        'errors' => [],
                        'error' => [
                            'code' => 'MALFORMED_JSON',
                            'details' => [],
                        ],
                        'meta' => [
                            'request_id' => $requestId,
                            'timestamp' => now()->toIso8601String(),
                        ],
                    ], 400)->header('X-Request-ID', $requestId);
                }
            }
        }

        $response = $next($request);

        if ($request->is('docs') || $request->is('docs/*') || $request->is('api/documentation')) {
            return $response;
        }

        // Controllers using App\Traits\ApiResponse have already produced the
        // canonical envelope with request_id/timestamp. Avoid decoding and
        // re-encoding the entire JSON body a second time on the hottest path.
        if ($response->headers->get('X-WMS-Envelope-Ready') === '1') {
            $response->headers->remove('X-WMS-Envelope-Ready');
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            $statusCode = $response->getStatusCode();
            $content = $response->getContent();

            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {

                if ($statusCode >= 200 && $statusCode < 300) {

                    if (isset($data['data']) && is_array($data['data']) && isset($data['data']['current_page'])) {
                        $paginator = $data['data'];

                        // Extract custom meta fields from the original payload or meta key
                        $extraMeta = [];
                        foreach ($data as $k => $v) {
                            if (! in_array($k, ['success', 'message', 'data', 'meta', 'links'], true)) {
                                $extraMeta[$k] = $v;
                            }
                        }
                        if (isset($data['meta']) && is_array($data['meta'])) {
                            foreach ($data['meta'] as $k => $v) {
                                if (! in_array($k, ['current_page', 'per_page', 'last_page', 'total', 'from', 'to', 'request_id'], true)) {
                                    $extraMeta[$k] = $v;
                                }
                            }
                        }

                        $newBody = [
                            'success' => true,
                            'data' => $paginator['data'] ?? [],
                            'meta' => array_merge([
                                'current_page' => $paginator['current_page'],
                                'per_page' => $paginator['per_page'],
                                'last_page' => $paginator['last_page'],
                                'total' => $paginator['total'],
                                'from' => $paginator['from'] ?? null,
                                'to' => $paginator['to'] ?? null,
                                'request_id' => $requestId,
                                'timestamp' => now()->toIso8601String(),
                            ], $extraMeta),
                            'links' => [
                                'first' => $paginator['first_page_url'] ?? null,
                                'last' => $paginator['last_page_url'] ?? null,
                                'prev' => $paginator['prev_page_url'] ?? null,
                                'next' => $paginator['next_page_url'] ?? null,
                            ],
                        ];

                        $rawSuccessMessage = is_string($data['message'] ?? null) ? $data['message'] : 'Thành công';
                        $newBody['message'] = UserFacingMessageLocalizer::translate($rawSuccessMessage) ?? 'Thành công';

                        $data = $newBody;
                    } else {
                        $hasStatus = isset($data['status']);
                        $hasData = isset($data['data']);
                        $hasSuccess = isset($data['success']);

                        if ($hasSuccess) {
                            $newBody = $data;
                            if (isset($newBody['message']) && is_string($newBody['message'])) {
                                $newBody['message'] = UserFacingMessageLocalizer::translate($newBody['message']);
                            }
                            if (! isset($newBody['meta'])) {
                                $newBody['meta'] = [];
                            }
                            if (! isset($newBody['meta']['request_id'])) {
                                $newBody['meta']['request_id'] = $requestId;
                            }
                            if (! isset($newBody['meta']['timestamp'])) {
                                $newBody['meta']['timestamp'] = now()->toIso8601String();
                            }
                        } else {
                            $rawSuccessMessage = is_string($data['message'] ?? null) ? $data['message'] : 'Thành công';
                            $newBody = [
                                'success' => true,
                                'message' => UserFacingMessageLocalizer::translate($rawSuccessMessage) ?? 'Thành công',
                                'meta' => [
                                    'request_id' => $data['meta']['request_id'] ?? ($data['request_id'] ?? $requestId),
                                    'timestamp' => $data['meta']['timestamp'] ?? now()->toIso8601String(),
                                ],
                            ];

                            if ($hasData) {
                                $newBody['data'] = $data['data'];
                            } elseif ($hasStatus) {
                                $temp = $data;
                                unset($temp['status'], $temp['message'], $temp['request_id']);
                                $newBody['data'] = empty($temp) ? (object) [] : $temp;
                            } else {
                                $newBody['data'] = $data;
                            }
                        }
                        $data = $newBody;
                    }
                } elseif ($statusCode >= 400) {
                    $defaultErrorCode = 'BUSINESS_RULE_ERROR';
                    $rawMessage = is_string($data['message'] ?? null) ? $data['message'] : 'Đã xảy ra lỗi.';
                    $message = UserFacingMessageLocalizer::translate($rawMessage) ?? 'Đã xảy ra lỗi.';
                    $rawMessageLower = strtolower($rawMessage);

                    switch ($statusCode) {
                        case 400:
                            // WWHN-267 (BE267-D02): nhánh này từng suy ra INSUFFICIENT_STOCK từ
                            // chuỗi tiếng Việt trong message. §8.2 xếp INSUFFICIENT_STOCK vào
                            // hàng 422 ("Field/business validation"), nên phát mã đó kèm HTTP 400
                            // vi phạm đúng câu ràng buộc của dòng Envelope: "HTTP and business
                            // code must match Annex/API contract". Mọi điểm phát
                            // INSUFFICIENT_STOCK thật trong nghiệp vụ đều đang dùng 422
                            // (AlignedOutboundStockService, CurrentBaselineStockService,
                            // CurrentBaselineReversalService, WarehouseException), nên nhánh 400
                            // chỉ còn là đường rò contract chờ một message trùng chữ. Fallback
                            // của 400 giữ đúng một mã.
                            $defaultErrorCode = 'BAD_REQUEST';
                            break;
                        case 401:
                            $defaultErrorCode = 'UNAUTHENTICATED';
                            break;
                        case 403:
                            $defaultErrorCode = 'ACCESS_DENIED';
                            break;
                        case 404:
                            $defaultErrorCode = 'RESOURCE_NOT_FOUND';
                            break;
                        case 405:
                            $defaultErrorCode = 'METHOD_NOT_ALLOWED';
                            break;
                        case 409:
                            if (str_contains($rawMessageLower, 'idempotency')) {
                                // WWHN-289: §8.2 chỉ công nhận STATE_CONFLICT /
                                // IDEMPOTENCY_CONFLICT / CONCURRENCY_CONFLICT /
                                // DOWNSTREAM_DEPENDENCY_EXISTS cho 409. Đây là nguồn
                                // phát mã lỗi thứ hai bên cạnh IdempotencyStore nên phải
                                // dùng chung hằng — nếu không, cùng một tình huống sẽ
                                // trả hai mã khác nhau tuỳ đường đi của response.
                                $defaultErrorCode = IdempotencyStore::CONFLICT_PAYLOAD_DIFFERS;
                            } elseif (str_contains($rawMessageLower, 'trùng') || str_contains($rawMessageLower, 'duplicate') || str_contains($rawMessageLower, 'unique') || str_contains($rawMessageLower, 'đã tồn tại')) {
                                $defaultErrorCode = 'DUPLICATE_RESOURCE';
                            } else {
                                $defaultErrorCode = 'STATE_CONFLICT';
                            }
                            break;
                        case 422:
                            if (str_contains($rawMessageLower, 'tồn kho') || str_contains($rawMessageLower, 'stock') || str_contains($rawMessageLower, 'không đủ')) {
                                $defaultErrorCode = 'INSUFFICIENT_STOCK';
                            } else {
                                $defaultErrorCode = 'BUSINESS_RULE_ERROR';
                            }
                            break;
                        case 500:
                            $defaultErrorCode = 'INTERNAL_SERVER_ERROR';
                            break;
                    }

                    $rawDetails = $data['error']['details'] ?? ($data['errors'] ?? []);
                    $localizedDetails = UserFacingMessageLocalizer::translateDetails($rawDetails);
                    $newBody = [
                        'success' => false,
                        'message' => $message,
                        'error_code' => $data['error']['code'] ?? ($data['error_code'] ?? $defaultErrorCode),
                        'errors' => $localizedDetails,
                        'error' => [
                            'code' => $data['error']['code'] ?? ($data['error_code'] ?? $defaultErrorCode),
                            'details' => $localizedDetails,
                        ],
                        'meta' => [
                            'request_id' => $data['meta']['request_id'] ?? $requestId,
                            'timestamp' => $data['meta']['timestamp'] ?? now()->toIso8601String(),
                        ],
                    ];

                    if (isset($data['data'])) {
                        $newBody['data'] = $data['data'];
                    }

                    // WWHN-165: "no-stack-trace" phải fail-closed theo môi trường,
                    // không chỉ theo cờ debug. Trước đây chỉ cần APP_DEBUG=true (kể
                    // cả bật nhầm trên testing/staging/production) là exception/file/
                    // line/trace bị gắn lại vào envelope. Nay debug payload chỉ tồn
                    // tại khi debug bật VÀ môi trường là local/development.
                    if (config('app.debug') && app()->environment(['local', 'development'])) {
                        if (isset($data['exception'])) {
                            $newBody['exception'] = $data['exception'];
                        }
                        if (isset($data['file'])) {
                            $newBody['file'] = $data['file'];
                        }
                        if (isset($data['line'])) {
                            $newBody['line'] = $data['line'];
                        }
                        if (isset($data['trace'])) {
                            $newBody['trace'] = $data['trace'];
                        }
                    }

                    $data = $newBody;
                }

                $response->setContent(json_encode($data));
            }
        }

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
