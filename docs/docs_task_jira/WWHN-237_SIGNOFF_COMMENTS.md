# WWHN-237 — bộ comment xác nhận (sign-off) cho Jira

Mỗi vai trò **một comment duy nhất**, phạm vi chỉ là *đọc + duyệt*, không phát sinh việc mới.
Dán nguyên khối trích dẫn.

**Định danh dùng chung cho mọi comment** (làm mới ngày 2026-08-19, nguồn
`docs/evidence/WWHN-237/SOURCE_REGISTER.json` — pin theo **commit cuối cùng chạm artifact
WWHN-237**, không phải HEAD tức thời, vì `m1` có nhiều phiên commit song song hàng chục lần
mỗi giờ; muốn tái lập đúng bản đã kiểm thì dùng digest trong `baselines`/`SHA256SUMS`, không
suy luận từ khoảng cách tới HEAD):

- Nhánh canonical: **`m1`** (cả BE lẫn FE). `m1-be-2` **không phải** nhánh sống — nó là ancestor
  thuần của `m1` (`git merge-base --is-ancestor origin/m1-be-2 m1` = true, 0 commit vượt trước,
  xem `docs/decision-records/DISP-OUT-198-CANONICAL-BRANCH.md §1`), dừng lại từ `2026-08-15` nên
  vẫn còn `stocktake/sessions` + `apply-adjustment` — bản đã bị `m1` gỡ từ lâu. Bất kỳ tham chiếu
  Jira nào tới `m1-be-2` cho WWHN-237/238 đều **trỏ sai nhánh**, cần sửa thành `m1`.
- BE SHA: `809d483981f5f323fb8b30c90bed48a73bceffbc` — branch `m1`
- FE SHA: `f9f2e65e687669f033d259a693635546876f05a8` — branch `m1`
- Disposition: `DISP-STK-237` v1.0.0 — `docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md`
- Evidence: `docs/evidence/WWHN-237/` (`SOURCE_REGISTER.json`, `CONTRACT_NEGATIVE_LOCK.json`,
  `FE_CLOSURE_IDENTITY.json`, `SHA256SUMS`, `logs/*.txt`, `NO_CODE_HANDOFF.md`)
- Kết quả gate (chạy lại 2026-08-19, raw log trong `logs/`): BE disposition gate 11/11 check PASS
  (đã sửa 1 false positive — xem `NO_CODE_HANDOFF.md §3.3`) · BE regression
  `Wwhn237VarianceReportDeferredGateTest` + `Flow05InventoryControlAlignmentTest` 20/20 test PASS ·
  sibling gate WWHN-236 11/11 PASS · FE closure gate 17/17 check PASS · FE guard test 6/6 PASS ·
  runner tổng **5/6 lệnh PASS** — lệnh thứ 6 (`fe-api-mapping`) FAIL vì `BE_CONTRACT_PIN.json` lệch
  pin với BE hiện hành, **không liên quan Stocktake/variance** (xem finding routed to `WWHN-309`
  trong `NO_CODE_HANDOFF.md §4`), không phải blocker của WWHN-237.

---

## 1. DEV (assignee) — comment đóng phần source

