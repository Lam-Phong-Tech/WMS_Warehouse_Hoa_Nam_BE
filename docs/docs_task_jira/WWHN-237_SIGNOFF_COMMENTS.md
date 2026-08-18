# WWHN-237 — bộ comment xác nhận (sign-off) cho Jira

Mỗi vai trò **một comment duy nhất**, phạm vi chỉ là *đọc + duyệt*, không phát sinh việc mới.
Dán nguyên khối trích dẫn. Chỉ còn hai chỗ QA1 phải tự điền: driver (`<sqlite|pgsql>`) và kết quả (`<PASS|FAIL>`).

**Định danh dùng chung cho mọi comment** (lấy từ `docs/evidence/WWHN-237/SOURCE_REGISTER.json`):

- BE SHA: `62dcbc104290eced035e4288fe46f869238bafcd` (evidence pin: `897710e`) — branch `m1`
- FE SHA: `29c6fd4` trong lịch sử `m1` (HEAD quan sát khi chốt: `0eda8248ea121be10518f6e3edda0d2ca74b9c26`)
- Disposition: `DISP-STK-237` v1.0.0 — `docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md`
- Evidence: `docs/evidence/WWHN-237/` (`SOURCE_REGISTER.json`, `CONTRACT_NEGATIVE_LOCK.json`, `SHA256SUMS`, `logs/`)
- Kết quả gate: BE 11 check + 10 test · FE 17 check + 6 test · runner 6/6 lệnh PASS

---

## 1. DEV (assignee) — comment đóng phần source

> **DEV CLOSURE — WWHN-237**
>
> Tôi xác nhận đã xử lý register 32/100 tại source trên nhánh `m1`, BE `62dcbc10` / FE `29c6fd4`:
>
> - `DEV237-P1-01`: source conflict được chốt bằng `DISP-STK-237` — **Option A: DEFERRED / NO CODE**, neo vào `REQ-SRS-037` (Must, adopt tại WWHN-334), kèm thang authority, điều kiện re-enable và cập nhật affected ticket.
> - `DEV237-P1-02`: route/permission/entity vốn đã zero; task này gỡ nốt tàn dư **hợp đồng** còn sót — folder `12. Stocktake` trong Postman DEV và criticalFlow `APPLY_ADJUSTMENT` + enum `SCAN_CLASSIFY.context = STOCKTAKE` trong `WMS_FE_CONTRACT_BASELINE.json`. Guard `Flow05...test_published_contract_artifacts_carry_no_stocktake_capability` **đang đỏ sẵn trên m1** trước task này, nay xanh.
> - `DEV237-P1-03`: phát hành no-code closure (`docs/evidence/WWHN-237/NO_CODE_HANDOFF.md`) thay vì viết code variance.
> - `DEV237-P1-04`: chuẩn bị sẵn ma trận QA N1..N8 / R1..R4 + raw log; kết quả cuối thuộc QA1.
> - `DEV237-P1-05`: contract hiện hành được nêu đích danh và pin digest trong `SOURCE_REGISTER.json`.
>
> Không sửa pack immutable WWHN-180/WWHN-150 (theo `DISP-STK-236 §3`); digest của chúng được ghi vào `CONTRACT_NEGATIVE_LOCK.json`.
>
> Hai finding chuyển ticket khác: enum `ADJUSTMENT` trong `DEC-REV-001 1.3.0-proposed` → WWHN-26/WWHN-134; 3 checksum mismatch có sẵn trong `REPOSITORY_SHA256SUMS` → WWHN-180.
>
> Disposition: **SOURCE REMEDIATED / APPROVER SIGN-OFF PENDING**.

---

## 2. BA Main — `DEV237-P1-01` (và phần AC của `WWHN-60`)

> **BA SIGN-OFF — WWHN-237**
>
> Tôi xác nhận đã đọc và duyệt:
>
> - Controlling source cho phạm vi kiểm kê/variance là `REQ-SRS-037` (Must) của SRS/FRD v1.12, adopt tại `WWHN-334`, được ERD §0.1 (`Variance_Line`), wireframe MVP và Techstack Dev Guide xác nhận chéo.
> - Disposition của `WWHN-237` là **Option A — DEFERRED / NO CODE**: đóng dạng removal/deprecation closure, không implement variance query/report.
> - AC nào của `WWHN-60` / `WWHN-237` còn yêu cầu variance query/report là **không còn hiệu lực** và thuộc trách nhiệm BA rebaseline; `WWHN-131` chỉ là bản ghi lịch sử.
> - `DEC-NO-STOCKTAKE-001` **không** được dùng như approved standalone Decision ID; `WWHN-25 / DEC-STK-001` vẫn là gate riêng, chưa duyệt.
>
> Không phát sinh yêu cầu nghiệp vụ mới từ WWHN-237. Không còn blocker thuộc BA cho task này.

---

## 3. PM/PO — `DEV237-P1-01`

> **PM/PO SIGN-OFF — WWHN-237**
>
> Tôi xác nhận đã đọc và duyệt:
>
> - `WWHN-237` được **defer/re-scope theo NO-STOCKTAKE** (`DISP-STK-237`, Option A), không mở Change Request nâng phạm vi MVP ở thời điểm này.
> - Hệ quả vận hành đã được nêu rõ và tôi chấp nhận: MVP **không có** báo cáo chênh lệch kiểm kê; lệch tồn chỉ phát hiện qua `reconciliation_status` của RPT-INV-01 và chỉ sửa được bằng Reversal append-only truy về đúng chứng từ gốc.
> - Điều kiện tái mở là bộ 4 điều kiện tại `DISP-STK-237 §2` (CR được duyệt + `WWHN-25` đóng có effective date + SRS rebaseline + sau đó mới mở DEV).
> - `WWHN-237` giữ `PROPOSED / NO EFFECTIVE DATE` cho tới khi tôi ghi effective date; guard CI vẫn fail-closed trong lúc chờ.
>
> Không còn blocker thuộc PM/PO cho task này.

