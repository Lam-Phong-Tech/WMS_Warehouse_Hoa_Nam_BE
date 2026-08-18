# DISP-STK-233 — Disposition Count / Recount / Partial / Stale (WWHN-59 ↔ WWHN-233 ↔ WWHN-235)

## Document control

| Field | Value |
|---|---|
| Disposition ID | `DISP-STK-233` |
| Jira task | `WWHN-233` (child of `WWHN-59`; QA gate `WWHN-235`) |
| Register closed | `DEV233-P1-01` … `DEV233-P1-06`, `DEV233-P2-01` |
| Version | `1.0.0` |
| Status | **PROPOSED — chờ approver-authored disposition trên Jira** |
| Proposed by | DEV — WWHN-233 |
| Required approvers | BA Main + PM/PO (P1-01), DEV → TL/QA (P1-02), BAS + BA (P1-03), DEV + TL → BA/FE/QA (P1-04), DEV → QA1/TL/DBA (P1-05), QA1 → BA/TL (P1-06), PM/BAS (P2-01) |
| Effective date | `NULL — chỉ có sau formal sign-off` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` + `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE` |
| Canonical branch | `m1` |
| Quan hệ với `DISP-STK-236` | **Thực thi** phần hàng rào mà DISP-STK-236 §5 mô tả nhưng chưa tồn tại trong repo; mở rộng sang nhánh `WWHN-59/233/235`. Không thay thế. |
| Quan hệ với `DISP-ADJ-001` | Kế thừa nguyên văn Option B (apply-adjustment là business action, không phải endpoint) |

> Tài liệu này **không** tự tuyên bố APPROVED. Nó chốt *một* authority duy nhất và biến quyết định
> thành ràng buộc chạy được, để lỗi không lặp lại ở vòng rà soát sau.

---

## 1. Vấn đề gốc rễ (root cause của điểm 34/100)

Review WWHN-233 chấm 34/100 với 6 mục P1 + 1 mục P2. Đọc kỹ cả 7 mục thì chúng **không** yêu cầu
implement Stocktake. Sáu trong bảy mục mở đầu bằng *"Nếu scope được activate"* / *"Nếu re-enable"*.
Cái bị thiếu là **disposition** — và bằng chứng chạy được cho disposition đó.

Có hai nguyên nhân độc lập:

### 1.1 AC còn active trong khi controlling source đã exclude capability

`WWHN-59` / `WWHN-233` vẫn mang AC "count / recount / partial / stale" trong khi `REQ-SRS-037`
(Must, đã adopt) cấm toàn bộ bề mặt Stocktake. Task không thể vừa PASS AC vừa PASS `REQ-SRS-037`.
Đây là cùng một cơ chế hỏng đã được mô tả ở `DISP-STK-236` §1 cho nhánh `WWHN-60/236`.

### 1.2 Disposition có thể tồn tại mà hàng rào của nó thì không

Đây là phát hiện của WWHN-233 và là lý do một disposition bằng văn bản, tự nó, **không** đóng được
register này.

`DISP-STK-236` §5 liệt kê 4 hạng mục "thay đổi duy nhất được phép" (verifier, gate test, forbidden
fragments, verifier FE). Khi WWHN-233 bắt đầu rà soát, **không hạng mục nào trong 4 tồn tại trên
`m1`** — `DISP-STK-236` khi đó mới chỉ là văn bản mô tả một hàng rào chưa được dựng. Nhánh WWHN-236
đang lấp phần của nó song song với task này (xem §12).

Bài học được đóng thành ràng buộc chạy được, không phải thành ghi chú: **mọi artifact mà một
disposition của chuỗi Stocktake cam kết đều phải được verifier khẳng định là có thật.** Đó là
`ANTI-DANGLING` check trong `scripts/verify-wwhn233-count-disposition.mjs` — nó đọc chính hai file
disposition, trích mọi đường dẫn artifact mà chúng hứa, và fail nếu đường dẫn đó không tồn tại.

Khoảng trống thứ hai, thuộc riêng nhánh `WWHN-59/233`: `forbidden_path_fragments` sau khi WWHN-236 mở
rộng vẫn chỉ chặn `stocktake` / `adjustment` / `variance`. AC của nhánh này là **count / recount /
partial / stale**, và một route đếm lại tồn kho — `POST /api/v1/inventory/recount`,
`/inventory/cycle-count` — không nhất thiết chứa ba mảnh đó. WWHN-233 bổ sung `recount`,
`cycle-count`, `physical-count`.

---

## 2. Chốt authority — quyết định (đóng `DEV233-P1-01`)

**Controlling source duy nhất cho phạm vi kiểm kê / đếm / sửa tồn là `REQ-SRS-037` của
`HN_SRS_FRD_v1.12_FULL.md`**, adopt tại `WWHN-334`, priority `Must`, kèm `AC-SRS-037`, `TC-SRS-037`,
`BR-NOSTK-01..03`.

Xác nhận chéo trong cùng baseline:

- `HN_SRS_FRD_v1.12_FULL.md:65` — *"No stocktake: không có entity, API, permission, screen, report
  hoặc hành động sửa tồn trực tiếp trong MVP."*
- `HN_SRS_FRD_v1.12_FULL.md:180` — cột out-of-scope: *"Physical stocktake, variance và hành động sửa
  tồn trực tiếp"*.
- `HN_SRS_FRD_v1.12_FULL.md:372` (§5.3) — *"Không có Stocktake Session/Snapshot/Count/Variance/direct
  stock edit."*
- `HoaNam_ERD_Moi_Nhat.md:95` (§0.1) — *"Không tạo Stocktake_Session, Stocktake_Snapshot,
  Stocktake_Count, Variance_Line, Adjustment hoặc report/permission tương ứng."*
- `WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md:568` — *"Stocktake/Variance/Adjustment are outside
  current MVP; reconciliation + append-only Reversal is the correction path."*
- `HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — **zero** occurrence của
  `stocktake` / `kiểm kê` / `REQ-SRS-037` ⇒ không có màn hình count/recount nào để build.

