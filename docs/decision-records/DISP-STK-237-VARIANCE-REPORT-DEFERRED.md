# DISP-STK-237 — Disposition Variance Query/Report (WWHN-60 ↔ WWHN-237 ↔ WWHN-238)

## Document control

| Field | Value |
|---|---|
| Disposition ID | `DISP-STK-237` |
| Jira task | `WWHN-237` (child of `WWHN-60`; QA gate `WWHN-238`) |
| Register closed | `DEV237-P1-01` … `DEV237-P1-05` (R1) · `DEV237-P1-01R2` … `DEV237-P2-01R` (R2, xem §9) |
| Version | `1.1.0` (R1 `1.0.0` + §9 đóng register R2) |
| Status | **PROPOSED — chờ approver-authored disposition trên Jira** |
| Proposed by | DEV — WWHN-237 |
| Proposed date | `2026-08-18` |
| Required approvers | BA Main + PM/PO (`P1-01`), Tech Lead + QA1 (`P1-02`), BA + Tech Lead + QA1 (`P1-03`), QA1 (`P1-04`), FE1 + QA1 + BA (`P1-05`) |
| Effective date | `NULL — chỉ có sau formal sign-off trên WWHN-237` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` + `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE` |
| Canonical branch | `m1` |
| Bản ghi approval | `docs/evidence/WWHN-237/APPROVAL_RECORD.json` — **tách khỏi tài liệu này**; tài liệu DEV-authored không thay approval (§9.2) |
| Cặp SHA đóng băng | `docs/evidence/WWHN-237/CANDIDATE_PAIR.json` — hai bản byte-identical BE/FE (§9.1) |
| Machine gate | `node scripts/verify-wwhn237-variance-report-disposition.mjs` (BE) · `npm run wwhn-237:verify` (FE) |
| Test lock | `tests/Feature/Wwhn237VarianceReportDeferredGateTest.php` (BE) · `src/test/wwhn-237-variance-report-guard.test.ts` (FE) |
| Quan hệ với `DISP-STK-236` | **Nối tiếp** — 236 xử lý *hành động* Apply Adjustment, 237 xử lý *truy vấn/báo cáo* Variance. Không thay thế, không mâu thuẫn. |
| Quan hệ với `DISP-STK-230` / `DISP-STK-233` | Cùng chuỗi `WWHN-60`; cùng một điều kiện re-enable duy nhất (§2) |
| Quan hệ với `DISP-ADJ-001` | Kế thừa Option B — không có endpoint sửa tồn trực tiếp |

> Tài liệu này **không** tự tuyên bố APPROVED. Nó chốt một authority duy nhất và biến quyết định
> thành ràng buộc chạy được, để cùng một phát hiện không lặp lại ở vòng rà soát sau.

---

## 1. Vấn đề gốc rễ (root cause của điểm 32/100)

`WWHN-237` yêu cầu **truy vấn / báo cáo chênh lệch kiểm kê (variance)**: công thức variance,
filter, pagination, report output. Bảy mục trong register đều xoay quanh cùng một chỗ đứt gãy:

**AC của ticket mô tả một sản phẩm khác với sản phẩm mà baseline đang có hiệu lực định nghĩa.**

Đây không phải "chưa làm", mà là **source conflict**. Bằng chứng ở hai phía:

| Phía | Nguồn | Nói gì |
|---|---|---|
| Ticket | `WWHN-237` + parent `WWHN-60`, AC còn trích BRD/Handoff **v3.9** | Phải có variance query/report |
| Baseline | `REQ-SRS-037` (Must) — `docs/HN_SRS_FRD_v1.12_FULL.md` | "Không expose Stocktake/**Variance**, menu/API/permission/**entity**/**report** hoặc action sửa tồn trực tiếp" |

`AC-SRS-037` nói thẳng: *"deployment v1.12 không chứa capability legacy; reconciliation chỉ tạo
incident, sửa sai dùng reversal theo WWHN-26."* `REQ-SRS-031` / `AC-SRS-031` nhắc lại ở tầng báo
cáo: *"không có stocktake report."*

Vì vậy `DEV237-P1-03` ghi nhận đúng hiện trạng — `stocktake_variance = 0`, không có
query/report variance nào được implement — nhưng **đó là hành vi đúng, không phải thiếu sót**.
Cái thiếu là **disposition** và **bằng chứng chạy được** cho disposition đó.

### 1.1 Nguồn phái sinh còn nhắc Variance (đã hạ historical, liệt kê để không mở lại)

| Nguồn | Trạng thái |
|---|---|
| `docs/decision-records/DEC-STK-001.md` (WWHN-25) | `PROPOSED / NO EFFECTIVE DATE` — chưa bao giờ duyệt |
| `docs/api-contract/WWHN-180-posting-openapi.{json,yaml}` v1.7.0 | `PROPOSED / PENDING_INDEPENDENT_SIGN_OFF`; **immutable**, superseded bằng decision record theo `DISP-STK-236 §3` — **không sửa nội dung** |
| `docs/postman/WWHN-180 Canonical Contract.postman_collection.json` + `.postman_environment.json` | Cùng pack immutable trên; xem §4 |
| `docs/evidence/WWHN-180/permission-catalog.json`, `BE_FE_PERMISSION_MAPPING.json` | Cùng pack immutable trên |
| `docs/testing/WWHN-131-stocktake-test-cases.json`, `docs/docs_task_jira/WWHN-131*` | Bản ghi lịch sử của WWHN-131; không phải test contract hiện hành |
| `docs/api-contract/WMS_HoaNam_OpenAPI_Changelog_BE1.{md,txt}` v1.0.1 | Historical theo `HN-BE-API-001:192` |
| BRD/Handoff v3.9 | Superseded bởi BRD v3.20 / SRS v1.12 (SRS revision history dòng 659) |

---

## 2. Chốt authority và disposition (đóng `DEV237-P1-01`)

**Controlling source duy nhất là `REQ-SRS-037`** của `HN_SRS_FRD_v1.12_FULL.md`, adopt tại
`WWHN-334`, priority `Must`, truy vết `SRC-008 → REQ-SRS-037 → BR-NOSTK-01..03 → AC-SRS-037 →
TC-SRS-037`, xác nhận chéo bởi:

- `HoaNam_ERD_Moi_Nhat.md` §0.1 — *"Không tạo Stocktake_Session, Stocktake_Snapshot,
  Stocktake_Count, **Variance_Line**, Adjustment hoặc **report/permission tương ứng**."*
- `HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — zero màn hình/menu variance.
- `WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — *"Stocktake/Variance/Adjustment are outside
  current MVP; reconciliation + append-only Reversal is the correction path."*
- SRS §5.3 — *"Không có Stocktake Session/Snapshot/Count/**Variance**/direct stock edit."*

**Xếp hạng authority** (giữ nguyên thang của `DISP-STK-236 §2`):

```text
REQ-SRS-037 (SRS v1.12, ADOPTED, Must)
  >  ERD §0.1 / Techstack Dev Guide / Wireframe MVP      (xác nhận chéo, cùng baseline)
  >  DEC-STK-001 (WWHN-25)                               (PROPOSED, no effective date)
  >  WWHN-180 posting OpenAPI + pack v1.7.0              (PROPOSED, pending independent sign-off)
  >  BRD/Handoff v3.9 + Changelog BE1 v1.0.1             (SUPERSEDED / historical)
