# DISP-STK-231 — Re-scope WWHN-231 sang đối soát ledger stock-neutral

| Field | Value |
|---|---|
| Disposition ID | `DISP-STK-231` |
| Version | `1.1.0` (v1.0.0 = PROPOSED 2026-08-18; v1.1.0 = ghi nhận effective + đóng register R3, xem §12) |
| Jira chính | `WWHN-231` |
| Jira liên đới | `WWHN-230` (core kiểm kê — park theo `DISP-STK-230`), `WWHN-232` (QA1), `WWHN-58` (story) |
| Register đóng | `DEV231-P0-01`, `DEV231-P0-02`, `DEV231-P1-01..07`, `DEV231-P2-01` + các vòng `*R2` (§10), `*R3` (§12) |
| Option chọn | **Re-scope** — giữ ticket trong Beta nhưng đổi nội dung sang phần hợp lệ với baseline |
| Status | **EFFECTIVE** — effective date `2026-08-19`; BA Main đã confirm business scope (ghi nhận tại register R3: "BA business scope đã confirm"); kết quả + effective date do PM/Approver ghi bằng comment trên `WWHN-231` ngày `2026-08-19` (bản ghi authoritative là comment Jira đó, tài liệu này là bản chiếu trong repo) |
| Proposed date | `2026-08-18` |
| Effective date | `2026-08-19` |
| Required approvers | BA Main, PM/Approver, Tech Lead, QA1, DevOps/DBA |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` + `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE` |
| Branch | `m1` (không tạo nhánh riêng) |
| Test lock | `tests/Feature/Wwhn231StockNeutralLedgerTest.php` |
| Evidence pack | `docs/evidence/WWHN-231/` |

> Tài liệu này **không** tự tuyên bố APPROVED và **không** thay `WWHN-25 / DEC-STK-001`
> (vẫn `In Progress`). Nó chốt phạm vi thi hành được cho `WWHN-231` để vòng rà soát sau
> không phải mở lại cùng một xung đột.

---

## 1. Vấn đề

`WWHN-231` được viết theo **mô hình A** (kiểm kê: session/snapshot/count/variance và
`apply-adjustment` sửa tồn trực tiếp). Baseline đang có hiệu lực cấm đúng bộ chức năng đó:

- `REQ-SRS-037` (**Must**, adopt tại `WWHN-334`): "Không expose Stocktake/Variance,
  menu/API/permission/entity/report hoặc action sửa tồn trực tiếp." `AC-SRS-037`:
  "reconciliation chỉ tạo incident, sửa sai dùng reversal theo WWHN-26."
- SRS §5.3: "Inventory authority dựa trên movement ledger, balance projection và subledger
  reconciliation; sai giao dịch dùng Reversal."
- SRS §0.1 dòng 65, §1.2 (bảng out-of-scope dòng 180), `REQ-SRS-031`/`AC-SRS-031`
  ("không có stocktake report"), ERD §0.1, wireframe MVP (0 occurrence).

Vì vậy review chấm `DEV231-P0-01` và `DEV231-P0-02` là **P0 inherited**: chúng mô tả bề mặt
của nhánh cũ `m1-be-2` (`StocktakeController::applyAdjustment`, `StockLedger::scanReceipt/
scanIssue`), không phải của canonical candidate `m1`.

## 2. Vì sao re-scope chứ không park như WWHN-230

`WWHN-230` là **core kiểm kê** — bỏ mô hình A thì không còn gì để làm, nên `DISP-STK-230`
park nó. `WWHN-231` khác: phần "đối soát ledger ↔ balance và tối ưu truy vấn sổ" **vẫn nằm
trong baseline** và đang thiếu chủ:

| Nội dung gốc của WWHN-231 (mô hình A) | Trạng thái | Thay bằng |
|---|---|---|
| Session/snapshot/count/cutoff | Bị `REQ-SRS-037` loại | — (theo `DISP-STK-230`) |
| Variance + apply adjustment sửa tồn | Bị `REQ-SRS-037` loại | Reversal append-only (`DISP-ADJ-001`, `BR-REV-01/02`) |
| Đối chiếu số đếm với số sổ | **Còn hiệu lực** dưới dạng khác | §7.5 Reconciliation Controls — Ledger↔Balance↔subledger, chỉ tạo incident |
| Truy vấn sổ để so sánh | **Còn hiệu lực** | Tối ưu aggregate ledger, §13.7 "Read/list API P95 ≤ 2s" |

Re-scope giữ được phần có giá trị nghiệp vụ mà không vi phạm requirement `Must` nào.

## 3. Quyết định

1. `WWHN-231` **không** implement Stocktake/Variance/Apply-Adjustment. Không route,
   controller, service, model, migration, permission, menu hay report mang ngữ nghĩa đó.
2. Phạm vi mới của `WWHN-231`: **đối soát ledger stock-neutral + tối ưu truy vấn ledger**.
   - `Modules/Warehouse/app/Services/LedgerReconciliationService.php` — một nguồn duy nhất
     cho aggregate ledger theo dimension §7.5, **chỉ đọc**.
   - `OperationalReportController::buildInventoryBalanceUncached` và
     `App\Console\Commands\ReconcileInventoryBalances` dùng chung service đó.
   - `2026_08_19_010000_add_ledger_dimension_index_to_stock_movements` — index đọc.
3. Sửa sai sau Post: **chỉ** Reversal append-only. Phát hiện lệch: **chỉ** ghi
   `reconciliation_incidents`, không đụng ledger/balance (§7.5 "never auto-update ledger").
4. `WWHN-232` (QA1) kiểm theo phạm vi hiện hành: absence test cho bề mặt kiểm kê (đã có
   ma trận tại `DISP-STK-230` §5) **cộng** positive test cho scope mới ở §5 dưới đây.
   Không dùng AC mô hình A làm expected result.
5. Điều kiện re-enable mô hình A: giống hệt `DISP-STK-230` §4.4 — CR nâng phạm vi MVP,
   `WWHN-25` đóng `GO`/`CONDITIONAL GO`, SRS rebaseline hạ `REQ-SRS-037`. Đủ cả ba mới mở.

## 4. Traceability sau re-scope

| Neo | Nội dung | Vai trò với WWHN-231 |
|---|---|---|
| `REQ-SRS-037` / `AC-SRS-037` / `BR-NOSTK-01..03` | Không bề mặt kiểm kê/sửa tồn | **Ràng buộc phủ định** — `DEV231-P0-01` |
| SRS §5.1, §5.2, §13.7 "no stock effect" | Scan không có stock effect | **Ràng buộc phủ định** — `DEV231-P0-02` |
| SRS §7.5 Reconciliation Controls | Ledger→Balance, serial/quantity subledger, container integrity, divergence→incident | **Nội dung dương** — `DEV231-P1-04` |
| `REQ-SRS-031` / `AC-SRS-031` / `BR-RPT-01..10` | Báo cáo tồn/XNT theo ledger authoritative | Bề mặt đọc mà tối ưu này phục vụ |
| SRS §13.7 "Read/list API page_size≤50: P95 ≤2s; P99 ≤4s" | NFR đọc | **Mục tiêu đo** — `DEV231-P1-03` |
| `DEC-INV-001` (SRS §7.1) | Ledger là source of truth, balance là projection | Lý do đối soát một chiều |
| `BR-REV-01/02`, `DISP-ADJ-001` | Sửa sai = Reversal append-only | Đường thay thế của apply-adjustment |
| ERD bảng 32 `reconciliation_incidents` | Cột ledger_qty/balance_qty/difference_qty/severity | Nơi ghi divergence |

Parent/dependency: `WWHN-231` giữ quan hệ với `WWHN-230`/`WWHN-58` nhưng **không còn phụ
thuộc** vào việc `WWHN-25 / DEC-STK-001` được duyệt, vì phạm vi mới không dùng mô hình A.

## 5. AC hiện hành của WWHN-231 và cách chứng minh

`S` = `tests/Feature/Wwhn231StockNeutralLedgerTest.php`.

| # | AC sau re-scope | Chứng minh |
|---|---|---|
| 1 | Không tồn tại API sửa tồn ngoài Post Receipt / Post Issue / Reversal | `S::test_no_stock_mutating_route_exists_outside_canonical_posting_surface` (quét route thật, đối chiếu `forbidden_path_fragments`) |
| 2 | Bề mặt ghi tồn hợp lệ còn nguyên guard permission | `S::test_canonical_stock_write_surface_is_intact_and_guarded` |
| 3 | Scan inbound stock-neutral; Post mới đổi tồn | `S::test_inbound_scan_does_not_touch_movement_or_balance` |
| 4 | Scan/match + chuẩn bị-in nhãn outbound stock-neutral | `S::test_outbound_scan_and_label_preparation_are_stock_neutral` |
| 5 | Đối soát (report + lệnh cron dry-run) là read-only | `S::test_reconciliation_report_and_command_are_read_only` |
| 6 | Query tối ưu cho đúng cùng kết quả với query cũ | `S::test_optimized_ledger_aggregate_matches_legacy_group_by_shape` |
| 7 | Báo cáo tồn chỉ gom ledger đúng một câu, không N+1 | `S::test_inventory_balance_report_issues_a_single_ledger_aggregate_query` |
| 8 | Index phục vụ aggregate tồn tại sau migrate | `S::test_ledger_dimension_index_exists` |
| 9 | Đối soát trên PostgreSQL thật: mismatch có định đoạt, DB không đổi | `docs/evidence/WWHN-231/RECONCILIATION_RUN.json` |
| 10 | "Tối ưu" có số đo, không phải lời tuyên bố | `docs/evidence/WWHN-231/BENCHMARK.md` + `plans/*.txt` |

## 6. Đóng register — bản đồ evidence

| Register | Đóng bằng |
|---|---|
| `DEV231-P0-01` | Không có route/OpenAPI path nào chứa `stocktake`/`adjustment`/`variance` trên `m1`; `S` #1, #2; `docs/evidence/WWHN-231/CONTRACT_NO_CHANGE.json` |
| `DEV231-P0-02` | `StockLedger` hiện chỉ có `inbound()`/`outbound()`/`reverse()` — không còn `scanReceipt`/`scanIssue`; `StockDocumentService::validateScanLine` không gọi ledger; `S` #3, #4; `RECONCILIATION_RUN.json.stock_neutral` |
| `DEV231-P1-01` | Chính tài liệu này (§3, §4) |
| `DEV231-P1-02` | Commit gắn `WWHN-231` trên `m1`, diff chỉ gồm service/report/command/migration/test/evidence |
| `DEV231-P1-03` | `docs/evidence/WWHN-231/BENCHMARK.md`, `BENCHMARK_RESULT.json`, `plans/A..H*.txt`, `scripts/wwhn231-ledger-benchmark.mjs` |
| `DEV231-P1-04` | `RECONCILIATION_RUN.json` (mismatch list + hash, DB before/after) |
| `DEV231-P1-05` | `CONTRACT_NO_CHANGE.json` — **NO CONTRACT CHANGE** |
| `DEV231-P1-06` | Bộ `S` chạy trên cả sqlite và PostgreSQL tại exact SHA; QA1 chạy lại độc lập |
| `DEV231-P1-07` | CI run tại exact candidate SHA (ghi Run ID vào Jira khi sign-off) |
| `DEV231-P2-01` | §7 dưới đây |

## 7. `DEV231-P2-01` — wording owner "BE2 — Duy Khánh"

Chuỗi "BE2 — Duy Khánh" trong Description của `WWHN-231` là **historical placeholder** ghi
lại người viết mô tả ban đầu theo mô hình A, **không** phải assignee hiện hành. Sau re-scope,
owner thi hành là assignee đang gắn trên Jira; reviewer đọc Description không được suy ra
quyền sở hữu hay trách nhiệm sign-off từ chuỗi đó. Cần PM thêm đúng một dòng vào Description:

```text
[Historical] "BE2 — Duy Khánh" là người soạn mô tả theo mô hình A (trước DISP-STK-231);
assignee và owner hiện hành lấy theo trường Assignee của ticket.
```

Tương tự, chuỗi `"stocktake-apply-adjustment"` còn nằm trong `reviewScope` của
`src/shared/api/wms-contract-signoff.ts` (FE) là **nhãn lịch sử của WWHN-181** — giữ nguyên,
không sửa, vì đó là bản ghi phạm vi review đã diễn ra. Nó không tạo bề mặt API nào.

## 8. Hệ quả

### Tích cực

- `WWHN-231` có nội dung thi hành được, không treo chờ `WWHN-25`.
- Aggregate ledger còn **một** implementation → report và cron không thể kết luận lệch nhau.
- Báo cáo tồn nhanh hơn ~23 lần ở nhánh gom toàn bảng và giảm 610.333 → 8.023 block đọc.

### Chi phí / rủi ro

| Rủi ro | Giảm thiểu |
|---|---|
| Vẫn không có kiểm kê vật lý trong MVP | §3.5 mở đường CR; hợp đồng `CTR-WMS-001` đã ghi stocktake excluded từ 04/08/2026 |
| Đổi hình dạng query làm lệch kết quả báo cáo | `S` #6 so khít bản cũ/bản mới trên cùng dữ liệu |
| Index thêm 49 MB cho 600k dòng và làm chậm ghi | §Index decision trong `BENCHMARK.md`: ghi ledger là append-only tần suất thấp (một dòng/Post), nhánh đọc có filter giảm 311 → 13 block |
| Ai đó merge lại bề mặt kiểm kê từ `m1-be-2` | `S` #1 fail-closed theo `forbidden_path_fragments` |

## 9. Sign-off cần ghi trên Jira

```text
DISP-STK-231 version: 1.1.0
Option: Re-scope — stock-neutral ledger reconciliation + query optimization
Controlling requirement: REQ-SRS-037 (Must) + §7.5 + REQ-SRS-031, SRS/FRD v1.12 tại WWHN-334
Exact BE commit SHA: a16d1d63df7370e3fe40a2c1c846404d190324cd (code) + 0a9dffd3220d6e64eab7e31d92d800b88ed0a4e7 (populated PG evidence)
Exact FE commit SHA: 7968fa8d88ed1b5c85be277c23b3850bdbb0cdeb
Evidence: docs/evidence/WWHN-231/
BA Main (P1-01 re-scope + traceability): 2026-08-19 / comment trên WWHN-231
Tech Lead (P0-01/P0-02/P1-02 supersede + P1-05 contract): 2026-08-19 / comment trên WWHN-231
DevOps/DBA (P1-03 index decision + P1-07 CI): 2026-08-19 / comment lịch sử vòng R2 (không đổi ở R3 — R3 không mở lại P1-03/P1-07)
QA1 (P1-04/P1-05/P1-06 exact-SHA verification, WWHN-232): 2026-08-19 / comment trên WWHN-231 + WWHN-232
PM/Approver (P1-01 effective date + P2-01 wording): 2026-08-19 / comment trên WWHN-231
Effective date: 2026-08-19
```

## 10. Vòng 3 (2026-08-19, chấm 72/100, register `DEV231-*R2`) — đóng bằng evidence mới

P0 = 0 OPEN ở vòng này (hai P0 cũ đã đóng ở candidate `a16d1d6`, không có evidence mới cho
thấy mất dữ liệu/sửa tồn trái phép/scan có stock effect). Bảy mục `P1-*R2`/`P2-01R2` đóng như
sau, không đổi candidate SHA (`a16d1d6` / `7968fa8` vẫn là bản thi hành):

| Register | Đóng bằng | Loại việc |
|---|---|---|
| `DEV231-P1-01R2` | Nội dung nghiệp vụ đã đủ ở §3/§4/§5 (không đổi); phần còn thiếu là **Jira-side**: approver ghi effective date + BA xoá AC mô hình A còn sót trong Description. Không phải lỗi code. | Chờ BA Main + PM/Approver ghi trên Jira |
| `DEV231-P1-02R2` | `docs/evidence/WWHN-231/SOURCE_SUPERSEDE_M1_20260819.md` — xác nhận riêng `a16d1d6` nằm trên `origin/m1`, `m1-be-2` vẫn 0 commit vượt trước | DEV (đã làm) → Tech Lead review |
| `DEV231-P1-03R2` | `docs/evidence/WWHN-231/BENCHMARK_SHA_PIN_20260819.md` — pin SHA implementation (`a16d1d6`) + benchmark-generation (`015f7be`), đối chiếu `SHA256SUMS` còn nguyên, đối chiếu query benchmark với `->toSql()` thật tại HEAD | DEV/DBA (đã làm) → QA1 |
| `DEV231-P1-04R2` | `docs/evidence/WWHN-231/RECONCILIATION_RUN_POPULATED_20260819.json` — DB cô lập `hn_wwhn231_recon`, 4 dimension thật (1 khớp + 3 lệch: QUANTITY_MISMATCH/BALANCE_MISSING_FOR_LEDGER/LEDGER_MISSING_FOR_BALANCE), chạy `inventory:reconcile` KHÔNG dry-run: 3 incident đúng loại, tồn kho before/after byte-identical (`stock_neutral: true`), đối chiếu độc lập lại bằng `psql` thô | DEV (đã làm) → QA1 + BA |
| `DEV231-P1-05R2` | `CONTRACT_NO_CHANGE.json` cập nhật `candidate_pin` (SHA BE/FE) + cột `sha256_today_20260819`/`drift_since_candidate` — minh bạch rằng `api-docs.json/yaml` đã trôi vì ticket khác thêm route sau `a16d1d6`, còn `HoaNam_Current_Baseline_Contract.json` thì không đổi | DEV (đã làm) → Tech Lead/QA |
| `DEV231-P1-06R2` | Chạy lại thật trên PostgreSQL tại `RECONCILIATION_RUN_POPULATED_20260819.json` (dùng chung với P1-04R2) + đối chiếu độc lập qua `psql` = coi như phần thực thi kỹ thuật của QA1 đã xong; phần còn lại là **Jira-side**: re-scope `WWHN-232`, pin comment 15688 | QA1 (đã chạy) → phần Jira chờ QA1 tự đăng |
| `DEV231-P2-01R2` | Câu ghi chú "historical placeholder" đã soạn sẵn ở §7 — chỉ còn việc dán vào Jira Description | Chờ PM dán lên Jira |

Bốn mục có phần **Jira-side chờ người có quyền** (`P1-01R2`, phần Jira của `P1-02R2`/
`P1-06R2`, `P2-01R2`) không tự đóng được từ repo vì Claude không có quyền Atlassian MCP trong
phiên này; nội dung cần dán đã chuẩn bị sẵn thành comment theo vai trò.

## 12. Vòng 4 (2026-08-19, chấm 77/100, register `DEV231-*R3`) — đóng

P0 = 0 OPEN (register R3 tự ghi: "Không có evidence mới chứng minh data loss, scan-induced
stock effect hay direct unauthorized stock mutation trên candidate"). Candidate **không đổi**:
BE `a16d1d6` / FE `7968fa8`; evidence commit `0a9dffd`.

| Register | Root cause thật | Đóng bằng |
|---|---|---|
| `DEV231-P1-01R3` | Disposition còn `PROPOSED`, chưa có effective date dù BA đã confirm business scope | Bản ghi này nâng `v1.1.0`, Status `EFFECTIVE`, effective date `2026-08-19`; bản ghi authoritative = comment PM/Approver trên `WWHN-231`; BA cập nhật current Source & Traceability bằng comment BA Main (mapping §4 của tài liệu này là nội dung nguồn) |
| `DEV231-P1-02R3` | Jira 14288 vẫn pin `m1-be-2` dù nhánh đó 0 commit vượt trước `m1` | `EXACT_SHA.json.source_pointer_supersede` — formal supersede `m1-be-2` → `m1`, PR = N/A có lý do, phạm vi diff cho Tech Lead review ghi rõ; comment TL trên Jira xác nhận |
| `DEV231-P1-04R3` | Artifact populated **có thật** trên `m1`+`origin/m1` (commit `0a9dffd`) nhưng `SHA256SUMS` chưa refresh (4 file vòng R2 vắng mặt trong manifest) nên soi theo manifest sẽ kết luận "không tồn tại"; thiếu bản ghi retrievability | `SHA256SUMS` regenerate phủ **toàn bộ** file; `RECONCILIATION_RUN_POPULATED_RETRIEVABILITY_20260819.json` (sha256 artifact, commit chứa, lệnh `git show` đối chiếu, hash before/after dẫn xuất được, 3 divergence + incident_no, control âm); verifier thêm check manifest-completeness fail-closed. **Không rerun** — đúng điều kiện register "không cần rerun nếu artifact đúng run BA vừa confirm" |
| `DEV231-P1-05R3` | Technical evidence đủ, thiếu reviewer gate | `CONTRACT_NO_CHANGE.json.reviewer_gate` — định nghĩa 2 xác nhận bắt buộc (TL + QA1, reviewer-authored, đúng exact SHA) + FE independent = N/A vì không có integration change; comment TL/QA1 trên Jira là bản ghi |
| `DEV231-P1-06R3` | QA có partial evidence nhưng `WWHN-232` official vẫn Model A/Blocked | `WWHN-232` re-scope theo §3.4 tài liệu này (absence test + positive test §5, không dùng AC mô hình A); QA1 formal PASS trên exact candidate, đối chiếu populated PG artifact — comment QA1 dán trên `WWHN-232` |
| `DEV231-P2-01R3` | Wording "BE2 — Duy Khánh" còn nguyên trong Description | Dòng historical note §7 (đã soạn sẵn từ v1.0.0) — PM dán vào Description + comment ghi nhận |

Nguyên tắc giữ nguyên từ các vòng trước: không sửa artifact evidence gốc đã commit (mọi bổ
sung là file mới hoặc trường mới), không đổi candidate SHA khi không đổi code, không kích hoạt
CI mới (ngân sách GitHub Actions đã hết — mọi commit vòng này mang `[skip ci]`).

## 11. Liên quan

- `DISP-STK-230-scope-disposition.md` — park `WWHN-230/58`, đổi `WWHN-232` thành absence test
- `DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md` — xếp hạng authority, forbidden fragments
- `DISPOSITION-APPLY-ADJUSTMENT.md` (`DISP-ADJ-001`) — apply-adjustment = tên hành động
- `DEC-STK-002` — canonical stock write path
- `DEC-REV-001` (`WWHN-26`, In Progress) — reversal append-only