**Xếp hạng authority** (áp dụng cho mọi rà soát sau, giữ nguyên thứ tự của `DISP-STK-236` §2):

```text
REQ-SRS-037 (SRS v1.12, ADOPTED, Must)
  >  ERD §0.1 / Techstack Dev Guide / Wireframe MVP        (xác nhận chéo, cùng baseline)
  >  DEC-STK-001 (WWHN-25)                                 (PROPOSED, no effective date)
  >  WWHN-180 posting OpenAPI v1.7.0                       (PROPOSED, pending independent sign-off)
  >  BRD/Handoff v3.9 + Changelog BE1 v1.0.1               (SUPERSEDED / historical)
```

**Disposition:** `WWHN-59` và `WWHN-233` được **DEFERRED — NO CODE**. Không implement count,
recount, partial count, stale/CAS baseline của phiên kiểm kê, count history hay bất kỳ route /
controller / service / permission / bảng / màn hình / report nào mang ngữ nghĩa
stocktake / count / variance / adjustment.

`DEC-STK-001` **không bị huỷ**. Nó vẫn là bản nháp hợp lệ cho một release sau, nhưng **không có hiệu
lực** ở MVP hiện tại vì `Effective date = NULL` và `WWHN-25` còn `In Progress`. Không repository file
nào được trích `DEC-NO-STOCKTAKE-001` như một approved *standalone* Decision ID.

**Điều kiện duy nhất để re-enable** (không có đường tắt nào khác — giống hệt `DISP-STK-236` §2 để cả
hai nhánh mở cùng lúc, không lệch pha):

1. Approved **Change Request** nâng phạm vi MVP, **và**
2. `WWHN-25` được ghi `GO` hoặc `CONDITIONAL GO` với effective date cụ thể trên Jira, **và**
3. SRS được rebaseline: `REQ-SRS-037` phải được sửa/gỡ ở một version SRS mới có số hiệu và ngày, **và**
4. Chỉ khi đủ 1–3 mới mở DEV; `DEV233-P1-04`, `P1-05`, `P1-06` là gate của lần re-enable đó.

Trước khi đủ 4 điều kiện, mọi PR chạm vào bề mặt stocktake/count/variance/adjustment **fail closed**
tại `scripts/verify-wwhn233-count-disposition.mjs` (BE), `tests/Feature/Wwhn233CountDeferredGateTest.php`
(BE) và `scripts/verify-wwhn-233-closure.mjs` (FE).

---

## 3. Source register hiện hành (đóng `DEV233-P1-03`)

