<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Never equate a supplied hardware UID with a tag found only through its payload. */
final class P07NfcReadbackGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('PATCH')) {
            $input = $request->validate([
                'hardware_uid' => ['required', 'string', 'min:1', 'max:150', 'regex:/\S/u'],
                'code_value' => ['nullable', 'string', 'max:100'],
            ]);
            // The UID limit is larger than physical_codes.code_value. Validate the
            // generated alias too, without changing the request/idempotency hash.
            $alias = strtoupper(trim((string) ($input['code_value'] ?? ('NFC-'.trim($input['hardware_uid'])))));
            if (preg_match('/^.{1,100}$/usD', $alias) !== 1) {
                ApiError::abort(422, 'NFC_CODE_VALUE_INVALID', 'Mã chip sau chuẩn hóa phải có từ 1 đến 100 ký tự.');
            }

            return $next($request);
        }

        // Auth, permission, session and warehouse enforcement remain in the
        // existing route/controller. Inspect only a successfully scoped response.
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() >= 400) {
            return $response;
        }
        $body = $response->getData(true);
        $data = $body['data'] ?? [];
        $uid = strtoupper(trim((string) $request->input('hardware_uid', '')));
        if ($uid === '' || ! ($data['resolved'] ?? false)) {
            return $response;
        }
        $tag = $data['nfc'] ?? null;
        if (! is_array($tag) || strtoupper(trim((string) ($tag['hardware_uid'] ?? ''))) !== $uid) {
            // A correct QR payload does not register an unknown physical chip.
            // Do not return another chip/item as the answer for this UID.
            ApiError::abort(422, 'NFC_UID_PAYLOAD_MISMATCH', 'UID chip chưa được xác nhận với nội dung này; không suy ra liên kết từ payload.');
        }
        if (($tag['carrier_type'] ?? null) !== 'NFC') {
            ApiError::abort(409, 'NFC_UID_CONFLICT', 'UID không thuộc chip NFC.');
        }
        if (($tag['status'] ?? null) !== 'ACTIVE') {
            ApiError::abort(409, 'NFC_TAG_INACTIVE', 'Chip không hoạt động; dùng chi tiết chip để tra cứu lịch sử, không xác nhận quét thành công.');
        }
        $raw = $request->input('raw_code');
        if (is_string($raw) && $raw !== '') {
            $expected = $data['nfc_payload'] ?? null;
            if (! is_string($expected) || ! hash_equals($expected, $raw)) {
                ApiError::abort(422, 'NFC_PAYLOAD_ITEM_MISMATCH', 'UID và nội dung đọc từ chip không khớp cùng hiện vật.');
            }
        }

        return $response;
    }
}
