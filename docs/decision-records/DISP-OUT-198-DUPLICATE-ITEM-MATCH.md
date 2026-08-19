# DISP-OUT-198-DUP — Disposition cho `DUPLICATE_ITEM_MATCH`

- Ticket: WWHN-198 (register DEV198-P2-01)
- Ngày: 2026-08-19
- Owner: DEV (BE2) → Tech Lead xác nhận
- Status: **GIỮ NGUYÊN (KEEP)** — không xoá, không "chuẩn hoá về canonical duplicate error"

## 1. Câu hỏi cần quyết

Rà soát vòng 1 ghi nhận: `recordOutboundMatch()`
(`Modules/Warehouse/app/Services/CurrentBaselineStockService.php:619-629`) có guard
`409 DUPLICATE_ITEM_MATCH` cho cùng invariant "item trùng trong cùng document" mà
`assertNoDuplicateItemInDocument()` ở controller đã chặn trước với
`DUPLICATE_ITEM_IN_DOCUMENT`. Câu hỏi: giữ defense-in-depth hay gộp về một mã lỗi?

## 2. Quyết định và căn cứ

**Giữ nguyên cả hai mã.** Căn cứ không phải cảm tính mà là bằng chứng đã thực thi:

Race PostgreSQL đa process (`scripts/wwhn198-outbound-reservation-race.php`, scenario
`same_request_same_item`) cho hai backend độc lập cùng quét **một item vào đúng một dòng
của cùng một phiếu**. Kết quả đã commit:

| Kiểm tra | Kết quả |
|---|---|
| Hai `pg_backend_pid()` khác nhau, có contention thật | PASS |
| Đúng 1 winner | PASS |
| Loser bị chặn | PASS — **HTTP 409, `error.code = DUPLICATE_ITEM_MATCH`** |
| Chỉ 1 match ACTIVE tồn tại | PASS |
| `scanned_qty` chỉ tăng 1 lần | PASS |
| Scan stock-neutral | PASS |

Nghĩa là: **`DUPLICATE_ITEM_MATCH` KHÔNG phải dead code.** Ở luồng một request tuần tự
thì đúng là controller chặn trước; nhưng khi hai request đồng thời cùng vượt qua kiểm
tra ở controller (hai transaction chưa nhìn thấy nhau), guard trong service — chạy sau
khi đã cầm row lock `item_instances` — là **hàng rào duy nhất còn lại ở tầng ứng dụng**.
Xoá nó đi thì cửa sổ race đó rơi thẳng xuống unique index của DB và trả ra lỗi 500
`QueryException` thay vì 409 có mã nghiệp vụ.

Ba tầng phòng thủ, mỗi tầng có lý do tồn tại riêng:

| Tầng | Guard | Bắt được tình huống |
|---|---|---|
| Controller | `DUPLICATE_ITEM_IN_DOCUMENT` (409) | Người dùng quét lại item đã quét — phản hồi sớm, thông điệp thân thiện |
| Service (trong transaction, sau row lock) | `DUPLICATE_ITEM_MATCH` (409) | Hai request đồng thời — **đã chứng minh bằng race thật** |
| PostgreSQL | `ux_erd_outbound_active_item` / `uk_outbound_match_active_item` (23505) | Mọi đường bypass application (migration, bulk script, regression tương lai) — **đã chứng minh bằng race thật** |

## 3. Hệ quả

1. Không xoá, không đổi tên `DUPLICATE_ITEM_MATCH`.
2. Cả hai mã phải được khai trong OpenAPI 409 của `POST /outbound-documents/{id}/scan`
   (đã có) và trong error catalog HN-BE-API-002.
3. FE xử lý hai mã này như nhau về mặt UX (đều là "item này đã được ghi nhận rồi"),
   nhưng **không được gộp ở tầng backend** — chúng phân biệt được nguyên nhân khi đọc log.
4. Guard chống hồi quy: `tests/Feature/Wwhn198OutboundReservationRuntimeRaceTest.php`
   assert đúng `conflict_is_duplicate_item_match`. Nếu ai đó gộp mã, test đỏ.

## 4. Cần xác nhận

- **Tech Lead**: xác nhận disposition KEEP ở mục 2 và ràng buộc mục 3.
