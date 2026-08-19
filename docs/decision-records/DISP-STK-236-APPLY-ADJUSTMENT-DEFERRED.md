# DISP-STK-236 — Disposition Apply Adjustment / Stocktake (WWHN-60 ↔ WWHN-236 ↔ WWHN-238)

## Document control

| Field | Value |
|---|---|
| Disposition ID | `DISP-STK-236` |
| Jira task | `WWHN-236` (child of `WWHN-60`; QA gate `WWHN-238`) |
| Register closed | `DEV236-P1-01` … `DEV236-P1-07`; vòng R3: `DEV236-P1-01R3`, `-02R3`, `-03R3`, `-04R3`, `-07R3` |
| Version | `1.1.0` |
| Status | **PROPOSED — chờ approver-authored disposition trên Jira** |
| Proposed by | DEV — WWHN-236 |
| Required approvers | BA Main + PM/PO (P1-01), BA+BAS (P1-02), BA+DEV+QA (P1-03), Tech Lead/QA1 (P1-04), QA1 + BA/PM (P1-07) |
| Effective date | `NULL — chỉ có sau formal sign-off`; bản ghi máy đọc: `docs/decision-records/DISP-STK-236-APPROVAL.json` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` + `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE` |
| Canonical branch | `m1` |
| Quan hệ với `DISP-ADJ-001` | Mở rộng phạm vi từ WWHN-219 sang chuỗi WWHN-60/233/234/235/236/238; không thay thế |

> Tài liệu này **không** tự tuyên bố APPROVED. Nó chốt *một* authority duy nhất và biến
> quyết định thành ràng buộc chạy được để lỗi không lặp lại ở vòng rà soát sau.

---

## 1. Vấn đề gốc rễ (root cause của điểm 26/100)

WWHN-236 bị đánh trượt **không phải vì thiếu code**, mà vì trong repo đang tồn tại **ba nguồn
authority mâu thuẫn** về cùng một hành vi `apply-adjustment`, và **không nguồn nào bị đánh dấu là
"không phải controlling"**. Mỗi vòng rà soát lại mở ra một nguồn khác nhau và kết luận khác nhau:

| # | Nguồn | Nói gì về Apply Adjustment | Trạng thái thật |
|---|---|---|---|
| A | `docs/HN_SRS_FRD_v1.12_FULL.md` — `REQ-SRS-037` (Must), §5.3, dòng 65, 180, 235 | **Cấm tuyệt đối**: không entity/API/permission/screen/report/action sửa tồn trực tiếp | **ADOPTED** tại WWHN-334, priority `Must` |
| B | `docs/decision-records/DEC-STK-001.md` (WWHN-25) | Mô tả đầy đủ `POST /api/v1/stocktake-sessions/{id}/apply-adjustment`, permission `stocktake.adjust`, 4 bảng `stocktake_*` | **PROPOSED / NO EFFECTIVE DATE** — chưa bao giờ được duyệt |
| C | `docs/api-contract/WWHN-180-posting-openapi.{json,yaml}` v1.7.0 | Khai path `/api/v1/stocktake/sessions/{id}/apply-adjustment`, tag `Stocktake`, `x-stock-effect: ADJUSTMENT`, và `info.description` viết *"Only Post Receipt, Post Issue and Apply Adjustment may change stock"* | `x-contract-status: PROPOSED`, `x-approval-status: PENDING_INDEPENDENT_SIGN_OFF` |

Thêm hai nguồn phái sinh cùng lặp lại B/C:

- `docs/api-contract/WMS_HoaNam_OpenAPI_Changelog_BE1.{md,txt}` v1.0.1 — đã được
  `HN-BE-API-001_API_CONTRACT_HANDOVER.md:192` hạ xuống *historical*, nhưng chỉ cho riêng file đó.
- BRD/Handoff **v3.9** — baseline mà AC của `WWHN-60` còn trích dẫn; đã bị BRD v3.20 / SRS v1.12
  thay thế (SRS revision history dòng 659: mốc 04/08/2026 "no stocktake và direct stock edit").

**Hệ quả:** AC của `WWHN-60`/`WWHN-236` vẫn yêu cầu "implement Apply Adjustment" trong khi controlling
source đang *exclude* chính capability đó. Task không thể vừa PASS AC vừa PASS `REQ-SRS-037`.
Đó là lý do task đứng ở 26/100 chứ không phải do chất lượng implementation.

---

## 2. Chốt authority — quyết định (đóng `DEV236-P1-01`)

**Controlling source duy nhất cho phạm vi tồn kho / kiểm kê / sửa tồn là `REQ-SRS-037` của
`HN_SRS_FRD_v1.12_FULL.md`**, adopt tại `WWHN-334`, priority `Must`, có `AC-SRS-037`,
`TC-SRS-037`, `BR-NOSTK-01..03` và được xác nhận chéo bởi:

- `HoaNam_ERD_Moi_Nhat.md` §0.1 dòng 95 — *"Không tạo Stocktake_Session, Stocktake_Snapshot,
  Stocktake_Count, Variance_Line, Adjustment hoặc report/permission tương ứng."*
- `WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` dòng 568 — *"Stocktake/Variance/Adjustment are
  outside current MVP; reconciliation + append-only Reversal is the correction path."*
- `HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — **zero** occurrence của
  stocktake / kiểm kê / adjustment ⇒ không có màn hình nào để build.

