# WWHN-231 — Comment xác nhận / sign-off theo vai trò

Mỗi vai trò **một comment duy nhất**, đã gộp đủ các mục register thuộc vai trò đó.
Nội dung là **xác nhận (đọc + duyệt)**, không phát sinh việc mới.

Exact SHA candidate: BE `a16d1d6` / FE `7968fa8` (chi tiết: `docs/evidence/WWHN-231/EXACT_SHA.json`).
Trước khi dán chỉ cần thay `<CI_RUN_ID>` bằng Run ID thật.

---

## 1. BA Main — đóng `DEV231-P1-01`, đồng duyệt `DEV231-P0-01`, `DEV231-P1-04`

```text
BA Main xác nhận WWHN-231 tại BE a16d1d6 / FE 7968fa8:

1. Re-scope: mô hình A (kiểm kê/variance/apply-adjustment) KHÔNG thuộc phạm vi ticket này.
   Phạm vi hiện hành của WWHN-231 = đối soát ledger stock-neutral + tối ưu truy vấn ledger.
   Căn cứ: REQ-SRS-037 (Must, adopt tại WWHN-334), SRS §5.3, §7.5, REQ-SRS-031/AC-SRS-031.
2. Traceability sau re-scope đúng nguồn BA hiện hành: REQ-SRS-037 + §7.5 + REQ-SRS-031 +
   DEC-INV-001 + BR-REV-01/02 (chi tiết tại docs/decision-records/DISP-STK-231-ledger-rescope.md §4).
   WWHN-231 không còn phụ thuộc trạng thái phê duyệt của WWHN-25/DEC-STK-001.
3. Đối soát chỉ ghi reconciliation_incident, sửa sai đi qua Reversal append-only — đúng AC-SRS-037.

Xác nhận nội dung nghiệp vụ, không yêu cầu thêm việc. Điều kiện mở lại mô hình A giữ nguyên
theo DISP-STK-231 §3.5 (CR + WWHN-25 GO + SRS rebaseline).
```

---

## 2. Tech Lead — đóng `DEV231-P0-01`, `DEV231-P0-02`, `DEV231-P1-02`

```text
Tech Lead xác nhận WWHN-231 tại BE a16d1d6:

1. P0-01: đã đọc route surface và OpenAPI runtime tại đúng SHA này — 183 path, 0 path chứa
   stocktake/adjustment/variance. Không có API sửa tồn nào ngoài post-receipt / post-issue /
   reverse, cả ba còn nguyên guard permission. Khoá bằng
   Wwhn231StockNeutralLedgerTest::test_no_stock_mutating_route_exists_outside_canonical_posting_surface
   và ::test_canonical_stock_write_surface_is_intact_and_guarded.
2. P0-02: xác nhận scan là stock-neutral trên nhánh m1 — StockLedger chỉ còn inbound()/outbound()/
   reverse(), không còn scanReceipt/scanIssue của nhánh m1-be-2; StockDocumentService::validateScanLine
   không gọi ledger. Đo bằng DB before/after ở test inbound và outbound: movement/balance không đổi
   sau scan, chỉ đổi ở Post.
3. P1-02: diff của WWHN-231 nằm gọn trong service đối soát + 2 báo cáo dùng nó + migration index +
   test + evidence; không có thay đổi ngoài phạm vi.

Xác nhận, không yêu cầu sửa thêm.
```

---

## 3. QA1 — đóng `DEV231-P1-06`, đồng duyệt `DEV231-P0-01/P0-02`, `DEV231-P1-04`; liên đới `WWHN-232`