Reliance vào BRD/Handoff **v3.9** bị **gỡ bỏ** cho `WWHN-59` và mọi task con. SRS v1.12 dòng 225 đã
ghi `BRD v3.9 → BRD v3.20 = HISTORICAL / SUPERSEDED`, và dòng 228 ghi `Handoff v3.10 reference →
Removed; use BRD v3.20 + WWHN-333 and SRS v1.12 + WWHN-334 = REMOVED / NO ARTIFACT`.

ID lịch sử chỉ giữ để trace/tombstone, **không** được dùng làm baseline triển khai hay làm căn cứ AC.

Bản máy đọc được — `docs/evidence/WWHN-233/SOURCE_REGISTER.json` — là nguồn digest chính thức.
Digest được verifier tính lại tại mỗi lần chạy, nên bảng dưới đây không thể "pin rot" âm thầm:

| Source ID | File | Version | Vai trò với WWHN-233 |
|---|---|---|---|
| `SRS-1.12` | `docs/HN_SRS_FRD_v1.12_FULL.md` | v1.12 (WWHN-334) | **CONTROLLING** — REQ-SRS-037 |
| `ERD` | `docs/HoaNam_ERD_Moi_Nhat.md` | latest | Xác nhận chéo §0.1 |
| `WIREFRAME` | `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` | v3.20 / v1.12 | Xác nhận chéo — zero màn hình (FE repo) |
| `TECHSTACK` | `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` | current | Correction path = Reversal append-only |
| `BASELINE-CONTRACT` | `docs/contracts/HoaNam_Current_Baseline_Contract.json` | schema 4.1.0 | Machine gate — forbidden fragments + excluded tables |
| `OPENAPI-RUNTIME` | `storage/api-docs/api-docs.yaml` | sinh từ route thật | Bề mặt API thật — zero count/variance path |

**Historical — không được trích dẫn làm controlling contract:**

| Artifact | Ghi chú |
|---|---|
| BRD/Handoff v3.9 | Superseded bởi BRD v3.20 / SRS v1.12 (SRS:225, SRS:228) |
| `docs/decision-records/DEC-STK-001.md` | `PROPOSED`, `Effective date = NULL`; verifier assert trạng thái này không đổi |
| `docs/api-contract/WWHN-180-posting-openapi.{json,yaml}` | Immutable, digest `8922b027…` pin tại `docs/evidence/WWHN-180/IMMUTABLE_HANDOFF_MANIFEST.json` |
| `docs/postman/WWHN-131 Stocktake *.json` | Collection của bản nháp WWHN-131 |
| `docs/testing/WWHN-131-stocktake-test-cases.json` | Test case của bản nháp WWHN-131 |

> **KHÔNG sửa hai file WWHN-180.** Một immutable handoff artifact chỉ được **superseded bằng decision
> record trỏ tới nó** — chính là tài liệu này — chứ không bao giờ bằng cách sửa nội dung. Verifier
> assert digest `8922b027…` không đổi, nên hành vi "sửa cho khớp" sẽ fail gate thay vì âm thầm phá
> evidence chain của WWHN-180.

### AC disposition của parent `WWHN-59`

AC nào của `WWHN-59` còn yêu cầu count / recount / partial / stale của phiên kiểm kê **không còn hiệu
lực** và phải được BA rebaseline. Business intent "phát hiện và sửa lệch tồn" được map sang bề mặt
canonical dưới đây (kế thừa `DISP-ADJ-001` Option B và `DISP-STK-236` §3):

| Business intent | Canonical endpoint | Permission |
|---|---|---|
| Phát hiện lệch tồn | `GET /api/v1/reports/inventory-reconciliation` → sinh `reconciliation_incidents` | `report.view` |
| Truy vết một mã vật lý | `GET /api/v1/inventory/trace/{code}` | `inventory.trace` |
| Điều chỉnh một receipt đã post | `POST /api/v1/inbound-documents/{id}/reverse` | `inventory.reversal.post` |
| Điều chỉnh một issue đã post | `POST /api/v1/outbound-documents/{id}/reverse` | `inventory.reversal.post` |
| Tạo chứng từ điều chỉnh độc lập | `POST /api/v1/reversal-documents` | `inventory.reversal.post` |

Cả ba endpoint ghi đều yêu cầu `Idempotency-Key` + `If-Match`, ghi movement **append-only**
(`movement_type = 'REVERSAL'`) và phát sinh audit entry theo `BR-REV-02`.

---