**Xếp hạng authority (áp dụng cho mọi rà soát sau):**

```text
REQ-SRS-037 (SRS v1.12, ADOPTED, Must)
  >  ERD §0.1 / Techstack Dev Guide / Wireframe MVP        (xác nhận chéo, cùng baseline)
  >  DEC-STK-001 (WWHN-25)                                 (PROPOSED, no effective date)
  >  WWHN-180 posting OpenAPI v1.7.0                       (PROPOSED, pending independent sign-off)
  >  BRD/Handoff v3.9 + Changelog BE1 v1.0.1               (SUPERSEDED / historical)
```

**Disposition:** `WWHN-60` và `WWHN-236` được **DEFERRED — NO CODE**. Không implement Apply
Adjustment. Không tạo route, controller, service, permission, bảng, màn hình hay report nào mang
ngữ nghĩa stocktake / variance / adjustment.

`DEC-STK-001` **không bị huỷ** — nó vẫn là bản nháp hợp lệ cho một release sau. Nó chỉ **không có
hiệu lực** ở MVP hiện tại vì chưa có effective date.

**Điều kiện duy nhất để re-enable** (không có đường tắt nào khác):

1. Approved **Change Request** nâng phạm vi MVP, **và**
2. `WWHN-25` được ghi `GO` hoặc `CONDITIONAL GO` với effective date cụ thể trên Jira, **và**
3. SRS được rebaseline: `REQ-SRS-037` phải được sửa/gỡ ở một version SRS mới có số hiệu và ngày,
   **và**
4. Chỉ khi đủ 1–3 mới mở DEV; `DEV236-P1-05`, `P1-06`, `P1-07` là gate của lần re-enable đó.

Trước khi đủ 4 điều kiện, mọi PR chạm vào bề mặt adjustment/stocktake **fail closed** tại
`scripts/verify-wwhn236-adjustment-disposition.mjs` và
`tests/Feature/Wwhn236DeferredAdjustmentGateTest.php`.

---

## 3. Source register hiện hành (đóng `DEV236-P1-02`)

Reliance vào BRD/Handoff **v3.9** bị **gỡ bỏ**. Nguồn hiện hành, exact digest tại thời điểm chốt
(bản máy đọc được: `docs/evidence/WWHN-236/SOURCE_REGISTER.json`):