> **DEV CLOSURE — WWHN-237 (re-review 2026-08-19, cập nhật sau lần đánh giá 63/100)**
>
> Tôi xác nhận đã xử lý tiếp register `DEV237-P1-01R..05R` + `P2-01` tại source trên nhánh `m1`, BE `809d4839` / FE `f9f2e65e`:
>
> - `DEV237-P1-01R`: giữ nguyên `DISP-STK-237` Option A đã chốt trước đó; không có phát sinh source mới. Chờ đúng BA/PM/PO ghi disposition trên Jira — DEV không tự ý đóng phần này.
> - `DEV237-P1-02R`: xác nhận bằng lệnh git là **`m1-be-2` chỉ là ancestor thuần của `m1`** (0 commit vượt trước, dừng từ 2026-08-15), không phải nhánh sống — mọi tham chiếu Jira tới `m1-be-2` cần sửa thành `m1` + SHA `809d4839` (BE) / `f9f2e65e` (FE) ở trên, cùng kết luận đã ghi tại `DISP-OUT-198-CANONICAL-BRANCH.md` cho WWHN-198. Đồng thời sửa 1 false-positive thật trong `scripts/verify-wwhn237-variance-report-disposition.mjs` (check `CONTRACT-SURFACE`): gate cũ so needle raw string nên báo FAIL nhầm trên pack sống WWHN-190 v1.8.0 (đang ghi lại việc gỡ apply-adjustment bằng `x-removed-surfaces`, tức bằng chứng tuân thủ chứ không phải capability) — đã sửa để gỡ khối tường thuật trước khi so needle, verify lại vẫn bắt đúng khi tiêm path sống thật. Chi tiết `NO_CODE_HANDOFF.md §3.3`.
> - `DEV237-P1-03R`: nội dung no-code closure không đổi; effective date vẫn chờ P1-01R.
> - `DEV237-P1-04R`: chạy lại toàn bộ ma trận N1..N8/R1..R4 trên SHA hiện hành, raw log mới trong `logs/` — kết quả thật ở mục QA1 bên dưới; QA1 chỉ cần đọc log và post, không cần chạy lại từ đầu.
> - `DEV237-P1-05R`: `SOURCE_REGISTER.json`/`FE_CLOSURE_IDENTITY.json` làm mới; nội dung `WMS_FE_CONTRACT_BASELINE.json` không đổi từ lần trước (đối chiếu git diff = rỗng), chỉ SHA neo bị cũ.
> - `DEV237-P2-01`: narrative Jira Description còn ghi "BE2/Duy Khánh" — đây là dữ liệu Jira, DEV không có quyền sửa mô tả ticket; đề nghị PM cập nhật theo comment PM/DEV bên dưới.
>
> Không sửa pack immutable WWHN-180/WWHN-150 (theo `DISP-STK-236 §3`); digest của chúng được ghi vào `CONTRACT_NEGATIVE_LOCK.json`.
>
> Finding mới chuyển ticket khác (không phải blocker của WWHN-237): FE `check:api-mapping` FAIL vì `BE_CONTRACT_PIN.json` lệch pin với BE hiện hành (không liên quan Stocktake) → `WWHN-309`. Hai finding cũ giữ nguyên: enum `ADJUSTMENT` trong `DEC-REV-001 1.3.0-proposed` → WWHN-26/WWHN-134; 3 checksum mismatch có sẵn trong `REPOSITORY_SHA256SUMS` → WWHN-180.
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

## 3. PM/PO — `DEV237-P1-01R`, `DEV237-P2-01`

> **PM/PO SIGN-OFF — WWHN-237 (re-review 2026-08-19)**
>
> Tôi xác nhận đã đọc và duyệt:
>
> - `WWHN-237` được **defer/re-scope theo NO-STOCKTAKE** (`DISP-STK-237`, Option A), không mở Change Request nâng phạm vi MVP ở thời điểm này.
> - Hệ quả vận hành đã được nêu rõ và tôi chấp nhận: MVP **không có** báo cáo chênh lệch kiểm kê; lệch tồn chỉ phát hiện qua `reconciliation_status` của RPT-INV-01 và chỉ sửa được bằng Reversal append-only truy về đúng chứng từ gốc.
> - Điều kiện tái mở là bộ 4 điều kiện tại `DISP-STK-237 §2` (CR được duyệt + `WWHN-25` đóng có effective date + SRS rebaseline + sau đó mới mở DEV).
> - `WWHN-237` giữ `PROPOSED / NO EFFECTIVE DATE` cho tới khi tôi ghi effective date; guard CI vẫn fail-closed trong lúc chờ.
> - `DEV237-P2-01`: tôi cập nhật narrative Description của `WWHN-237` để phản ánh đúng assignee/role hiện tại (Quang, unified DEV) thay vì "BE2/Duy Khánh" — việc này chỉ là dữ liệu Jira, không cần commit code.
>
> Không còn blocker thuộc PM/PO cho task này.

