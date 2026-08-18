# DISP-STK-230 — Scope disposition cho WWHN-230 / WWHN-58 / WWHN-232 (Stocktake)

| Field | Value |
|---|---|
| Disposition ID | `DISP-STK-230` |
| Version | `1.0.0` |
| Jira chính | `WWHN-230` — [DEV] Kiểm kê (core) |
| Jira liên đới | `WWHN-58` (story kiểm kê), `WWHN-232` (QA1 test kiểm kê) |
| Backlog item | `DEV230-P1-01` |
| Option chọn | **B — park / de-scope khỏi Beta implementation** |
| Status | **PROPOSED — chưa có effective date; cần sign-off ghi trên Jira** |
| Effective date | `NULL — chỉ có sau khi Approver ghi kết quả trên WWHN-230` |
| Proposed date | `2026-08-18` |
| Required approvers | BA Main, PM/Approver, Tech Lead, QA1, BAS |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` + `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE` |
| Branch | `m1` |
| Machine gate | `node scripts/verify-wwhn-230-closure.mjs` (BE) · `npm run wwhn-230:verify` (FE) |
| Test lock | `tests/Feature/Wwhn230StocktakeScopeDispositionTest.php` |

> Tài liệu này là **disposition về phạm vi**, không phải Decision Record kỹ thuật.
> Nó **không** thay `WWHN-25 / DEC-STK-001` (vẫn `In Progress`) và **không** được diễn giải
> là approval cho mô hình kiểm kê A. Không file nào trong repo được tuyên bố `APPROVED`
> trước khi Approver ghi kết quả trên Jira với đúng exact commit SHA ở §7.

---

## 1. Vấn đề

`WWHN-230` yêu cầu triển khai **core kiểm kê** (session / snapshot / count / cutoff /
variance / apply adjustment) và `WWHN-232` yêu cầu QA1 test đúng bộ chức năng đó.

Baseline nghiệp vụ đang có hiệu lực lại **cấm chính bộ chức năng này**. Đây không phải
"chưa làm" mà là **xung đột phạm vi**: hai ticket mô tả một sản phẩm khác với sản phẩm mà
baseline định nghĩa. Chừng nào xung đột chưa được xử lý bằng một quyết định ghi lại được,
mọi lần rà soát sau sẽ lặp lại đúng phát hiện này — đúng như đã xảy ra ở
`WWHN-190/195/205/207/218` với `StockDocumentService` (xem `DEC-STK-002` §Context).

## 2. Căn cứ, xếp theo thứ tự thẩm quyền

1. **`docs/HN_SRS_FRD_v1.12_FULL.md` — `REQ-SRS-037`**, priority **`Must`**:
   "Không expose Stocktake/Variance, menu/API/permission/entity/report hoặc action sửa tồn
   trực tiếp." `AC-SRS-037`: "deployment v1.12 không chứa capability legacy; reconciliation
   chỉ tạo incident, sửa sai dùng reversal theo WWHN-26." Traceability:
   `SRC-008 / DEC-NO-STOCKTAKE-001 → REQ-SRS-037 → BR-NOSTK-01..03 → AC-SRS-037 → TC-SRS-037`.
2. **SRS §0.1**: "No stocktake: không có entity, API, permission, screen, report hoặc hành
   động sửa tồn trực tiếp trong MVP."
3. **SRS §5.3 Camera Scan and No-Stocktake Control**: "Không có Stocktake Session/Snapshot/
   Count/Variance/direct stock edit. Inventory authority dựa trên movement ledger, balance
   projection và subledger reconciliation; sai giao dịch dùng Reversal."
4. **SRS `REQ-SRS-031` / `AC-SRS-031`**: "không có stocktake report."
5. **`docs/HoaNam_ERD_Moi_Nhat.md` §0.1 Entity/field bị loại khỏi baseline**: "Không tạo
   Stocktake_Session, Stocktake_Snapshot, Stocktake_Count, Variance_Line, Adjustment hoặc
   report/permission tương ứng."
6. **`docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html`**: không có màn hình,
   menu entry hay action kiểm kê/sửa tồn nào (0 occurrence).
7. **`docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`**: "Stocktake/Variance/Adjustment
   are outside current MVP; reconciliation + append-only Reversal is the correction path."
8. **`docs/contracts/HoaNam_Current_Baseline_Contract.json`**: `stocktake` nằm trong
   `forbidden_path_fragments`; các bảng `stocktake_*` nằm trong `excluded_tables`.

Hiệu lực của `REQ-SRS-037` đến từ việc **adopt SRS/FRD v1.12 tại `WWHN-334` (Done)**, không
đến từ một Decision ID độc lập. Chi tiết trạng thái governance:
`../../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE/docs/api-evidence/STOCKTAKE_GOVERNANCE_STATUS.json`.

## 3. Vì sao chọn Option B chứ không Option A

**Option A** — làm formal CR đưa Stocktake vào current scope rồi implement — bị loại **ở
mức repo**, không phải vì khó, mà vì DEV/QA không có thẩm quyền tạo ra căn cứ cho nó:

- CR phải sửa `REQ-SRS-037` từ `Must` sang trạng thái khác. Đó là hành động của BA Main +
  PM/PO + Client trên baseline SRS, phát hành version mới, rồi mới lan xuống ERD/BPD/RBAC/
  OpenAPI/wireframe/test. Không có CR nào như vậy tồn tại tính đến `2026-08-18`.
- Decision Record kỹ thuật tương ứng (`WWHN-25 / DEC-STK-001`, mô hình kiểm kê A) vẫn
  `In Progress`, `Effective date: NULL`. Chính `DEC-STK-001` §Impacted tickets đã ghi:
  "`WWHN-58`, `WWHN-230` — stocktake story/core; **must follow decision status**".
- Implement trước khi có CR sẽ tạo **P0 governance breach**: xoá một cờ "thiếu chức năng"
  bằng cách vi phạm một requirement `Must` đang có hiệu lực, đồng thời làm đỏ các gate đang
  xanh (`Wwhn219ApplyAdjustmentDispositionTest`, `check-api-mapping`, `wwhn-296:verify`,
  `wwhn219-permission-catalog.php`).

**Option B** giữ đúng baseline, và quan trọng hơn: biến kết luận thành thứ CI kiểm tra được,
nên lần rà soát sau không phải chứng minh lại cùng một mệnh đề phủ định.

Option B **không đóng cửa vĩnh viễn**. Nó chỉ nói: chừng nào `REQ-SRS-037` còn là `Must`
trong baseline đang adopt, `WWHN-230/58/232` **không nằm trong Beta implementation**.

## 4. Quyết định

1. `WWHN-230` và `WWHN-58` được **park (Later Phase)**, không nằm trong Beta scope. Không
   tạo route, controller, service, model, migration, permission, menu, màn hình hay report
   nào mang ngữ nghĩa Stocktake / Snapshot / Count / Variance / direct stock-edit.
2. `WWHN-232` (QA1) **đổi bản chất**: từ "test chức năng kiểm kê" thành **absence &
   substitution test** — chứng minh bề mặt kiểm kê không tồn tại ở mọi tầng, và chứng minh
   con đường nghiệp vụ thay thế hoạt động đúng. Ma trận AC ở §5.
3. Nghiệp vụ "sửa sai sau Post" đi theo **append-only Reversal** (`DISP-ADJ-001`,
   `BR-REV-01/02`), không qua adjustment trực tiếp. Nghiệp vụ "phát hiện lệch" đi theo
   **reconciliation incident**, chỉ ghi nhận incident, không sửa tồn.
4. Nếu sau này có CR/Decision cho phép mở lại: phải qua `WWHN-25` đóng với `GO`/`CONDITIONAL
   GO` **và** baseline SRS phát hành version mới hạ `REQ-SRS-037`. Khi đó disposition này
   được thay bằng bản `2.0.0`, không sửa đè.
5. **Không sửa** các artifact lịch sử đã pin hash (`docs/api-contract/WWHN-180-posting-openapi.*`,
   evidence pack `WWHN-131`) để phản ánh disposition này. Artifact bất biến chỉ bị thay thế
   bằng decision record trỏ tới nó — chính là tài liệu này.

## 5. Ma trận AC cho WWHN-232 sau khi de-scope

Mỗi dòng có một assertion tự động. `S` = `tests/Feature/Wwhn230StocktakeScopeDispositionTest.php`.

| # | AC gốc (theo review) | Cách chứng minh sau de-scope | Neo |
|---|---|---|---|
| 1 | session / snapshot / cutoff | Không route, không bảng, không model nào mang ngữ nghĩa đó | `S::test_no_stocktake_route_is_registered`, `S::test_no_stocktake_table_exists_after_migrate` |
| 2 | manual / scan count | Không endpoint đếm; scan hiện có chỉ phục vụ inbound/outbound và stock-neutral ở tầng scan | `S::test_no_stocktake_route_is_registered` |
| 3 | duplicate / state machine | Không state machine `DRAFT→IN_PROGRESS→REVIEWED→APPLIED` nào tồn tại | `S::test_no_stocktake_state_machine_or_service_exists` |
| 4 | movement warning | Không có acknowledgment surface vì không có session để cảnh báo | `S::test_no_stocktake_route_is_registered` |
| 5 | variance | Không bảng/cột variance; `stock_movements.stocktake_variance_id` đã bị gỡ | `S::test_no_stocktake_table_exists_after_migrate`, `S::test_stock_movements_has_no_stocktake_variance_column` |
| 6 | RBAC 401/403 | Không permission `stocktake.*` trong catalog; đường sửa tồn hợp lệ vẫn bị chặn đúng | `S::test_no_stocktake_permission_exists`, `S::test_canonical_correction_routes_stay_permission_guarded` |
| 7 | audit | Không action kiểm kê nào để audit; audit của đường thay thế do `ADR-AUDIT-001` phủ | `S::test_canonical_correction_routes_stay_permission_guarded` |
| 8 | **snapshot/count không tạo movement/balance delta** | Chứng minh mạnh hơn AC gốc: **không tồn tại bất kỳ đường ghi tồn nào ngoài Post Receipt / Post Issue / Reversal**, nên không có đường nào để tạo delta | `S::test_no_stocktake_state_machine_or_service_exists`, `verify-canonical-stock-write-path.php` |
| 9 | Apply Adjustment: idempotency / race / rollback / reconcile | Ngoài scope theo `DISP-ADJ-001` (Option B). Đường thay thế là Reversal, đã có idempotency/If-Match/rollback | `Wwhn219ApplyAdjustmentDispositionTest` |

**Điều QA1 không phải làm:** dựng candidate build để test chức năng kiểm kê. Không có
chức năng đó để test. Evidence hợp lệ của `WWHN-232` là kết quả chạy bộ absence test ở đúng
exact SHA, cộng bằng chứng đường thay thế xanh.

## 6. Downstream artifact — trạng thái đối chiếu

| Artifact | Yêu cầu | Cách verify |
|---|---|---|
| SRS/FRD v1.12 | `REQ-SRS-037` còn nguyên là `Must` | `verify-wwhn-230-closure.mjs` đọc lại file, fail nếu mất |
| ERD | §0.1 còn loại trừ entity Stocktake | verifier đọc anchor thật |
| Wireframe MVP | 0 occurrence Stocktake/kiểm kê | verifier đếm occurrence |
| BPD / Techstack guide | Ghi Stocktake ngoài MVP | verifier đọc anchor |
| RBAC catalog | Không `stocktake.*` | `S::test_no_stocktake_permission_exists` + `wwhn219-permission-catalog.php` |
| OpenAPI runtime (`storage/api-docs/api-docs.{json,yaml}`) | 0 path chứa `stocktake` | `S::test_generated_openapi_has_no_stocktake_path` |
| Baseline contract JSON | `stocktake` trong `forbidden_path_fragments` | `S::test_baseline_contract_still_forbids_stocktake` |
| DB schema | Không bảng `stocktake_*`, không cột `stock_movements.stocktake_variance_id` | `S` (chạy sau migrate) |
| FE runtime | Không route/permission/menu/endpoint | `npm run check:api-mapping`, `wwhn-230:verify` |
| Traceability temp key | `HN-INV-001 → WWHN-48` | `docs/traceability/TEMP_KEY_CANONICAL_REGISTER.json` + verifier |

## 7. Sign-off cần ghi trên Jira

Điền vào `docs/decision-records/DISP-STK-230-APPROVAL.json`. Verifier chạy với
`--require-signoff` sẽ fail nếu còn ô trống.

```text
DISP-STK-230 version: 1.0.0
Option: B — park/de-scope WWHN-230 + WWHN-58; WWHN-232 chuyển thành absence test
Controlling requirement: REQ-SRS-037 (Must), SRS/FRD v1.12 adopted at WWHN-334
Exact BE commit SHA: <sha>
Exact FE commit SHA: <sha>
BA Main: <ngày / comment URL>
PM/Approver: <GO | CONDITIONAL GO | NO-GO> / <ngày> / <comment URL>
Tech Lead: <ngày / comment URL>
QA1: <ngày / comment URL>
BAS: <ngày / comment URL>
Effective date: <ISO-8601 hoặc NULL>
```

## 8. Hệ quả

### Tích cực

- `WWHN-230/58/232` có disposition ghi lại được thay vì treo vô thời hạn.
- Kết luận thành gate CI; lần rà soát sau đọc kết quả gate thay vì chứng minh lại.
- QA1 có ma trận AC thi hành được, không phải chờ candidate build không tồn tại.
- Không phát sinh P0 governance breach.

### Chi phí / rủi ro

| Rủi ro | Giảm thiểu |
|---|---|
| Bên nghiệp vụ vẫn cần kiểm kê vật lý | §4.4 mở đường CR; hợp đồng `CTR-WMS-001` đã ghi "stocktake excluded per confirmed revision 04/08/2026" |
| Ai đó merge lại surface kiểm kê từ nhánh cũ | `S` + verifier fail-closed ở BE và FE |
| Gate outlive baseline (SRS đổi mà guard vẫn chặn) | Verifier đọc lại chính file SRS/ERD; SRS mất `REQ-SRS-037` thì **guard fail**, buộc review lại |
| Nhầm disposition này với approval của `DEC-STK-001` | Verifier fail nếu repo trình bày `DEC-NO-STOCKTAKE-001`/`DEC-STK-001` như approved standalone |

## 9. Liên quan

- `DEC-STK-001` — mô hình kiểm kê A (PROPOSED, `WWHN-25`, In Progress)
- `DEC-STK-002` — canonical stock write path
- `DISPOSITION-APPLY-ADJUSTMENT.md` (`DISP-ADJ-001`) — `apply-adjustment` là tên hành động
- `DEC-REV-001` — reversal append-only (`WWHN-26`, In Progress)
- `docs/traceability/TEMP_KEY_CANONICAL_REGISTER.json` — `DEV230-P1-04`

### Quan hệ với các disposition Stocktake anh em

Nhánh kiểm kê được xử lý song song ở nhiều ticket. Các disposition dưới đây **cùng một
hướng** (không hiện thực hoá Stocktake khi `REQ-SRS-037` còn là `Must`) và **không thay thế
nhau**; mỗi bản phủ một nhánh ticket riêng:

| Disposition | Nhánh ticket | Phạm vi |
|---|---|---|
| `DISP-STK-230` (bản này) | `WWHN-230 / 58 / 232` | **core** kiểm kê: session/snapshot/cutoff/variance |
| `DISP-STK-233` | `WWHN-59 / 233 / 235` | Count / Recount / Partial / Stale |
| `DISP-STK-236` | `WWHN-236` | Apply Adjustment |
| `DISP-STK-237` | `WWHN-237 / 238 / 60` | Variance report |
| `DISP-ADJ-001` | `WWHN-219` | `apply-adjustment` là business action, không phải endpoint |

Bên FE, mỗi nhánh giữ **file disposition riêng** trong `docs/api-evidence/`
(`WWHN-230_SCOPE_DISPOSITION.json` cho bản này). `STOCKTAKE_GOVERNANCE_STATUS.json` chỉ giữ
phần *authority* dùng chung (`ruleAuthority`, `decisionRecordGate`) — không nhánh nào được
chiếm một khoá chung, vì nhánh ghi sau sẽ xoá lặng lẽ nhánh ghi trước.