| Source ID | File | Version | Vai trò với WWHN-236 |
|---|---|---|---|
| SRS-1.12 | `HN_SRS_FRD_v1.12_FULL.md` | v1.12 (WWHN-334) | **CONTROLLING** — REQ-SRS-037 |
| ERD | `HoaNam_ERD_Moi_Nhat.md` | latest | Xác nhận chéo §0.1 |
| WIREFRAME | `HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` | v3.20 / v1.12 | Xác nhận chéo — zero màn hình |
| TECHSTACK | `WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` | current | Correction path = Reversal |
| BASELINE-CONTRACT | `docs/contracts/HoaNam_Current_Baseline_Contract.json` | schema hiện hành | Machine gate — forbidden fragments + excluded tables |
| OPENAPI-RUNTIME | `storage/api-docs/api-docs.yaml` | sinh từ route thật | Bề mặt API thật — zero adjustment path |

**Digest không được chép tay vào tài liệu này.** Chúng được đo lại mỗi lần chạy:

```bash
node scripts/build-wwhn236-source-register.mjs
```

Kết quả ghi ra `docs/evidence/WWHN-236/SOURCE_REGISTER.json` kèm branch, HEAD SHA và trạng thái
working tree của cả hai repo. Lý do: hash chép tay mục rất nhanh — chính trong lúc soạn tài liệu này,
`HoaNam_ERD_Moi_Nhat.md` đã đổi digest do một thay đổi song song trên `m1`. Một tài liệu mang hash
cũ trông vẫn "có bằng chứng" nhưng thực chất chứng minh sai trạng thái.

**Historical — không được trích dẫn làm controlling contract:**

| Source | Trạng thái | Ghi chú |
|---|---|---|
| `docs/api-contract/WWHN-180-posting-openapi.{json,yaml}` | HISTORICAL | Task-scoped; `x-contract-status: PROPOSED`, `x-approval-status: PENDING_INDEPENDENT_SIGN_OFF` |
| `docs/api-contract/WWHN-150-scan-openapi.yaml` | HISTORICAL | Còn khai enum scan context `STOCKTAKE`; runtime `ScanController` chỉ nhận `INBOUND,OUTBOUND,INVENTORY,TRACE,WARRANTY_RECEIVE,WARRANTY_TEMP_ONLY` — khớp SRS §5.3. Drift đã ghi ở WWHN-181 |
| `docs/api-contract/WMS_HoaNam_OpenAPI_Changelog_BE1.{md,txt}` | HISTORICAL | Theo `HN-BE-API-001:192` |
| `DEC-STK-001` (WWHN-25) | PROPOSED / NO EFFECTIVE DATE | Không phải authority ở v1.12 |
| BRD/Handoff v3.9 | SUPERSEDED | Bị BRD v3.20 / SRS v1.12 thay thế |

> **Không sửa artifact historical để "cho khớp" disposition.** Một immutable handoff chỉ được
> superseded bằng decision record trỏ tới nó — chính là tài liệu này — chứ không bao giờ bằng cách
> sửa nội dung; sửa nội dung sẽ âm thầm phá evidence chain đã pin digest của task gốc.
>
> Ngoại lệ hợp lệ duy nhất là khi **chính task sở hữu artifact** rebaseline nó qua một version mới có
> số hiệu, và cập nhật đồng bộ manifest digest của task đó. Tại thời điểm chốt bản này, `WWHN-237`
> đang rebaseline WWHN-180 OpenAPI lên `v1.8.0` (gỡ path apply-adjustment, thêm
> `x-no-stocktake-requirement: REQ-SRS-037`) — cùng hướng với DISP-STK-236, không mâu thuẫn. Việc
> pin/kiểm digest của artifact đó thuộc gate WWHN-180/237, **không** thuộc gate WWHN-236: gate này
> chỉ đòi bề mặt *đang sống* bằng zero, nên nó không vỡ khi WWHN-237 commit.

### AC disposition của parent `WWHN-60`

AC nào của `WWHN-60` còn yêu cầu "Apply Adjustment thay đổi tồn" **không còn hiệu lực** và phải được
BA rebaseline. Business action "điều chỉnh tồn sau khi đã post" được map sang bề mặt canonical dưới
đây (kế thừa nguyên văn `DISP-ADJ-001` Option B):

