# WWHN-214 — Expected-Result Mapping (test oracle) · DEV214-P1-05R / P1-05R2

Tài liệu này pin **controlling source** và ánh xạ *hạng mục retest → điều khoản
nguồn → kết quả kỳ vọng* để BA/BAS chỉ cần đối chiếu và ký, đúng yêu cầu
DEV214-P1-05R ("BA/BAS pin current controlling source + expected-result mapping;
BRD v3.9 chỉ giữ historical nếu superseded").

**Cách truy cập (accessible pin — DEV214-P1-05R2):** file này nằm trong repo BE
tại `docs/evidence/WWHN-214/EXPECTED_RESULT_MAPPING.md` trên nhánh `m1` (đóng gói
từ commit `965547a`); nguồn trích dẫn nằm trong repo FE
`docs/HN_SRS_FRD_v1.12_FULL.md` và `docs/HoaNam_ERD_Moi_Nhat.md`. Trích dẫn đã
đối chiếu lại với văn bản gốc ngày 19/08/2026: §1.6 Supersession Register (dòng
220; "BRD v3.9 → BRD v3.20 · HISTORICAL / SUPERSEDED" dòng 225), §6 State Model
(dòng 436), §8.2 (dòng 543–545: 409/412/422 + "State, stock and movement
unchanged"), DEC-INV-001 (dòng 252), ERD `inventory_movements` #30 "Authoritative
append-only ledger" (dòng 269), ERD 59 entity (HN-WMS-ERD-DD-001).

## 1. Controlling source

| Vai trò | Tài liệu | Trạng thái |
|---|---|---|
| Controlling source nghiệp vụ | `HN_SRS_FRD_v1.12_FULL.md` (HN-SRS-FRD-001 v1.12, 14/08/2026) | REVISION APPROVAL CANDIDATE — hiệu lực hình thức khi WWHN-334 approve đúng attachment/hash v1.12 (§0.1, §2 dòng 201) |
| ERD vật lý | `HoaNam_ERD_Moi_Nhat.md` (HN-WMS-ERD-DD-001, revision 1.0+AMD008, 59 entity) | Effective |
| BRD v3.9 | — | **HISTORICAL / SUPERSEDED** theo chính SRS v1.12 §1.6 Supersession Register: "BRD v3.7 / v3.9 / v3.10 / v3.11 → BRD v3.20"; "Các SRS/FRD revision trước → SRS/FRD v1.12" |

BRD v3.9 vì vậy **không còn là test oracle**; mọi expected result dưới đây trích
từ SRS v1.12 (số §/REQ/AC ghi cạnh từng dòng).

## 2. Expected-result mapping cho 7 hạng mục retest (DEV214-P1-04R)

| # | Hạng mục | Điều khoản nguồn (SRS v1.12) | Kết quả kỳ vọng |
|---|---|---|---|
| 1 | No-negative stock | REQ-SRS-011/AC-SRS-011 (dòng 312), REQ-SRS-012/AC-SRS-012 (dòng 313) (§4); §8.2 dòng 545; NFR-SRS-003 (§9 dòng 590) | Post vượt tồn → **422 INSUFFICIENT_STOCK**; "State, stock and movement unchanged"; balance không bao giờ âm — về token hiện thực `INSUFFICIENT_AVAILABLE_STOCK` xem ghi chú **[E1]** |
| 2 | Duplicate/retry (idempotency) | REQ-SRS-008/AC-SRS-008 (§4); NFR-SRS-001 (§9); §13.2 AC-GWT-IDEMP-001; TC-P1-005/013/029 | Retry cùng `Idempotency-Key` + cùng payload → **200 replay**, đúng MỘT movement/balance change; cùng key + khác payload → **409 IDEMPOTENCY_CONFLICT** (TC-P1-006/014/030) |
| 3 | Concurrency (If-Match/version) | §6 State Model (dòng 436); §8.2 (412/428/409); §7.1 DEC-INV-001 (optimistic version); §13.2 AC-GWT-CONC-001; TC-P1-007/015/031/039 | Thiếu If-Match → **428 PRECONDITION_REQUIRED**; If-Match cũ → **412 STALE_VERSION**; sai trạng thái → **409 STATE_CONFLICT**; race: tối đa MỘT request commit, bên thua **409/412/422 theo điểm phát hiện**, không lost update/âm kho |
| 4 | Rollback / no partial write | REQ-SRS-008 (§4); NFR-SRS-002 (§9); §5.1 (atomic commit); §8.2 (500); TC-P1-008/016/032 | Lỗi hạ tầng giữa giao dịch → **500 INTERNAL_ERROR** + request_id; rollback TOÀN BỘ document/movement/balance/subledger; không lộ stack trace/secret; retry sau đó thành công |
| 5 | RBAC | §8.1 Endpoint Catalog + Enforcement standard; Annex C (permission `inbound.post`, `outbound.post`, `inventory.reversal.post`, `warranty.component_issue`); NFR-SRS-005; REQ-SRS-026; TC-P1-003/011/027 | Nặc danh → **401**; thiếu permission → **403 PERMISSION_DENIED / DATA_SCOPE_DENIED** (hoặc 404 theo masking policy); từ chối KHÔNG tạo hiệu ứng state/stock/movement |
| 6 | Append-only ledger | §7.1 DEC-INV-001 — nguyên văn dòng 455: "Inventory_Movement · Authoritative ledger · Append-only; **movement_key/idempotency_key unique**" (optimistic `version` thuộc hàng Inventory_Balance, dòng 456; bảng quyết định DEC-INV-001 còn ở dòng 252); REQ-SRS-013 (§4 dòng 314); BR-REV-01 (dòng 398)/BR-REV-08 (dòng 405) (§5.5); NFR-SRS-014 (§9 dòng 601) | UPDATE/DELETE trực tiếp vào ledger bị **DB trigger chặn** (cả pgsql lẫn sqlite — thông điệp `... is append-only under HN-WMS-ERD-DD-001`); sửa sai chỉ qua Reversal append-only |
| 7 | Reconciliation = 0 | §7.5 Reconciliation Controls (heading dòng 502; công thức dòng 506: "Balance = Opening + Posted Receipt - Posted Issue + Posted Reversal"); REQ-SRS-023/AC-SRS-023 (§4 dòng 324) — BR-RECON-01..04 chỉ được **tham chiếu** tại dòng 324 và 1343, SRS v1.12 không có bảng định nghĩa nội dung riêng cho các BR này; oracle vì vậy neo vào chính AC-SRS-023 + §7.5 | Sau chu trình sạch: từng dòng balance = Σ `qty_delta` ledger cùng khoá (warehouse, sku, stock_status); báo cáo đối soát `mismatch_count = 0`, status PASS; **không** sinh `reconciliation_incidents`; divergence (nếu có) chỉ được xử lý bằng reversal có audit (§7.5 dòng 510), không bao giờ sửa ledger/balance trực tiếp |

Ghi chú trích dẫn: (a) mã lỗi 422 hết tồn neo vào **§8.2** của SRS v1.12 — token
"DEC-OUT-STOCK-HTTP-001" là mã quyết định nội bộ dự án, không xuất hiện trong văn
bản SRS; (b) dưới race thật, bên thua được phép trả bất kỳ 409/412/422 "theo điểm
phát hiện" (AC-GWT-CONC-001, dòng 871/895) nên test race không hard-assert một mã
duy nhất; (c) 409 idempotent replay của một success trước đó là ngoại lệ no-write
duy nhất (§8.2 + TC-P1-005, dòng 919).

**[E1] Token `INSUFFICIENT_AVAILABLE_STOCK` — variance tài liệu, KHÔNG phải defect
hành vi (phát hiện & sửa trích dẫn ở vòng R2).** SRS v1.12 §8.2 (dòng 545) chỉ định
danh `INSUFFICIENT_STOCK`; token `INSUFFICIENT_AVAILABLE_STOCK` không xuất hiện ở bất
kỳ đâu trong SRS. Hiện thực BE có **hai guard tách bạch có chủ đích** trong
`Modules/Warehouse/app/Services/CurrentBaselineStockService.php`: tồn vật lý sẽ âm →
`INSUFFICIENT_STOCK` (dòng 956, 979); available_qty sẽ âm (available = on-hand −
reserved, ngữ nghĩa reservation WWHN-198/199) → `INSUFFICIENT_AVAILABLE_STOCK`
(dòng 985). Cả hai cùng HTTP **422**, cùng bất biến "State, stock and movement
unchanged" của §8.2 — hành vi tuân thủ AC-SRS-011/012. Evidence thô ghi nhận cả hai
token (`retest-r2/qa-retest-raw-api-db.<driver>.json → areas.no-negative`). **Đề nghị
BA/BAS:** ghi erratum bổ sung token `INSUFFICIENT_AVAILABLE_STOCK` vào catalog §8.2
của SRS (cùng hàng 422) — đổi token phía code sẽ phá hợp đồng đã pin (OpenAPI, test
contract WWHN-201/202, FE mapping) nên không làm.

## 3. Phạm vi "adjustment" — absence probe

SRS v1.12 REQ-SRS-037 (DEC-NO-STOCKTAKE-001, §4 dòng 338): **không expose**
Stocktake/Variance/action sửa tồn trực tiếp. Vì vậy oracle cho "adjustment" là
**không tồn tại route/permission/schema** (đã có gate `Wwhn219ApplyAdjustmentDispositionTest`
+ `Wwhn236DeferredAdjustmentGateTest` giữ bất biến này); đường sửa sai duy nhất là
Reversal append-only (REQ-SRS-013, BR-REV-01).

## 4. Nguồn dữ liệu vật lý (ERD 1.0+AMD008 — số dòng theo `HoaNam_ERD_Moi_Nhat.md`)

- Ledger: `inventory_movements` — ERD **§30** (heading dòng 1457; "Authoritative
  append-only ledger" dòng 1459; `movement_key varchar(160) UNIQUE` dòng 1466;
  "Append-only; no UPDATE/DELETE" dòng 1506; hàng index register dòng 269). Trong
  code Laravel là `stock_movements` (model `StockMovement`).
- Balance: `inventory_balances` — ERD **§29** (dòng 1424; `UNIQUE(warehouse_id,
  sku_id, stock_status)` + "cập nhật cùng transaction với movement/subledger" dòng
  1453; `version integer DEFAULT 0 — optimistic lock` dòng 1447).
- Idempotency: `idempotency_records` — ERD **§6** (dòng 495; `request_hash char(64)
  — SHA-256 canonical payload` dòng 508; `UNIQUE(scope, idempotency_key); cùng key
  khác hash → 409 conflict` dòng 528).
- Chặn double-post: `posting_events` — ERD **§31** (dòng 1510) — **[E2]** unique thật
  là `UNIQUE(resource_type, resource_id, posting_action)` (3 cột, dòng 1547) kèm
  `UX(posting_action, idempotency_key)`; bản vòng R ghi gọn "UNIQUE(resource,
  posting_action)" là thiếu cột — đã sửa ở vòng R2.
- Đối soát: `reconciliation_incidents` — ERD **§32** (dòng 1551; `ledger_qty`/
  `balance_qty`/`subledger_qty`/`difference_qty` dòng 1570–1576; "Không auto-edit
  ledger. Resolution phải có evidence/audit" dòng 1604).
- DBMS tham chiếu: PostgreSQL **14+** (ERD dòng 100) — evidence R2 chạy PostgreSQL
  16.14, thoả; quy ước concurrency `version` + If-Match ở ERD dòng 111.

*Người soạn: BE1 (DEV) — tổng hợp để BA/BAS đối chiếu và pin bằng comment trên
WWHN-52/WWHN-214; bản thân tài liệu này không thay thế quyết định của BA/BAS.*