## 4. Dependency chain (nhất quán với `DISP-STK-236` §4)

| Ticket | Disposition | Lý do |
|---|---|---|
| `WWHN-59` | **DEFERRED — rebaseline AC** | Parent story của capability bị `REQ-SRS-037` exclude |
| `WWHN-233` | **DEFERRED — NO CODE** | Tài liệu này |
| `WWHN-235` | **DEFERRED / N/A** — xem §8 | QA gate của một capability không build |
| `WWHN-25` / `DEC-STK-001` | **PROPOSED — giữ nguyên** | Gate re-enable, không tự đóng từ repo |
| `WWHN-60/234/236/238`, `HN-STK-002` | Theo `DISP-STK-236` | Cùng một điều kiện re-open ở §2 |

Không ticket nào ở trên được để trạng thái `Blocked` hay `Pending` mơ hồ: chúng chia sẻ **một** điều
kiện re-open duy nhất — bộ 4 điều kiện ở §2.

---

## 5. Handoff NO-CODE (đóng `DEV233-P1-02`)

`WWHN-233` **không được giữ ở trạng thái implementation-complete và cũng không được để trống**.
Register ghi đúng thực tế: 0 attachment, 0 PR/SHA, `search WWHN-233 = 0 commit`. Với disposition
DEFERRED thì đó là **kết quả đúng**, nhưng nó phải được ghi tường minh chứ không phải để suy đoán.

Handoff chính thức: `docs/evidence/WWHN-233/NO_CODE_HANDOFF.md`.

| Hạng mục | Giá trị |
|---|---|
| Kiểu handoff | **NO-CODE / DEFERRED** |
| Code thay đổi cho capability | **ZERO** — không route / controller / service / migration / permission / screen / report |
| Code thay đổi cho *gate* | Có — verifier + test + forbidden fragments (chỉ để chặn tái xuất hiện) |
| Bằng chứng | route surface, runtime OpenAPI, permission catalog, schema, FE route/permission/menu — tất cả zero |
| Exact SHA | pin tại `docs/evidence/WWHN-233/SOURCE_REGISTER.json` (`repository.be_commit_sha` / `repository.fe_commit_sha`) |

Thay đổi duy nhất được phép trong task này là **hàng rào**, không phải capability:

1. `docs/contracts/HoaNam_Current_Baseline_Contract.json` — bổ sung `recount`, `cycle-count`,
   `physical-count` vào `forbidden_path_fragments` (§1.2). Ba mảnh `stocktake` / `adjustment` /
   `variance` do nhánh WWHN-236 bổ sung được giữ nguyên, không sửa.
2. `scripts/verify-wwhn233-count-disposition.mjs` (BE) — verifier fail-closed, gồm `ANTI-DANGLING`.
3. `tests/Feature/Wwhn233CountDeferredGateTest.php` (BE) — khoá bằng test trên route/permission/schema thật.
4. FE: `scripts/verify-wwhn-233-closure.mjs` + `src/test/wwhn-233-count-deferral-gate.test.ts`.
5. FE: refresh digest trong `docs/api-evidence/STOCKTAKE_GOVERNANCE_STATUS.json` và nâng drift của
   corroborating baseline từ *note* lên *fail* (§3, chống pin rot).

---

## 6. `DEV233-P1-04` — contract count / recount / partial / stale

Mục này mở đầu bằng *"Nếu re-enable"*. Vì disposition là DEFERRED, nó **không phát sinh nghĩa vụ
dương ở MVP v1.12**, và được thay bằng **nghĩa vụ phủ định** kiểm chứng được:

| Nghĩa vụ khi DEFERRED | Kiểm chứng bằng |
|---|---|
| Runtime OpenAPI + Postman + permission matrix + baseline contract **không** khai endpoint/state/permission nào cho count, recount, partial, stale/CAS của phiên kiểm kê | `docs/evidence/WWHN-233/CONTRACT_NEGATIVE_LOCK.json` |
| Artifact historical (WWHN-180 OpenAPI, WWHN-131 Postman/test-case) giữ nguyên digest, không bị "sửa cho khớp" | verifier assert digest |
| `DEC-STK-001` — nơi *duy nhất* trong repo định nghĩa hợp đồng count/recount/stale — vẫn `PROPOSED`, `Effective date = NULL` | verifier assert chuỗi trạng thái |