---

## 4. Tech Lead — `DEV237-P1-02`, `DEV237-P1-03`

> **TECH LEAD SIGN-OFF — WWHN-237**
>
> Tôi xác nhận đã đọc và duyệt trên BE `62dcbc10` / FE `29c6fd4`:
>
> - Bằng chứng absence phủ đủ tầng: route, report registry/hub/export, KPI dashboard, permission seed, schema, OpenAPI runtime, bộ contract bàn giao và hợp đồng FE — `CONTRACT_NEGATIVE_LOCK.json` + `FE_CLOSURE_IDENTITY.json`.
> - Ranh giới kỹ thuật đúng: **variance của reconciliation (balance vs ledger) được giữ nguyên** vì `AC-SRS-037` chỉ định đó là cơ chế thay thế; gate có check dương `RECONCILIATION-ALIVE` nên không ai gỡ nhầm.
> - Pack immutable WWHN-180/WWHN-150 **không bị sửa nội dung**, đúng nguyên tắc `DISP-STK-236 §3`; phần được miễn nằm trong allowlist hằng số, mở rộng phải qua review source.
> - Gate fail-closed đã vào CI: `wwhn-237-variance-report-deferral-gate.yml` (BE) và bước `wwhn_237` trong `ci.yml` + `assert-ci-gates.mjs` (FE).
>
> Tôi ghi nhận hai finding chuyển ticket khác (enum `ADJUSTMENT` của `DEC-REV-001 1.3.0-proposed` → WWHN-26/134; 3 checksum mismatch có sẵn → WWHN-180) và **không** tính chúng là blocker của WWHN-237.
>
> Không còn blocker kỹ thuật thuộc Tech Lead cho task này.

---

## 5. QA1 — `DEV237-P1-04` (và trạng thái `WWHN-238`)

> **QA1 SIGN-OFF — WWHN-237 / WWHN-238**
>
> Tôi xác nhận đã đọc và duyệt:
>
> - `WWHN-238` chuyển **DEFERRED / N-A cho MVP v1.12** — không để `Blocked`, không để `Pending`. Lý do: capability cần test đã bị `REQ-SRS-037` loại khỏi phạm vi, không có build nào sẽ chứa nó.
> - Phạm vi QA thay thế là **negative gate + regression**, đã có ma trận chạy được N1..N8 (capability vắng mặt) và R1..R4 (reconciliation + Reversal còn sống) tại `docs/evidence/WWHN-237/QA_DISPOSITION_WWHN-238.md`.
> - Kết quả chạy trên BE `62dcbc10` / FE `29c6fd4`, driver `<sqlite|pgsql>`: **`<PASS|FAIL>`** — raw log kèm exit code tại `docs/evidence/WWHN-237/logs/`, tổng hợp tại `SOURCE_REGISTER.json`.
> - Tôi không dùng kết quả của `WWHN-236` / `WWHN-235` thay cho `WWHN-238`.
>
> Điều kiện mở lại phạm vi test dương: đúng bộ 4 điều kiện tại `DISP-STK-237 §2`.

---

## 6. FE1 — `DEV237-P1-05` (consumer confirmation)

> **FE1 SIGN-OFF — WWHN-237**
>
> Tôi xác nhận đã đọc và duyệt trên FE `29c6fd4` / BE `62dcbc10`:
>
> - Hợp đồng FE hiện hành là `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json` **sau khi** gỡ criticalFlow `APPLY_ADJUSTMENT` (permission `stocktake.adjust`) và sửa `SCAN_CLASSIFY.context` về đúng enum runtime `INBOUND, OUTBOUND, INVENTORY, TRACE, WARRANTY_RECEIVE, WARRANTY_TEMP_ONLY`.
> - FE **không** suy diễn lại contract Stocktake từ artifact lịch sử (WWHN-180 pack, WWHN-150 scan OpenAPI, OpenAPI Changelog BE1 v1.0.1, Swagger Standard cũ); khối `removedFromMvp` trong file hợp đồng ghi rõ điều này.
> - FE không có route, permission, menu, màn hình hay report code variance; `npm run wwhn-237:verify` PASS 17 check và guard test PASS 6 test.
>
> Không còn blocker thuộc FE cho task này.

---

## 7. BAS — traceability (tuỳ chọn, nếu vai trò này tham gia WWHN-60)

> **BAS SIGN-OFF — WWHN-237**
>
> Tôi xác nhận chuỗi truy vết đã khớp một nguồn duy nhất:
> `SRC-008 → REQ-SRS-037 → BR-NOSTK-01..03 → AC-SRS-037 → TC-SRS-037`, hiện thực hoá bằng
> `DISP-STK-237` và hai gate BE/FE. Nguồn phái sinh (`DEC-STK-001`, WWHN-180 OpenAPI v1.7.0,
> BRD/Handoff v3.9, Changelog BE1 v1.0.1) đã được ghi rõ là PROPOSED hoặc historical, không phải
> controlling contract. Không còn blocker thuộc BAS.

---

## Ghi chú khi post

- SHA đầy đủ lấy từ `SOURCE_REGISTER.json → repositories.*.commit_sha`; khi dẫn link file dùng `blob/<sha>/<path>`, **không** dùng URL nhánh động.
- QA1 phải điền `<PASS|FAIL>` và driver thật; không post comment QA khi chưa có log tương ứng trong `logs/`.
- Không vai trò nào ghi `APPROVED` / `Done` / `100%` cho `WWHN-237` trước khi PM/PO ghi effective date.