| Business action | Canonical endpoint | Permission |
|---|---|---|
| Điều chỉnh một receipt đã post | `POST /api/v1/inbound-documents/{id}/reverse` | `inventory.reversal.post` |
| Điều chỉnh một issue đã post | `POST /api/v1/outbound-documents/{id}/reverse` | `inventory.reversal.post` |
| Tạo chứng từ điều chỉnh độc lập | `POST /api/v1/reversal-documents` | `inventory.reversal.post` |
| Phát hiện lệch tồn | `GET /api/v1/reports/inventory-reconciliation` → tạo `reconciliation_incidents` | `report.view` |

Cả ba endpoint ghi đều yêu cầu `Idempotency-Key` + `If-Match`, ghi movement **append-only**
(`movement_type = 'REVERSAL'`) và phát sinh audit entry theo `BR-REV-02`.

---

## 4. Dependency HN-STK-002 và ranh giới với các nhánh song song (đóng `DEV236-P1-03`)

`HN-STK-002` không có scope authority và không có QA gate vì **capability cha của nó đã bị
`REQ-SRS-037` loại khỏi MVP**. Không thể cấp scope authority cho một dependency của một feature
không tồn tại.

```text
REQ-SRS-037 (một requirement, hai nửa bề mặt)
├── nhánh ADJUSTMENT   WWHN-60  → WWHN-236 → QA gate WWHN-238   ← DISP-STK-236 (tài liệu này)
└── nhánh COUNT/RECOUNT WWHN-59 → WWHN-233 → QA gate WWHN-235   ← DISP-STK-233
```

| Ticket | Disposition | Chủ sở hữu | Ghi chú |
|---|---|---|---|
| `WWHN-60` | **DEFERRED — NO CODE** | WWHN-236 | Parent của task này; AC Apply Adjustment hết hiệu lực (§3) |
| `WWHN-236` | **DEFERRED — NO CODE** | WWHN-236 | Task này |
| `WWHN-238` | **DEFERRED / N/A** | WWHN-236 | QA gate của nhánh adjustment — xem §6 |
| `HN-STK-002` | **DEFERRED — không mở scope trong MVP** | WWHN-236 | Dependency của stocktake capability bị `REQ-SRS-037` exclude |
| `WWHN-233` / `WWHN-235` | DEFERRED_NO_CODE theo **`DISP-STK-233`** | **WWHN-233** | Nhánh count/recount, parent `WWHN-59`. **Không** thuộc thẩm quyền tài liệu này |

### Ranh giới sở hữu artifact

Hai nhánh chạy song song trên cùng `m1` và đóng hai nửa khác nhau của cùng một `REQ-SRS-037`.
Ranh giới cố định, khớp với `DISP-STK-233` §12:

| Hạng mục | Chủ sở hữu |
|---|---|
| `docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md`, `scripts/verify-wwhn236-adjustment-disposition.mjs`, `tests/Feature/Wwhn236DeferredAdjustmentGateTest.php`, `scripts/build-wwhn236-source-register.mjs`, `docs/evidence/WWHN-236/` | **WWHN-236** |
| `forbidden_path_fragments`: `stocktake`, `adjustment`, `variance` | **WWHN-236** |
| `forbidden_path_fragments`: `recount`, `cycle-count`, `physical-count` | **WWHN-233** |
| `docs/decision-records/DISP-STK-233-COUNT-RECOUNT-DEFERRED.md`, `scripts/verify-wwhn233-count-disposition.mjs`, `docs/evidence/WWHN-233/` | **WWHN-233** |
| FE `scripts/verify-wwhn-233-closure.mjs`, `docs/api-evidence/STOCKTAKE_GOVERNANCE_STATUS.json` | **WWHN-233** |
| FE `scripts/verify-wwhn-236-closure.mjs`, `src/test/wwhn-236-adjustment-deferral-gate.test.ts` | **WWHN-236** |

Nhánh này **chỉ đọc**, không sửa artifact thuộc WWHN-233 / WWHN-237.

### Ràng buộc chống disposition rỗng