```text
QA1 xác nhận WWHN-231 / WWHN-232 tại BE a16d1d6:

1. Đã chạy độc lập bộ khoá phạm vi tại đúng SHA này: Wwhn231StockNeutralLedgerTest — 9 test PASS
   trên sqlite baseline; kết quả và log tại docs/evidence/WWHN-231/.
2. Expected result lấy theo phạm vi hiện hành (stock-neutral reconciliation), KHÔNG dùng AC mô
   hình A. Bộ test phủ: negative route, guard permission, scan stock-neutral (inbound + outbound),
   đối soát read-only, phát hiện lệch có incident mà vẫn không sửa tồn, parity query cũ/mới,
   đếm query, và sự tồn tại của index.
3. P1-04: đối soát chạy thật trên PostgreSQL — DB trước/sau khớp hash từng dòng (stock_neutral =
   PASS), mismatch list và hash tại docs/evidence/WWHN-231/RECONCILIATION_RUN.json. Ghi nhận
   database đó đang rỗng nên mismatch = 0 là kết quả rỗng; bằng chứng đối soát trên dữ liệu có
   lệch cấy sẵn nằm ở test ::test_detected_divergence_creates_incident_without_touching_stock
   (phát hiện đúng dimension, ghi incident, tồn không đổi).

Ghi nhận giới hạn đã biết (không do WWHN-231): suite chạy trên PostgreSQL hiện đỏ từ trước ở
migration 2026_08_19_000100 (WWHN-252, lỗi kiểu uuid/text trong trigger mirror pv_industries) —
đã ghi tại docs/evidence/WWHN-231/BASELINE_ISOLATION.md §3 (có đối chứng bằng một test không
thuộc WWHN-231 cho đúng cùng lỗi). Xác nhận QA gate của WWHN-231 trên
baseline sqlite; phần PostgreSQL chạy lại khi WWHN-252 xanh.
```

---

## 4. DevOps / DBA — đóng `DEV231-P1-03`, `DEV231-P1-07`

```text
DevOps/DBA xác nhận WWHN-231 tại BE a16d1d6:

1. P1-03: đã đọc benchmark chạy thật trên PostgreSQL 16.14, dataset 600.000 movements / 2.000 SKU
   tất định, 5 lần đo/cấu hình, có raw EXPLAIN (ANALYZE, BUFFERS) tại docs/evidence/WWHN-231/plans/.
   Kết quả: báo cáo tồn gom toàn bảng 1897,6 ms → 88,7 ms (×21,4) và 607.339 → 8.847 block; báo cáo
   XNT 1633,3 ms → 152,9 ms (×10,7); nhánh có filter SKU 1,972 ms → 0,266 ms (×7,4) và 311 → 13
   block. Số câu truy vấn không đổi (1 aggregate/lần dựng báo cáo).
2. Index decision: đồng ý giữ idx_sm_ledger_dimension (warehouse_id, product_id, stock_status)
   INCLUDE (qty_delta, occurred_at) — 49 MB/600k dòng, ledger là append-only ghi thưa nên chi phí
   ghi chấp nhận được; với nhánh gom toàn bảng planner giữ nguyên plan cũ nên index không gây hại.
3. P1-07: CI chạy tại đúng SHA này — run <CI_RUN_ID>.

Xác nhận, không yêu cầu thay đổi hạ tầng.
```

---

## 5. FE Lead — đồng duyệt `DEV231-P1-05`

```text
FE Lead xác nhận WWHN-231 tại BE a16d1d6 / FE 7968fa8:

Xác nhận NO CONTRACT CHANGE: /api/v1/reports/inventory-balance và
/api/v1/reports/inventory-reconciliation giữ nguyên field, filter, phân trang và error code;
digest storage/api-docs/api-docs.{json,yaml} không đổi; không collection Postman nào cần cập nhật
(bằng chứng: docs/evidence/WWHN-231/CONTRACT_NO_CHANGE.json). FE không phải sửa service hay type.

Thay đổi FE duy nhất là comment mô tả trong src/shared/api/wms-contract.ts: gỡ "apply-adjustment"
khỏi danh sách action đổi tồn (REQ-SRS-037 / BR-REV-01), không đổi hành vi runtime.
```

---

## 6. PM / Approver — đóng `DEV231-P2-01` và ghi kết quả disposition

```text
PM xác nhận WWHN-231 tại BE a16d1d6 / FE 7968fa8:

1. P2-01: chuỗi "BE2 — Duy Khánh" trong Description là historical placeholder (người soạn mô tả
   theo mô hình A trước DISP-STK-231), KHÔNG phải assignee hay owner hiện hành. Đã bổ sung ghi chú
   này vào Description để reviewer sau không hiểu sai quyền sở hữu.
2. Ghi nhận disposition DISP-STK-231 v1.0.0 (re-scope, không park): WWHN-231 ở lại Beta với phạm vi
   đối soát ledger stock-neutral + tối ưu truy vấn; nghiệp vụ kiểm kê vật lý vẫn ngoài MVP theo
   REQ-SRS-037 và chỉ mở lại qua CR.
3. Kết quả phê duyệt: <GO | CONDITIONAL GO | NO-GO> — effective date: <ISO-8601 hoặc NULL>.
```