---

## 4. Tech Lead — `DEV237-P1-02R`, `DEV237-P1-03R`

> **TECH LEAD SIGN-OFF — WWHN-237 (re-review 2026-08-19)**
>
> Tôi xác nhận đã đọc và duyệt trên BE `809d4839` / FE `f9f2e65e`:
>
> - Bằng chứng absence phủ đủ tầng: route, report registry/hub/export, KPI dashboard, permission seed, schema, OpenAPI runtime, bộ contract bàn giao và hợp đồng FE — `CONTRACT_NEGATIVE_LOCK.json` + `FE_CLOSURE_IDENTITY.json`.
> - **`m1` là nhánh canonical duy nhất; `m1-be-2` là ancestor thuần đã dừng từ 2026-08-15, không phải nguồn thay thế** — tôi supersede mọi tham chiếu Jira cũ tới `m1-be-2` bằng SHA ở trên, cùng cơ sở đã dùng cho `DISP-OUT-198-CANONICAL-BRANCH.md`.
> - Ranh giới kỹ thuật đúng: **variance của reconciliation (balance vs ledger) được giữ nguyên** vì `AC-SRS-037` chỉ định đó là cơ chế thay thế; gate có check dương `RECONCILIATION-ALIVE` nên không ai gỡ nhầm.
> - Pack immutable WWHN-180/WWHN-150 **không bị sửa nội dung**, đúng nguyên tắc `DISP-STK-236 §3`; phần được miễn nằm trong allowlist hằng số, mở rộng phải qua review source.
> - Fix gate `CONTRACT-SURFACE` (bỏ khối tường thuật `x-removed-surfaces`/`x-supersedes`/mô tả trước khi so needle, cùng khoá với guard PHP của WWHN-190/260) đã được tôi soát: đúng phạm vi, không nới lỏng — đã tự kiểm bằng cách tiêm path sống giả và thấy gate vẫn fail.
> - Gate fail-closed trong CI (`wwhn-237-variance-report-deferral-gate.yml`, bước `wwhn_237`/`assert-ci-gates.mjs`) **không bị đụng tới** ở vòng này — chỉ sửa logic scan bên trong script đã có sẵn trong CI, không sửa file workflow.
>
> Tôi ghi nhận các finding chuyển ticket khác và **không** tính chúng là blocker của WWHN-237: enum `ADJUSTMENT` của `DEC-REV-001 1.3.0-proposed` → WWHN-26/134; 3 checksum mismatch có sẵn → WWHN-180; `BE_CONTRACT_PIN.json` lệch pin (không liên quan Stocktake) → WWHN-309.
>
> Không còn blocker kỹ thuật thuộc Tech Lead cho task này.

---

## 5. QA1 — `DEV237-P1-04R` (và trạng thái `WWHN-238`)