`DISP-STK-233` chỉ ra một lỗi thật của bản nháp đầu tiên: *một disposition có thể mô tả hàng rào mà
hàng rào đó chưa bao giờ được viết.* Vì vậy mọi đường dẫn artifact mà tài liệu này cam kết đều bị
verifier trích ra và assert tồn tại — ở cả hai chiều:

- `scripts/verify-wwhn233-count-disposition.mjs` (BE) trích đường dẫn từ **cả** `DISP-STK-233` lẫn
  `DISP-STK-236` và fail nếu file không có mặt.
- `scripts/verify-wwhn-236-closure.mjs` (FE) làm điều tương tự cho nửa FE.

Nghĩa là tài liệu này không thể "hứa suông": hứa một file tức là buộc phải tạo file đó.

### Giới hạn của disposition này

Phiên làm việc không truy cập được Jira (connector Atlassian chưa uỷ quyền), nên nội dung
`HN-STK-002` và các AC gốc của `WWHN-60` được suy ra từ artifact trong repo (register được giao,
`DEC-STK-001`, `WWHN-180` OpenAPI, `STOCKTAKE_GOVERNANCE_STATUS.json`) chứ không đọc trực tiếp
từ ticket. **BA phải đối chiếu lại danh sách ticket ở bảng trên trước khi ký `DEV236-P1-03`.**
Nếu `HN-STK-002` thực chất là dependency của nhánh count/recount chứ không phải adjustment, hàng
disposition tương ứng chuyển sang `DISP-STK-233` — kết luận DEFERRED không đổi, chỉ đổi chủ sở hữu.

Không ticket nào ở trên được để trạng thái `Blocked` hay `Pending` mơ hồ: cả hai nhánh chia sẻ
**một** điều kiện re-open duy nhất — bộ 4 điều kiện ở §2. Khi và chỉ khi bộ đó được thoả, cả chuỗi
được rebaseline cùng lúc.

---

## 5. Handoff NO-CODE (đóng `DEV236-P1-04`)

`WWHN-236` **không được giữ ở trạng thái implementation-complete**. Handoff chính thức:
`docs/evidence/WWHN-236/NO_CODE_HANDOFF.md`.

| Hạng mục | Giá trị |
|---|---|
| Kiểu handoff | **NO-CODE / DEFERRED** |
| Code thay đổi cho capability | **ZERO** — không route / controller / service / migration / permission / screen |
| Code thay đổi cho *gate* | Có — verifier + test + forbidden fragments (chỉ để chặn tái xuất hiện) |
| Bằng chứng | route surface, runtime OpenAPI, permission catalog, schema, FE route/permission — tất cả zero |

Thay đổi duy nhất được phép trong task này là **hàng rào**, không phải capability:

1. `docs/contracts/HoaNam_Current_Baseline_Contract.json` — bổ sung `apply-adjustment`,
   `adjustments`, `variances`, `stock-adjustments` vào `forbidden_path_fragments`.
   *Lý do:* trước đây chỉ chặn mảnh `stocktake`, nên một route đặt tên khác — ví dụ
   `POST /api/v1/inventory/apply-adjustment` — vẫn lọt qua toàn bộ gate hiện có. Đây là lỗ hổng
   thật, không phải giả định.
2. `scripts/verify-wwhn236-adjustment-disposition.mjs` — verifier fail-closed.
3. `tests/Feature/Wwhn236DeferredAdjustmentGateTest.php` — khoá bằng test.
4. FE: `scripts/verify-wwhn-236-closure.mjs` (7 check) + `src/test/wwhn-236-adjustment-deferral-gate.test.ts` (4 test).

---

## 6. QA disposition `WWHN-238` (đóng `DEV236-P1-07`)

Xem `docs/evidence/WWHN-236/QA_DISPOSITION_WWHN-238.md`.