Khi re-enable, mục này quay lại **đúng nguyên văn** yêu cầu ở register: khoá endpoint, state,
duplicate semantics, stale/CAS, count history, permission và error contract **trước** khi DEV/FE/QA
bắt đầu — và phải đóng bằng OpenAPI + Postman + schema/version được tech-approve, không phải bằng tài
liệu này.

---

## 7. `DEV233-P1-05` — stock effect / stale / partial boundary

Cũng là mục điều kiện. Nghĩa vụ phủ định tương ứng, và đây là phần mạnh nhất của disposition vì nó
được **DB enforce**, không phụ thuộc code tầng ứng dụng:

| Nghĩa vụ khi DEFERRED | Kiểm chứng bằng |
|---|---|
| **Không tồn tại** đường ghi tồn thứ hai. `chk_stock_movement_type_current` chỉ cho `OPENING / RECEIPT / ISSUE / COMPONENT_ISSUE / REVERSAL` ⇒ `ADJUST_IN` / `ADJUST_OUT` bị PostgreSQL từ chối ở tầng constraint | `Modules/Warehouse/database/migrations/2026_08_10_000620_remove_superseded_fields_and_lock_append_only_history.php:83` + gate test |
| Bốn bảng `stocktake_*` vắng mặt ở end-state schema | gate test (`Schema::hasTable` + `excluded_tables`) |
| Không có endpoint sửa tồn trực tiếp; correction path duy nhất là Reversal append-only, đã có row lock / CAS / idempotency riêng | `scripts/verify-canonical-stock-write-path.php` + `Wwhn219ApplyAdjustmentDispositionTest` |
| `stock_movements` là append-only ở PostgreSQL qua trigger `hn_reject_append_only_change()` | migration `…000620` §`addPostgresAppendOnlyGuards` |

Không có transaction/idempotency/reconcile report *dương* nào được yêu cầu ở MVP này, vì không có
transaction nào để đo. Khi re-enable, mục này phải đóng bằng API/DB race/retry/rollback/reconcile
report chạy trên đúng SHA.

---

## 8. QA disposition `WWHN-235` (đóng `DEV233-P1-06`)

Xem `docs/evidence/WWHN-233/QA_DISPOSITION_WWHN-235.md`.

Tóm tắt: `WWHN-235` được đặt **DEFERRED / N/A cho MVP v1.12**, **không** phải `Blocked`, **không**
phải `Pending`. QA1 không thực hiện exact-build test duplicate/partial/stale/RBAC cho một capability
đã bị controlling source exclude. Thay vào đó QA1 chạy **negative gate** — xác nhận capability *không*
tồn tại ở mọi tầng — và đó là toàn bộ phạm vi QA của WWHN-233 ở MVP này.

---

## 9. `DEV233-P2-01` — owner narrative

Description của `WWHN-233` còn ghi owner narrative là **Duy Khánh** trong khi live assignee là
**Quang**. Đây là placeholder lịch sử, không phải sự thật hiện hành.

Disposition: giữ tên cũ **chỉ** ở phần trace lịch sử và ghi rõ nhãn `historical placeholder`; phần
responsibility hiện hành phải trỏ current DEV owner. PM/BAS thực hiện trên Jira; repo không phải là
nơi lưu assignee. Bản ghi trace: `docs/evidence/WWHN-233/NO_CODE_HANDOFF.md` §Ownership.

---

## 10. Consequences

### Positive

- Chỉ còn **một** authority cho phạm vi kiểm kê: `REQ-SRS-037`. Vòng rà soát sau không phải mở lại
  `DEC-STK-001` hay WWHN-180 OpenAPI để đoán xem cái nào thắng.
- Lỗ hổng §1.2 — hàng rào được mô tả nhưng chưa tồn tại — bị đóng bằng file chạy được, và verifier
  **tự kiểm tra rằng chính nó tồn tại**, nên một disposition tương lai không thể lại trở thành lời hứa suông.
- Route adjustment/variance đặt tên khác `stocktake` không còn lọt gate.
- `WWHN-59/233/235` có **một** điều kiện re-open chung với `WWHN-60/234/236/238`, không lệch pha.

### Negative / cost

- Nghiệp vụ kiểm kê vật lý **không có** trong MVP. Lệch tồn chỉ phát hiện qua reconciliation report và
  chỉ sửa được bằng Reversal — tức phải truy về đúng chứng từ gốc sai. Không có đường "chỉnh thẳng số
  tồn". Đây là hệ quả có chủ đích của `REQ-SRS-037`, cần PM/PO xác nhận là chấp nhận được về vận hành.
