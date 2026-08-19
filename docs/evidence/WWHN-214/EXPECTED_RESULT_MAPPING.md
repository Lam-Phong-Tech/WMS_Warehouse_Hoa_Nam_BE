# WWHN-214 — Expected-Result Mapping (test oracle) · DEV214-P1-05R

Tài liệu này pin **controlling source** và ánh xạ *hạng mục retest → điều khoản
nguồn → kết quả kỳ vọng* để BA/BAS chỉ cần đối chiếu và ký, đúng yêu cầu
DEV214-P1-05R ("BA/BAS pin current controlling source + expected-result mapping;
BRD v3.9 chỉ giữ historical nếu superseded").

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
| 1 | No-negative stock | REQ-SRS-011/AC-SRS-011, REQ-SRS-012/AC-SRS-012 (§4); §8.2 dòng 545; NFR-SRS-003 (§9) | Post vượt tồn → **422 INSUFFICIENT_STOCK** (hoặc `INSUFFICIENT_AVAILABLE_STOCK` cùng lớp §8.2); "State, stock and movement unchanged"; balance không bao giờ âm |
| 2 | Duplicate/retry (idempotency) | REQ-SRS-008/AC-SRS-008 (§4); NFR-SRS-001 (§9); §13.2 AC-GWT-IDEMP-001; TC-P1-005/013/029 | Retry cùng `Idempotency-Key` + cùng payload → **200 replay**, đúng MỘT movement/balance change; cùng key + khác payload → **409 IDEMPOTENCY_CONFLICT** (TC-P1-006/014/030) |
| 3 | Concurrency (If-Match/version) | §6 State Model (dòng 436); §8.2 (412/428/409); §7.1 DEC-INV-001 (optimistic version); §13.2 AC-GWT-CONC-001; TC-P1-007/015/031/039 | Thiếu If-Match → **428 PRECONDITION_REQUIRED**; If-Match cũ → **412 STALE_VERSION**; sai trạng thái → **409 STATE_CONFLICT**; race: tối đa MỘT request commit, bên thua **409/412/422 theo điểm phát hiện**, không lost update/âm kho |
| 4 | Rollback / no partial write | REQ-SRS-008 (§4); NFR-SRS-002 (§9); §5.1 (atomic commit); §8.2 (500); TC-P1-008/016/032 | Lỗi hạ tầng giữa giao dịch → **500 INTERNAL_ERROR** + request_id; rollback TOÀN BỘ document/movement/balance/subledger; không lộ stack trace/secret; retry sau đó thành công |
| 5 | RBAC | §8.1 Endpoint Catalog + Enforcement standard; Annex C (permission `inbound.post`, `outbound.post`, `inventory.reversal.post`, `warranty.component_issue`); NFR-SRS-005; REQ-SRS-026; TC-P1-003/011/027 | Nặc danh → **401**; thiếu permission → **403 PERMISSION_DENIED / DATA_SCOPE_DENIED** (hoặc 404 theo masking policy); từ chối KHÔNG tạo hiệu ứng state/stock/movement |
| 6 | Append-only ledger | §7.1 DEC-INV-001 ("Inventory_Movement: authoritative ledger, append-only, movement_key unique"); REQ-SRS-013 (§4); BR-REV-01/08 (§5.5); NFR-SRS-014 (§9) | UPDATE/DELETE trực tiếp vào ledger bị **DB trigger chặn** (cả pgsql lẫn sqlite — thông điệp `... is append-only under HN-WMS-ERD-DD-001`); sửa sai chỉ qua Reversal append-only |
| 7 | Reconciliation = 0 | §7.5 Reconciliation Controls ("Balance = Opening + Posted Receipt − Posted Issue + Posted Reversal"); REQ-SRS-023/AC-SRS-023 (§4, BR-RECON-01..04) | Sau chu trình sạch: từng dòng balance = Σ `qty_delta` ledger cùng khoá (warehouse, sku, stock_status); báo cáo đối soát `mismatch_count = 0`, status PASS; **không** sinh `reconciliation_incidents`; divergence (nếu có) chỉ được xử lý bằng reversal có audit, không bao giờ sửa ledger/balance trực tiếp |

Ghi chú trích dẫn: (a) mã lỗi 422 hết tồn neo vào **§8.2** của SRS v1.12 — token
"DEC-OUT-STOCK-HTTP-001" là mã quyết định nội bộ dự án, không xuất hiện trong văn
bản SRS; (b) dưới race thật, bên thua được phép trả bất kỳ 409/412/422 "theo điểm
phát hiện" (AC-GWT-CONC-001) nên test race không hard-assert một mã duy nhất;
(c) 409 idempotent replay của một success trước đó là ngoại lệ no-write duy nhất
(§8.2 + TC-P1-005).

## 3. Phạm vi "adjustment" — absence probe

SRS v1.12 REQ-SRS-037 (DEC-NO-STOCKTAKE-001, §4 dòng 338): **không expose**
Stocktake/Variance/action sửa tồn trực tiếp. Vì vậy oracle cho "adjustment" là
**không tồn tại route/permission/schema** (đã có gate `Wwhn219ApplyAdjustmentDispositionTest`
+ `Wwhn236DeferredAdjustmentGateTest` giữ bất biến này); đường sửa sai duy nhất là
Reversal append-only (REQ-SRS-013, BR-REV-01).

## 4. Nguồn dữ liệu vật lý (ERD 1.0+AMD008)

- Ledger: `inventory_movements` (§30) — trong code Laravel là `stock_movements`
  (model `StockMovement`), append-only, `movement_key`/`business_key` unique.
- Balance: `inventory_balances` (§29) — UNIQUE(warehouse_id, sku_id, stock_status),
  optimistic `version`, cập nhật cùng transaction với movement.
- Idempotency: `idempotency_records`/`idempotency_keys` — UNIQUE(scope, idempotency_key),
  `request_hash` SHA-256 canonical payload; `posting_events`/`posting_event` —
  UNIQUE(resource, posting_action) chặn double-post.
- Đối soát: `reconciliation_incidents` (§32) với `ledger_qty`/`balance_qty`/
  `subledger_qty`/`difference_qty`.

*Người soạn: BE1 (DEV) — tổng hợp để BA/BAS đối chiếu và pin bằng comment trên
WWHN-52/WWHN-214; bản thân tài liệu này không thay thế quyết định của BA/BAS.*