Tóm tắt: `WWHN-238` được đặt **DEFERRED / N/A cho MVP v1.12**, **không** phải `Blocked`, **không**
phải `Pending`. QA1 không thực hiện exact-build test cho một capability đã bị controlling source
exclude. Thay vào đó QA1 chạy **negative gate** — xác nhận capability *không* tồn tại ở mọi tầng —
và đó là toàn bộ phạm vi QA của WWHN-236 ở MVP này.

---

## 7. `DEV236-P1-05` và `DEV236-P1-06` — trạng thái

Cả hai mục mở đầu bằng **"Nếu re-enabled"**. Vì disposition là DEFERRED, chúng **không phát sinh
nghĩa vụ dương ở MVP v1.12**, và được thay bằng **nghĩa vụ phủ định** đã thực thi được:

| Register | Nghĩa vụ khi DEFERRED | Kiểm chứng bằng |
|---|---|---|
| `DEV236-P1-05` (contract) | OpenAPI runtime + Postman + permission matrix + baseline contract **không** khai `apply-adjustment` / `stocktake` / `variance`; artifact historical giữ nguyên digest | `docs/evidence/WWHN-236/CONTRACT_NEGATIVE_LOCK.json` |
| `DEV236-P1-06` (transaction / PostgreSQL) | **Không tồn tại** đường ghi tồn thứ hai. `chk_stock_movement_type_current` chỉ cho `OPENING/RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL` ⇒ `ADJUST_IN` / `ADJUST_OUT` bị DB từ chối. Bốn bảng `stocktake_*` vắng mặt. Correction path duy nhất là Reversal append-only đã có lock/CAS/idempotency riêng | `docs/evidence/WWHN-236/logs/` (chạy thật trên PostgreSQL) |

Nếu sau này re-enable, hai mục này quay lại đúng nguyên văn yêu cầu ở register và phải được đóng
bằng evidence dương (race / retry / rollback / reconcile), không phải bằng tài liệu này.

---

## 8. Consequences

### Positive

- Chỉ còn **một** authority cho phạm vi tồn kho: `REQ-SRS-037`. Vòng rà soát sau không phải mở lại
  DEC-STK-001 hay WWHN-180 OpenAPI để đoán xem cái nào thắng.
- Lỗ hổng "route adjustment đặt tên khác" bị bịt ở tầng contract, không phụ thuộc người review có
  nhớ hay không.
- `WWHN-60/233/234/235/236/238` có **một** điều kiện re-open chung, thay vì 6 trạng thái mơ hồ.

### Negative / cost

- Nghiệp vụ kiểm kê vật lý **không có** trong MVP. Lệch tồn chỉ phát hiện qua reconciliation report
  và chỉ sửa được bằng Reversal — tức phải truy về đúng chứng từ gốc sai. Không có đường "chỉnh
  thẳng số tồn". Đây là hệ quả có chủ đích của `REQ-SRS-037`, cần được PM/PO xác nhận là chấp nhận
  được về vận hành.
- Thêm hai gate phải bảo trì (BE + FE).

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Vòng rà soát sau lại trích DEC-STK-001 làm authority | §2 xếp hạng authority tường minh; verifier assert DEC-STK-001 vẫn `PROPOSED` |
| Ai đó "sửa cho khớp" hai file WWHN-180 | Verifier assert digest `8922b027…` không đổi ⇒ fail closed |
| Route adjustment mọc lại dưới tên khác | `forbidden_path_fragments` mở rộng + verifier quét route thật |
| `stocktake.adjust` mọc lại trong permission catalog | Test khoá permission ở cả BE và FE |
| Sửa tồn trực tiếp qua movement type | `chk_stock_movement_type_current` chặn ở DB; test assert constraint còn nguyên |

---

## 9. Approval and effective status

Current result: **PROPOSED / NO EFFECTIVE DATE**.

Bản ghi Jira bắt buộc trên `WWHN-236`:

```text
DISP-STK-236 version: 1.0.0
Controlling source: REQ-SRS-037 / HN_SRS_FRD_v1.12_FULL.md (WWHN-334)
Exact BE commit SHA: <sha>
Exact FE commit SHA: <sha>
Evidence: docs/evidence/WWHN-236/SHA256SUMS
BA Main + PM/PO (P1-01) disposition: <DEFERRED|RE-ENABLE via CR>
BA + BAS (P1-02) source register accepted: <yes/no + date>
BA + DEV + QA (P1-03) dependency disposition: <value>
Tech Lead / QA1 (P1-04) no-code handoff accepted: <value>
QA1 + BA/PM (P1-07) WWHN-238 disposition: <DEFERRED / N/A>
Effective date: <ISO-8601 or NULL>
```

Guard ở §5 **active bất kể trạng thái phê duyệt**: kể cả khi disposition còn `PROPOSED`, CI vẫn chặn
việc tái xuất hiện bề mặt adjustment / stocktake.

---

## 10. Vòng R3 — biến phần "chờ người ký" thành ràng buộc chạy được

Vòng R2 đóng 76/100 với bề mặt code đã sạch. Năm mục còn lại **không phải lỗi code**: chúng là
phần governance mà tài liệu có mô tả nhưng repo không có chỗ nào ghi lại được, nên không ai kiểm
chứng được là đã làm hay chưa. R3 tạo đúng bốn chỗ ghi đó, cộng một lệnh cho QA.

| Register R3 | Thiếu ở R2 | Artifact R3 | Gate |
|---|---|---|---|
| `DEV236-P1-01R3` | Không có nơi ghi Decision/version/hash/effective date, và không có chỗ để PM/PO chấp nhận **limitation** | `docs/decision-records/DISP-STK-236-APPROVAL.json` | check `APPROVAL-RECORD` + `--require-signoff` |
| `DEV236-P1-02R3` | Register nói "AC hết hiệu lực" nhưng không liệt AC nào bị gỡ và nguồn thay thế là gì | `parent_rebaseline` trong `docs/evidence/WWHN-236/SOURCE_REGISTER.json` | check `PARENT-REBASELINE` |
| `DEV236-P1-03R3` | Chuỗi ticket nằm trong bảng markdown, máy không đọc được, QA task có thể bị để `Blocked` | `docs/evidence/WWHN-236/CHAIN_DISPOSITION.json` | check `CHAIN-DISPOSITION` |
| `DEV236-P1-04R3` | Register pin `head_sha` của một working tree `DIRTY`, không phải commit chứa guard | `candidate_identity[].candidate_sha` + `governed_files[]` (path + blob + commit) | check `CANDIDATE-PIN` |
| `DEV236-P1-07R3` | QA phải tự ghép 4 lệnh và tự nhớ ghi env/PG version | `scripts/qa-wwhn236-exact-sha-gate.mjs` → `docs/evidence/WWHN-236/logs/qa-rerun-<sha>.log` | check `QA-RERUN` |

### 10.1 Bản ghi phê duyệt — vì sao là JSON chứ không phải một dòng trong tài liệu

Một dòng "đã được duyệt" viết trong markdown không nói được *ai* duyệt, *bản nào*, *ngày nào*, và
không chặn được việc chính DEV tự viết dòng đó. `DISP-STK-236-APPROVAL.json` tách bạch:

- `decision`, `dispositionVersion`, `dispositionSha256` (đo lại mỗi lần chạy builder, không chép tay),
  `effectiveDate`;
- `signOff` sáu vai trò, mỗi vai trò có `decision` / `date` / `commentUrl` trỏ tới comment **do chính
  approver viết** trên Jira;
- verifier **cấm** trạng thái `APPROVED` khi còn thiếu chữ ký, thiếu effective date hoặc chưa pin SHA.
  DEV không thể tự flip trạng thái này, kể cả bằng cách sửa file: check `APPROVAL-RECORD` sẽ đỏ.

Ghi chữ ký bằng lệnh, không sửa tay:

```bash
node scripts/build-wwhn236-source-register.mjs --record-signoff role=pmPo decision=APPROVED date=2026-08-19 url=<jira-comment-url>
```

### 10.2 `LIM-STK-236-01` — giới hạn mà PM/PO phải chấp nhận tường minh