> **QA1 SIGN-OFF — WWHN-237 / WWHN-238 (re-review 2026-08-19)**
>
> Tôi xác nhận đã đọc raw log do DEV chạy lại và duyệt (phạm vi của tôi là đọc + đối chiếu log, không tự chạy lại vì log đã có exit code + raw output):
>
> - `WWHN-238` chuyển **DEFERRED / N/A cho MVP v1.12** — không để `Blocked`, không để `Pending`. Lý do: capability cần test đã bị `REQ-SRS-037` loại khỏi phạm vi, không có build nào sẽ chứa nó.
> - Phạm vi QA thay thế là **negative gate + regression**, ma trận N1..N8 (capability vắng mặt) và R1..R4 (reconciliation + Reversal còn sống) tại `docs/evidence/WWHN-237/QA_DISPOSITION_WWHN-238.md`.
> - Kết quả chạy trên BE `809d4839` / FE `f9f2e65e`, driver **sqlite :memory:** (mặc định `phpunit.xml`): **N1..N8 PASS, R1..R4 PASS** — `Wwhn237VarianceReportDeferredGateTest` 10/10, `Flow05InventoryControlAlignmentTest` 10/10, gate BE 11/11 check, gate FE 17/17 check, guard FE 6/6 test; raw log + exit code 0 tại `docs/evidence/WWHN-237/logs/*.txt`, tổng hợp tại `SOURCE_REGISTER.json`.
> - Ghi nhận riêng, **không tính vào N1..N8/R1..R4**: lệnh `fe-api-mapping` (kiểm tra hồi quy no-Stocktake của WWHN-296, nằm ngoài ma trận WWHN-238 chính thức) FAIL vì `BE_CONTRACT_PIN.json` lệch pin — đã soát log, không có bất kỳ needle `stocktake`/`variance` nào trong lý do FAIL, nên không phải hồi quy của WWHN-237/296; routed `WWHN-309`.
> - Tôi không dùng kết quả của `WWHN-236` / `WWHN-235` thay cho `WWHN-238`.
>
> Điều kiện mở lại phạm vi test dương: đúng bộ 4 điều kiện tại `DISP-STK-237 §2`.

---

## 6. FE1 — `DEV237-P1-05R` (consumer confirmation)

> **FE1 SIGN-OFF — WWHN-237 (re-review 2026-08-19)**
>
> Tôi xác nhận đã đọc và duyệt trên FE `f9f2e65e` / BE `809d4839`:
>
> - Hợp đồng FE hiện hành là `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json` **sau khi** gỡ criticalFlow `APPLY_ADJUSTMENT` (permission `stocktake.adjust`) và sửa `SCAN_CLASSIFY.context` về đúng enum runtime `INBOUND, OUTBOUND, INVENTORY, TRACE, WARRANTY_RECEIVE, WARRANTY_TEMP_ONLY`. Đã đối chiếu `git diff` nội dung file này giữa SHA cũ và SHA mới: **không đổi** — chỉ SHA neo trong evidence bị cũ, không phải hợp đồng trôi.
> - FE **không** suy diễn lại contract Stocktake từ artifact lịch sử (WWHN-180 pack, WWHN-150 scan OpenAPI, OpenAPI Changelog BE1 v1.0.1, Swagger Standard cũ); khối `removedFromMvp` trong file hợp đồng ghi rõ điều này.
> - FE không có route, permission, menu, màn hình hay report code variance; `npm run wwhn-237:verify` PASS 17 check và guard test PASS 6 test (chạy lại 2026-08-19).
> - Riêng lệnh `check:api-mapping` FAIL vì `BE_CONTRACT_PIN.json` lệch pin với BE hiện hành — đây là vấn đề đồng bộ contract chung (owner `WWHN-309`), không phải hợp đồng WWHN-237 trôi; tôi xác nhận không chặn sign-off này.
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
- Nếu post trễ hơn ngày làm mới (2026-08-19) và `m1` đã đi xa hơn: SHA trong comment sẽ lệch HEAD — đó là bình thường (branch có nhiều phiên commit song song liên tục). Đừng tự sửa số; nếu cần bản mới nhất, chạy lại `node scripts/run-wwhn-237-evidence.mjs` (BE) rồi lấy `SOURCE_REGISTER.json` mới, không copy tay.
- Khi dẫn "nhánh" trên Jira, luôn ghi `m1`. Không ghi `m1-be-2` — nhánh đó là ancestor lịch sử đã dừng, xem giải thích trong mục 1/4 ở trên.
- Không vai trò nào ghi `APPROVED` / `Done` / `100%` cho `WWHN-237` trước khi PM/PO ghi effective date.
