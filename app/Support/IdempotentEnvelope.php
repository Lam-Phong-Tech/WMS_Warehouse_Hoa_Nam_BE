<?php

namespace App\Support;

/**
 * WWHN-234 (DEV234-P1-02 · F-09) — cờ `replayed` cho mọi action đổi tồn có idempotency.
 *
 * SRS §13.3 yêu cầu ở cả bốn action đổi tồn (`TC-P1-005/013/029/037`): "retry replayed=true,
 * không tăng/giảm tồn hoặc version lần hai". Header `Idempotent-Replay` một mình là không đủ:
 * client web đọc body, và nhiều tầng proxy/log không giữ header tuỳ biến.
 *
 * Trước task này chỉ **3/8** đường idempotent đổi tồn phát cờ đó ở body — Post Receipt,
 * `ReversalDocumentController` và migration commit. Riêng **Post Issue** thì không, dù nó
 * chạy qua `AlignedOutboundFlowController` chứ không phải `CurrentBaselineStockFlowController`
 * như tên gọi gợi ý. Hệ quả thật: người dùng bấm "Hoàn tất xuất" lần hai sau timeout mạng
 * nhận đúng một response 200 giống hệt lần đầu, không có dấu hiệu nào cho biết đây là replay,
 * nên tưởng vừa trừ tồn thêm một lần nữa.
 *
 * Đặt ở một chỗ để lần thêm endpoint đổi tồn tiếp theo không phải nhớ chép lại đoạn 3 dòng.
 */
final class IdempotentEnvelope
{
    /**
     * Gắn `data.replayed` vào envelope trả về của một operation idempotent.
     *
     * Không tự tạo khoá `data`: nếu body không phải envelope chuẩn thì giữ nguyên, vì việc
     * bịa ra một khoá `data` rỗng sẽ làm hỏng contract của response lỗi.
     *
     * @param  mixed  $body
     * @return mixed
     */
    public static function withReplayFlag($body, bool $replayed)
    {
        if (is_array($body) && array_key_exists('data', $body) && is_array($body['data'])) {
            $body['data']['replayed'] = $replayed;
        }

        return $body;
    }
}