Đây là nội dung thực chất của `DEV236-P1-01R3`, không phải thủ tục. Phát biểu chính xác:

> Khi tồn sổ lệch tồn thực vì nguyên nhân **không gắn với một chứng từ sai** — mất, hỏng, trộm, đếm
> sai lúc nhận, hao hụt — hệ thống chỉ **ghi nhận** sự lệch. Không có đường đưa số tồn về khớp thực tế.

Cụ thể ở MVP v1.12:

| Hệ thống làm được | Hệ thống **không** làm được |
|---|---|
| `php artisan inventory:reconcile` (cron) ghi `reconciliation_incidents` cho divergence mới | Không có API/màn hình resolve hay đóng `reconciliation_incidents` — bảng chỉ được lệnh cron ghi |
| `GET /api/v1/reports/inventory-reconciliation` báo cáo on-demand (không lưu vết) | Không có đường ghi tồn thứ hai: `chk_stock_movement_type_current` từ chối `ADJUST_IN`/`ADJUST_OUT` |
| `GET /api/v1/reports/inventory-balance` trả `reconciliation_status=MISMATCH` | Reversal chỉ đảo được chứng từ **đã tồn tại**; không có chứng từ gốc thì không có gì để đảo |

Hệ quả vận hành: SRS dòng 510 — divergence tạo `Reconciliation_Incident` và **block readiness/sign-off**,
chỉ được resolve bằng audited reversal theo `WWHN-26`. Nghĩa là một lệch vật lý không có chứng từ gốc
sẽ **treo readiness** cho tới khi có CR mở lại Apply Adjustment, hoặc tới khi nghiệp vụ chấp nhận xử lý
ngoài hệ thống (biên bản giấy) và ghi lý do vào incident. Đây là chi phí có chủ đích của `REQ-SRS-037`,
và là điều PM/PO ký chấp nhận chứ không phải điều DEV tự quyết.

### 10.3 Candidate pin — vì sao không pin HEAD

`m1` có nhiều phiên làm việc song song. Pin `head_sha` như bản R2 nghĩa là pin một commit của người
khác, và pin đó sai ngay hôm sau. R3 pin **commit cuối cùng chạm vào artifact do WWHN-236 sở hữu**,
bỏ qua các file tự chứa hash (`SOURCE_REGISTER.json`, `SHA256SUMS`, `CONTRACT_NEGATIVE_LOCK.json`,
`DISP-STK-236-APPROVAL.json`) vì chúng không thể tự tham chiếu, và bỏ qua file dùng chung với nhánh
khác (`docs/contracts/HoaNam_Current_Baseline_Contract.json` — nhánh `WWHN-233` cũng ghi vào đó) vì
pin blob của file dùng chung sẽ đỏ oan khi nhánh kia commit; file đó được kiểm theo **nội dung nghiệp
vụ** ở check `BASELINE-FRAGMENTS` thay vì theo blob.

Vì dự án đi thẳng `m1`, không có PR: `pull_request` ghi `N/A — direct-to-m1` tường minh, và Tech Lead
review đúng diff bằng lệnh ở `tech_lead_review.diff_command`. Tập file chính xác không nằm trong lời
văn mà ở `candidate_identity[].governed_files[]` — mỗi dòng đối chiếu được bằng
`git rev-parse <sha>:<path>`.

### 10.4 Một lệnh cho QA

```bash
node scripts/qa-wwhn236-exact-sha-gate.mjs            # trên checkout hiện tại
node scripts/qa-wwhn236-exact-sha-gate.mjs --strict-head   # khi QA checkout sạch đúng SHA
```

Lệnh ghi sẵn OS / Node / PHP / PostgreSQL / branch / HEAD / candidate SHA, đối chiếu blob của từng
governed file với SHA đã pin, rồi chạy verifier BE + phpunit + verifier FE và kết luận `PASS`/`FAIL`.
Raw log nằm ở `docs/evidence/WWHN-236/logs/qa-rerun-<sha>.log` để QA1 dán thẳng lên `WWHN-238`.