- Thêm hai gate phải bảo trì (BE + FE).

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Vòng rà soát sau lại trích `DEC-STK-001` làm authority | §2 xếp hạng authority tường minh; verifier assert `DEC-STK-001` vẫn `PROPOSED` / `Effective date = NULL` |
| Disposition lại chỉ nằm trên giấy như `DISP-STK-236` §5 | Verifier assert sự tồn tại của chính bộ gate (self-check), và gate nằm trong CI |
| Ai đó "sửa cho khớp" hai file WWHN-180 | Verifier assert digest `8922b027…` không đổi ⇒ fail closed |
| Route mọc lại dưới tên khác | `forbidden_path_fragments` mở rộng + gate quét route thật từ router đã boot |
| `stocktake.adjust` mọc lại trong permission catalog | Test khoá permission ở cả BE và FE |
| Sửa tồn trực tiếp qua movement type | `chk_stock_movement_type_current` chặn ở DB; test assert constraint còn nguyên |
| Baseline đổi mà hash pin không đổi (pin rot) | Verifier **tính lại** digest mỗi lần chạy thay vì so với hằng số chép tay |

---

## 11. Approval and effective status

Current result: **PROPOSED / NO EFFECTIVE DATE**.

Bản ghi Jira bắt buộc trên `WWHN-233`:

```text
DISP-STK-233 version: 1.0.0
Controlling source: REQ-SRS-037 / HN_SRS_FRD_v1.12_FULL.md (WWHN-334)
Exact BE commit SHA: <sha>
Exact FE commit SHA: <sha>
Evidence: docs/evidence/WWHN-233/SHA256SUMS
BA Main + PM/PO (P1-01) disposition: <DEFERRED|RE-ENABLE via CR>
DEV → TL/QA (P1-02) no-code handoff accepted: <value>
BAS + BA (P1-03) source register accepted: <yes/no + date>
DEV + TL → BA/FE/QA (P1-04) contract negative lock accepted: <value>
DEV → QA1/TL/DBA (P1-05) stock-effect negative lock accepted: <value>
QA1 → BA/TL (P1-06) WWHN-235 disposition: <DEFERRED / N/A>
PM/BAS (P2-01) owner narrative synced: <yes/no>
Effective date: <ISO-8601 or NULL>
```

Guard ở §5 **active bất kể trạng thái phê duyệt**: kể cả khi disposition còn `PROPOSED`, CI vẫn chặn
việc tái xuất hiện bề mặt stocktake / count / variance / adjustment.

---

## 12. Phân giới với nhánh `WWHN-236` (đang chạy song song trên cùng `m1`)

Hai nhánh đóng hai nửa khác nhau của cùng một `REQ-SRS-037`. Ranh giới cố định để không ai ghi đè ai:

| Hạng mục | Chủ sở hữu | Ghi chú |
|---|---|---|
| `DISP-STK-236…md`, `scripts/verify-wwhn236-adjustment-disposition.mjs`, `docs/evidence/WWHN-236/*` | **WWHN-236** | WWHN-233 chỉ *đọc*, không sửa |
| `forbidden_path_fragments`: `stocktake`, `adjustment`, `variance` | **WWHN-236** | WWHN-233 giữ nguyên |
| `forbidden_path_fragments`: `recount`, `cycle-count`, `physical-count` | **WWHN-233** | Bổ sung phía sau, không đụng phần trên |
| `DISP-STK-233…md`, `scripts/verify-wwhn233-count-disposition.mjs`, `tests/Feature/Wwhn233CountDeferredGateTest.php`, `docs/evidence/WWHN-233/*` | **WWHN-233** | |
| FE `verify-wwhn-233-closure.mjs`, `STOCKTAKE_GOVERNANCE_STATUS.json` digest refresh | **WWHN-233** | |

Verifier của WWHN-233 **gọi** verifier của WWHN-236 như một bước con khi file đó có mặt, và chỉ
*cảnh báo* (không fail) khi chưa có — để hai nhánh merge theo thứ tự nào cũng không làm đỏ nhánh kia.
Sau khi cả hai đã merge, `ANTI-DANGLING` biến nó thành ràng buộc cứng.