```

### Disposition — Option A

Register `DEV237-P1-01` đưa hai lựa chọn. **Chọn (A): `WWHN-237` được re-scope thành
removal/deprecation closure — DEFERRED, NO CODE.**

Lý do không chọn (B) "approve Later Phase/CR có effective date trước DEV implementation":
một Change Request nâng phạm vi MVP là quyết định của PM/PO + Client, **không** phải thứ DEV
tự tạo ra để hợp thức hoá code. Chừng nào chưa có CR, viết code variance sẽ dọn được một cờ
"thiếu chức năng" nhưng tạo ra một vi phạm governance `Must` — đúng lỗi mà `DISP-ADJ-001` đã
từ chối.

Cụ thể, ở MVP v1.12 **không** tạo bất kỳ thứ nào sau đây:

- entity/bảng `stocktake_*`, `variance_*`, `*_variance`;
- route/endpoint chứa mảnh `stocktake`, `variance`, `adjustment`;
- `report_code`, mục trong `/reports/hub`, cột export hay field KPI dashboard mang ngữ nghĩa
  variance/chênh lệch kiểm kê;
- permission `stocktake.*`, `variance.*`;
- màn hình, menu entry hay route FE tương ứng.

`DEC-STK-001` **không bị huỷ** — vẫn là bản nháp hợp lệ cho một release sau, chỉ là **không có
hiệu lực** ở MVP hiện tại vì chưa có effective date.

### Điều kiện re-enable (dùng chung cho `WWHN-60/230/233/236/237/238`)

1. Approved **Change Request** nâng phạm vi MVP, **và**
2. `WWHN-25` được ghi `GO` / `CONDITIONAL GO` với effective date cụ thể trên Jira, **và**
3. SRS rebaseline: `REQ-SRS-037` được sửa/gỡ ở một version SRS mới có số hiệu và ngày, **và**
4. Chỉ khi đủ 1–3 mới mở DEV; khi đó `DEV237-P1-03` quay lại đúng nguyên văn (công thức /
   filter / pagination / report theo approved contract) và phải đóng bằng evidence dương.

Trước khi đủ 4 điều kiện, mọi PR chạm bề mặt variance/stocktake **fail closed** tại
`scripts/verify-wwhn237-variance-report-disposition.mjs` và
`tests/Feature/Wwhn237VarianceReportDeferredGateTest.php`.

### Cập nhật affected ticket

| Ticket | Disposition |
|---|---|
| `WWHN-237` | **DEFERRED — NO CODE**, đóng dạng removal/deprecation closure |
| `WWHN-238` (QA gate) | **DEFERRED / N/A cho MVP v1.12** — không `Blocked`, không `Pending`; xem §6 |
| `WWHN-60` (parent) | AC nào yêu cầu variance query/report **không còn hiệu lực**, cần BA rebaseline |
| `HN-STK-002`, `WWHN-233/234/235/236` | Giữ nguyên disposition đã ghi tại `DISP-STK-233` / `DISP-STK-236` |
| `WWHN-131` | Artifact test-case/Postman kiểm kê là **historical**, không phải contract hiện hành |

---

## 3. Bằng chứng absence — đóng `DEV237-P1-02` và `DEV237-P1-03`

Bề mặt được quét và kết quả bắt buộc là **zero** (gate tự chạy lại mỗi lần CI):

| Tầng | Nguồn kiểm | Kỳ vọng |
|---|---|---|
| Route | `Modules/Warehouse/routes/api.php` + route table runtime | 0 route chứa `stocktake` / `variance` / `adjustment` |
| Report registry | `OperationalReportController::REPORT_BUILDERS`, `ALIGNED_REPORTS`, `EXPORT_DOWNLOAD_PATHS`, `hub()` | 9 report code, 0 mã variance |
| Report dispatcher | `GET /api/v1/reports/{report_code}` | `report_code=stocktake-variance` → `404 RESOURCE_NOT_FOUND` |
| Dashboard KPI | `OperationalReportController::dashboardQuick` | 0 field `stocktake_variance` / `variance_*` |
| Permission | bảng `permissions` sau seed | 0 key `%stocktake%` / `%variance%` |
| Entity | schema sau migrate | 0 bảng `stocktake_session/snapshot/count/variance` |
| Movement type | `chk_stock_movement_type_current` | chỉ `OPENING/RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL` |
| OpenAPI runtime | `storage/api-docs/api-docs.{json,yaml}` | 0 path/tag/enum variance |
| Contract bàn giao hiện hành | `docs/postman/**` trừ pack immutable WWHN-180 | 0 request/biến variance |
| FE contract baseline | `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json` | 0 criticalFlow / permission / scan-context variance |
| FE route + permission + menu | `src/shared/constants/routes.ts`, `wms-permissions.ts`, `src/app/layouts/menu.tsx` | 0 entry variance |

**Đường sửa sai duy nhất** (kế thừa `DISP-ADJ-001` Option B, không đổi):

| Business action | Canonical endpoint | Permission |
|---|---|---|
| Phát hiện lệch tồn | `GET /api/v1/reports/inventory-balance` (RPT-INV-01, `reconciliation_status`) và `GET /api/v1/reports/inventory-reconciliation` | `report.view` |
| Ghi nhận lệch | `reconciliation_incidents` (chỉ tạo incident, không sửa tồn) | — |
| Sửa sai sau Post | `POST /api/v1/inbound-documents/{id}/reverse`, `POST /api/v1/outbound-documents/{id}/reverse`, `POST /api/v1/reversal-documents` | `inventory.reversal.post` |

---

## 4. Xử lý pack immutable WWHN-180 (ranh giới của `DEV237-P1-02`)

`DISP-STK-236 §3` đã chốt: một immutable handoff artifact **chỉ được superseded bằng decision
record trỏ tới nó, không bao giờ bằng cách sửa nội dung**. Tài liệu này giữ nguyên nguyên tắc đó.

**Không sửa** (digest giữ nguyên, gate ghi ra lock để reviewer nhìn thấy):

- `docs/api-contract/WWHN-180-posting-openapi.json` / `.yaml`
- `docs/postman/WWHN-180 Canonical Contract.postman_collection.json` / `.postman_environment.json`
- `docs/evidence/WWHN-180/permission-catalog.json`, `BE_FE_PERMISSION_MAPPING.json`,
  `IMMUTABLE_HANDOFF_MANIFEST.json`, `REPOSITORY_SHA256SUMS`
- `docs/api-contract/WWHN-150-scan-openapi.yaml`

**Đã sửa** (không thuộc pack immutable, là contract bàn giao đang sống):

| File | Thay đổi |
|---|---|
| `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | Gỡ folder `12. Stocktake` và request `List stocktake sessions` |
| FE `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json` | Gỡ criticalFlow `APPLY_ADJUSTMENT` (`stocktake.adjust`); sửa `SCAN_CLASSIFY.context` về đúng enum runtime BE (bỏ `STOCKTAKE`) |
| FE `src/styles/hn-wms-page-normalize.css` | Gỡ selector chết `.stocktake-page` |
| FE `src/shared/hooks/usePermission.ts` | Sửa doc-comment còn mô tả `stocktake.manage` / `stocktake.adjust` như quyền đang tồn tại |
| FE `docs/api-evidence/STOCKTAKE_GOVERNANCE_STATUS.json` | Ghi `scopeDisposition` của WWHN-237 |

`tests/Feature/Flow05InventoryControlAlignmentTest::test_published_contract_artifacts_carry_no_stocktake_capability`
trước đó **đang đỏ** trên `m1` vì quét cả pack immutable. Guard được làm chính xác lại: phạm vi
là *bộ bàn giao hiện hành*, còn pack immutable WWHN-180 nằm trong allowlist historical tường minh
kèm lý do — cùng cơ chế `HISTORICAL_SPEC_ALLOWLIST` mà `verify-wwhn236-adjustment-disposition.mjs`
đã dùng. Allowlist **không** làm yếu guard: mọi file khác vẫn fail-closed, và digest của pack
được ghi vào evidence để reviewer thấy chính xác cái gì được miễn và vì sao.

---

## 5. Handoff NO-CODE và contract disposition (đóng `DEV237-P1-05`)

`WWHN-237` **không** được giữ ở trạng thái implementation-complete. Handoff chính thức:
`docs/evidence/WWHN-237/NO_CODE_HANDOFF.md`.

| Hạng mục | Giá trị |
|---|---|
| Kiểu handoff | **NO-CODE / DEFERRED** |
| Code thay đổi cho capability | **ZERO** — không route / controller / service / migration / permission / report code / màn hình |
| Code thay đổi cho *gate* | Có — verifier + test + dọn tàn dư contract (chỉ để chặn tái xuất hiện) |
| Contract disposition hiện hành | OpenAPI runtime `storage/api-docs/api-docs.json` (sinh từ route thật) + `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` + FE `WMS_FE_CONTRACT_BASELINE.json` — cả ba **zero** bề mặt variance |
| Consumer confirmation | FE1 + QA1 ký trên cùng exact BE/FE SHA ghi tại `docs/evidence/WWHN-237/SOURCE_REGISTER.json` |

Không dùng `Swagger Standard` cũ hay OpenAPI changelog BE1 v1.0.1 thay cho SRS hiện hành.

---

## 6. QA disposition `WWHN-238` (đóng `DEV237-P1-04`)

Xem `docs/evidence/WWHN-237/QA_DISPOSITION_WWHN-238.md`.

`WWHN-238` đặt **DEFERRED / N/A cho MVP v1.12** — **không** `Blocked`, **không** `Pending`.
QA1 không chạy exact-build test cho một capability đã bị controlling source exclude. Phạm vi QA
của WWHN-237 là **negative gate + regression**:

1. xác nhận capability variance *không* tồn tại ở mọi tầng ở §3;
2. xác nhận đường thay thế vẫn sống: reconciliation tạo incident, Reversal append-only vẫn
   post được và vẫn bị RBAC/idempotency/CAS chặn đúng.

Cả hai chạy được bằng một lệnh, output raw log đi kèm exact SHA/env — xem
`docs/evidence/WWHN-237/README.md`.

---

## 7. Consequences

### Positive

- Chuỗi `WWHN-60/230/233/236/237/238` có **một** authority và **một** điều kiện re-open chung.
- Bề mặt variance bị khoá ở tầng report/registry/KPI — chỗ mà gate của 233/236 chưa phủ.
- Guard `Flow05` hết đỏ mà không phải sửa pack immutable, nên evidence chain WWHN-180 còn nguyên.

### Negative / cost

- Không có báo cáo chênh lệch kiểm kê trong MVP. Lệch tồn chỉ thấy qua `reconciliation_status`
  của RPT-INV-01 và chỉ sửa được bằng Reversal truy về đúng chứng từ gốc sai. Đây là hệ quả có
  chủ đích của `REQ-SRS-037`, cần PM/PO xác nhận chấp nhận được về vận hành.
- Thêm một cặp gate (BE + FE) phải bảo trì.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Vòng rà soát sau lại trích `DEC-STK-001` hoặc WWHN-180 OpenAPI làm authority | §2 xếp hạng authority tường minh; verifier assert `DEC-STK-001` vẫn `PROPOSED` |
| Report variance mọc lại dưới tên khác (`discrepancy`, `chênh lệch`, `gap`) | Verifier quét cả registry, hub, export path và KPI field theo danh sách mảnh cấm |
| Ai đó "sửa cho khớp" pack immutable WWHN-180 | Verifier ghi digest pack vào lock; §4 nêu rõ chỉ được superseded bằng decision record |
| Allowlist historical bị nới rộng âm thầm | Allowlist là hằng số trong verifier; thêm phần tử là thay đổi source có review |
| FE suy diễn lại contract Stocktake từ artifact cũ | `WMS_FE_CONTRACT_BASELINE.json` mang khối `removedFromMvp`; `npm run wwhn-237:verify` fail-closed |

---

## 8. Approval and effective status

Current result: **PROPOSED / NO EFFECTIVE DATE**.

Bản ghi Jira bắt buộc trên `WWHN-237`:

```text
DISP-STK-237 version: 1.0.0
Controlling source: REQ-SRS-037 / HN_SRS_FRD_v1.12_FULL.md (WWHN-334)
Exact BE commit SHA: <sha>
Exact FE commit SHA: <sha>
Evidence: docs/evidence/WWHN-237/SHA256SUMS
BA Main + PM/PO (P1-01) disposition: <DEFERRED | RE-ENABLE via CR>
Tech Lead + QA1 (P1-02) absence proof accepted: <yes/no + date>
BA + Tech Lead + QA1 (P1-03) no-code closure accepted: <value>
QA1 (P1-04) negative gate + regression result: <PASS/FAIL + raw log>
FE1 + QA1 + BA (P1-05) contract disposition confirmed on same version: <value>
Effective date: <ISO-8601 or NULL>
```

Guard ở §3 **active bất kể trạng thái phê duyệt**: kể cả khi disposition còn `PROPOSED`, CI vẫn
chặn việc tái xuất hiện bề mặt variance/stocktake.

---

## 9. Đóng register vòng R2 (67/100) — `DEV237-P1-01R2` … `DEV237-P2-01R`

Vòng R1 đóng bằng tài liệu; vòng R2 chỉ ra rằng **bản thân bộ bằng chứng có lỗi**, không phải
capability. Ba lỗi thật và cách sửa tận gốc:

### 9.1 `DEV237-P1-02R2` — hai file định danh khai **hai cặp SHA khác nhau** (lỗi thật)

`scripts/run-wwhn-237-evidence.mjs` và FE `scripts/verify-wwhn-237-closure.mjs` đều ghi
`git rev-parse HEAD` **tại thời điểm chạy**. Hai repo chạy lệch giờ nên bộ evidence R1 khai:

| File | BE SHA | FE SHA |
|---|---|---|
| BE `SOURCE_REGISTER.json` | `809d4839…` | `f9f2e65e…` |
| FE `FE_CLOSURE_IDENTITY.json` | `22564b46…` | `cf0fe8d0…` |

Bốn SHA cho một cặp — reviewer không có bản nào để tái lập. Sửa:

- Thêm `docs/evidence/WWHN-237/CANDIDATE_PAIR.json`, **hai bản byte-identical** ở BE và FE.
- Candidate **không phải HEAD**: `candidate = git log -1 --format=%H -- <artifact_scope>`
  (commit cuối chạm tập artifact do WWHN-237 sở hữu). `m1` chạy tiếp cho task khác thì pin
  vẫn đúng, và pin **tự kiểm được** — gate giải lại rồi so, lệch là fail.
- Vòng gà-trứng cắt bằng `excluded_from_scope: docs/evidence/WWHN-237/**` (cùng cơ chế
  `excluded_from_digest` của `WWHN-290`): file pin không nằm trong tập nó pin.
- `--expect-be-sha` / `--expect-fe-sha` có ở **cả hai** gate để reviewer khoá đúng cặp.
- Supersede `m1-be-2` → `m1` là **số đo** (`rev-list --left-right --count`,
  `merge-base --is-ancestor`) ghi vào `branch_supersession`, không phải câu khẳng định chép lại.

Scope **cố ý không** gồm `docs/postman/**` và FE `WMS_FE_CONTRACT_BASELINE.json`: chúng thuộc
task khác và còn sống, đưa vào scope là tự chuốc pin rot — đúng bẫy `DISP-STK-236` đã ghi. Chúng
được canh ở `CONTRACT_DIGEST_PIN.json` bằng bất biến bề mặt (xem 9.4).

### 9.2 `DEV237-P1-01R2` — tách approval khỏi disposition

Tài liệu này là **DEV-authored**; register R2 nói thẳng nó không thay thế approval. Nay bằng
chứng approval là một artifact riêng, `docs/evidence/WWHN-237/APPROVAL_RECORD.json`, với ba slot
(`Process Owner/Client`, `PM/PO`, `BA Main`) và check `APPROVAL-AUTHORITY` fail-closed khi:

- `approval_state = APPROVED` mà còn slot chưa duyệt, hoặc thiếu `effective_date`;
- một slot khai `APPROVED`/`REJECTED` mà thiếu approver, `jira_comment_ref`, hoặc `decided_at`;
- người duyệt mang vai trò DEV, hoặc `approver` trùng `authored_by` (self-approval);
- tài liệu này tự khai `Status: APPROVED` trong khi bản ghi approval còn `PENDING`;
- bản ghi không nêu `WWHN-132 ATT-11898` — input authority bắt buộc phải được trích dẫn.

`ATT-11898` là attachment Jira, **không** nằm trong repo, nên gate không hash được nó; bằng chứng
là comment approver-authored có trích đúng attachment id + cặp SHA. Điều này được ghi thẳng trong
`source_authority_inputs` thay vì giả vờ đã kiểm.

### 9.3 `DEV237-P1-04R2` — ma trận QA từng dòng + raw API/DB

R1 gộp cả bộ vào một `php artisan test --filter=…`, nên không đọc được N1..N8/R1..R4 từng mục.
Nay `scripts/run-wwhn237-qa-matrix.mjs` chạy **từng dòng một**, mỗi dòng một raw log riêng, cộng:

- `RAW-API` — `curl` thật vào runtime container: `/api/v1/stocktake-sessions`,
  `/api/v1/inventory/apply-adjustment`, `/api/v1/variance-lines` trả `404 RESOURCE_NOT_FOUND`;
  `/api/v1/reports/stocktake-variance` trả `401 UNAUTHENTICATED` vì khớp catch-all
  `reports/{report_code}` và auth chặn trước — việc dispatcher từ chối mã variance được chứng
  minh ở `N2` (PHPUnit, có xác thực). Ghi rõ như vậy trong log để QA1 không đọc nhầm 401 thành
  "route tồn tại".
- `RAW-DB` — `psql` **read-only** vào DB runtime PostgreSQL: 0 permission
  `%stocktake%`/`%variance%`/`%adjustment%`, 0 bảng tương ứng, và
  `chk_stock_movement_type_current` đúng 5 giá trị `OPENING/RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL`.

Check `QA-MATRIX` **anti-dangling** theo luật `DISP-STK-233`: một dòng khai `PASS` mà raw log
không tồn tại hoặc rỗng là dòng chép tay — fail. `N/A` bắt buộc có `na_reason`.
`executed_at_pair` phải trùng `CANDIDATE_PAIR.json`.

`N7` là gate của chính bộ evidence này nên chạy **hai lần**: pass 1 bootstrap (lúc ma trận chưa
tồn tại), pass 2 sau khi ma trận đã ghi — kết quả có giá trị là pass 2. Không làm vậy thì hai
artifact tham chiếu vòng tròn nhau.

> Bản chạy trong repo là **DEV pre-run**, dùng để QA1 tái lập bằng một lệnh. Nó **không** thay
> QA1: trường `executed_by_role` phải được QA1 đổi thành tên mình khi ký.

### 9.4 `DEV237-P1-05R2` — pin cùng version, nhưng không hard-pin artifact của task khác

`docs/evidence/WWHN-237/CONTRACT_DIGEST_PIN.json` ghi digest + version của OpenAPI runtime,
Postman DEV collection, FE contract baseline, FE governance status và pack immutable WWHN-180,
tất cả **tại đúng cặp SHA đã pin**, kèm `blob_sha256_at_pair` để reviewer phân biệt nội dung
working tree với nội dung tại commit.

Phân loại `pin_class` là chỗ dễ làm sai:

| Lớp | Ví dụ | Drift digest |
|---|---|---|
| `owned` | tài liệu này | **FAIL** |
| `immutable` | `WWHN-180-posting-openapi.json` | **FAIL** (và không áp bất biến bề mặt — pack mô tả capability lịch sử) |
| `consumed` | Postman DEV, OpenAPI runtime, FE baseline | FAIL **chỉ khi** bề mặt cấm quay lại; drift bytes thuần → NOTE + đòi refresh confirmation |

Hard-pin digest artifact do task khác sở hữu chính là bẫy `DISP-STK-236` đã ghi: gate đỏ oan cho
mọi PR không liên quan. Nhưng "ba consumer xác nhận cùng một version" vẫn được giữ, vì digest
*được đóng băng trong bản ghi* — nếu artifact đổi, NOTE nói thẳng rằng confirmation phải xin lại.

Ba slot `independent_confirmations` (`Tech Lead`, `BA`, `QA1`) fail-closed khi khai `CONFIRMED`
mà thiếu người ký / comment Jira / cặp SHA, hoặc khi `confirmed_by` chứa `DEV` — FE
self-confirmation của cùng DEV không tính là independent.

### 9.5 `DEV237-P1-03R2` và `DEV237-P2-01R`

`P1-03R2` **phụ thuộc** `P1-01R2`: không implement variance. Kết quả NO-CODE đã có ở
`docs/evidence/WWHN-237/NO_CODE_HANDOFF.md`; nó chỉ **có hiệu lực** khi `APPROVAL_RECORD.json`
chuyển `APPROVED`. Nếu authority đổi sang Later Phase/CR thì mở lại ở dạng positive
implementation, và tài liệu này bị superseded bằng một decision record mới trỏ tới nó — không
sửa nội dung (luật `DISP-STK-236 §3`).

`P2-01R` là thao tác metadata trên Jira (chuẩn hoá historical BE2/Duy Khánh → DEV/Quang, giữ
audit history). Không có phần code; ghi ở đây để register có một nơi duy nhất tra cứu.

### 9.6 Lệnh tái lập vòng R2

```bash
node scripts/build-wwhn237-candidate-pair.mjs
node scripts/build-wwhn237-contract-digest-pin.mjs
node scripts/run-wwhn237-qa-matrix.mjs
node scripts/verify-wwhn237-variance-report-disposition.mjs --emit-lock
node scripts/run-wwhn-237-evidence.mjs
```
