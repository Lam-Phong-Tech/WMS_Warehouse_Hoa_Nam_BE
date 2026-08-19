# WWHN-240 — Pin canonical OpenAPI artifact (DEV240-P1-02C-R)

Đóng góp cho `DEV240-P1-02C-R`. Bổ sung cho `BE_CHANGE_MANIFEST.md` và
`openapi-wwhn240-slice.json` (snapshot tĩnh tại candidate `9640091`, nay đã cũ sau
>125 commit trôi trên `m1`).

## Canonical artifact đã chọn

`storage/api-docs/api-docs.json` (và bản song song `storage/api-docs/api-docs.yaml`)
sinh bởi `php artisan l5-swagger:generate`, **được track trong git** (không phải build
output bị ignore) — đây là nguồn sự thật duy nhất cho hợp đồng OpenAPI, thay cho slice
tĩnh cũ. `config/l5-swagger.php → annotations[]` xác nhận có quét cả
`OperationalReportController.php` (dòng 69) và `StockMovementController.php` (dòng 78).

## Pin tại thời điểm xác nhận

- BE HEAD (`m1`): `e02903f1fc6b7635c2deac84a0284f49ddfb0fdd` (2026-08-19)
- Commit cuối cùng chạm `storage/api-docs/api-docs.json`: `6cfb642e7e78b46a012af54a47aac21420502443`
  (đã xác nhận là ancestor của HEAD trên, tức bản generated đang nằm trong working tree
  là bản mới nhất đã commit, không phải build cục bộ chưa track)
- SHA-256 `storage/api-docs/api-docs.json`:
  `14b226e5bcddb6e486878b6130e058d1fe49d176b7973275fce055bc79127af8`
- SHA-256 `storage/api-docs/api-docs.yaml`:
  `01052e5c025ba6cb50af13c63f91dc35950950214cd2935055fcfad607a5a6ec`
- Tổng số `operationId` trong artifact: 231

## Xác nhận hai operation nhóm WWHN-240 có mặt (grep trực tiếp trên artifact, không suy đoán)

```
report_inventory_xnt_current
report_movement_trace
report_movement_trace_export
report_xnt_enhanced
report_xnt_enhanced_export
report_xnt_export
stock_movement_trace_by_physical_code
stock_movement_trace_source
```

→ Annotation của cả `OperationalReportController` (XNT gross/reversal/net) và
`StockMovementController` (`traceSource`/`correctionChain`) đã publish thật vào artifact
generated, không phải bị bỏ sót khỏi scan (cùng lớp lỗi từng xảy ra ở WWHN-184).

## Việc cần làm cho Postman/FE/QA (theo yêu cầu "same contract")

Postman collection và FE/QA phải đồng bộ từ **đúng file `storage/api-docs/api-docs.json`
tại commit đã pin ở trên** (hoặc bất kỳ commit sau này có tracked lại file này), không
dùng lại `openapi-wwhn240-slice.json` (đã lịch sử hoá, giữ lại chỉ để đối chiếu thời điểm
đóng WWHN-240 gốc 2026-08-18).

## Trạng thái

`openapi-wwhn240-slice.json` → đánh dấu **historical/superseded**, không xoá (giữ làm mốc
đối chiếu). Canonical hiện hành = `storage/api-docs/api-docs.json` tại HEAD hiện hành của
`m1` (di chuyển theo commit, không pin cứng một SHA — vì đây là artifact generated đi theo
code, không phải nghiệp vụ cố định).

Việc còn lại (ngoài phạm vi sửa code): FE/QA xác nhận đã đồng bộ Postman/contract-test theo
artifact này (mục DEV240-P1-05R / DEV240-P1-04R).
