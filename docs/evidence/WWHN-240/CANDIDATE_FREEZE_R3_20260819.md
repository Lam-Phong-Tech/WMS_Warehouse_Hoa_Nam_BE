# WWHN-240 — Candidate freeze R3 (DEV240-P1-CAND-R3)

Ngày: 2026-08-19. Đóng yêu cầu register R3: "freeze exact BE SHA + FE SHA cho W240;
review diff từ last-tested candidate tới final ở các file reversal/trace/report;
ghi PR=N/A nếu quy trình direct-commit được chấp nhận".

## 1. Source disposition

- **Nguồn canonical = nhánh `m1`** ở cả hai repo. Bằng chứng supersede đã lập ở
  `SOURCE_SUPERSEDE_M1_20260819.md` (m1-be-2: 0 ahead / 814 behind m1, 0 PR từng
  tồn tại). Cần Tech Lead/PM ghi disposition này vào Jira (comment soạn sẵn đã
  gửi kèm phiên đóng register — DEV không tự flip).
- **PR = N/A**: mọi thay đổi WWHN-240 đi theo quy trình direct-commit trên `m1`
  (cùng quy trình mọi ticket khác của dự án); review thực hiện qua register +
  diff disposition trong tài liệu này thay cho PR review.

## 2. Freeze theo mô hình "commit-cuối-chạm-artifact" (chống pin rot)

HEAD tuyệt đối trôi liên tục vì nhiều phiên làm việc song song trên `m1`, nên
freeze KHÔNG pin HEAD mà pin **commit cuối cùng chạm từng artifact sản xuất**
cộng **bất biến dải commit** (mô hình đã dùng ở WWHN-328/191/205).

### BE (`WMS_Warehouse_Hoa_Nam_BE`, nhánh m1)

| Artifact | Commit cuối chạm | Ticket chạm |
|---|---|---|
| `Modules/Warehouse/app/Http/Controllers/OperationalReportController.php` | `84e66b3` | WWHN-258 |
| `Modules/Warehouse/app/Http/Controllers/StockMovementController.php` | `4b88662` | WWHN-266 |
| `config/l5-swagger.php` | `b90d5ef` | WWHN-252 |
| `storage/api-docs/api-docs.json` (spec canonical) | `84e66b3` — sha256 `46cbfc0140fe3da389625ed55c0ef91e18a21f50dbd5389488c270be4e2e7af2` | WWHN-258 |

**Bất biến dải commit:** không tồn tại commit nào sau các SHA trên (tới HEAD tại
thời điểm evidence, xem §5) chạm 4 artifact này. Lệnh re-check tại cổng bất kỳ:

```
git log --oneline <SHA-bảng-trên>..HEAD -- <artifact>   # phải rỗng
```

Pin object-level bổ sung (không rot theo cả tệp): `OPENAPI_CANONICAL_PIN_20260819.md`
— hai operation `report_xnt_enhanced` / `stock_movement_trace_source` đã publish
trong spec canonical.

### FE (`WMS_Warehouse_Hoa_Nam_FE`, nhánh m1)

| Artifact | Commit cuối chạm | Ghi chú |
|---|---|---|
| `src/modules/inventory/components/TraceModal.tsx` | `79474b8` | **Chính là candidate FE đã test R1** — logic render chain/net_effect/truncated chưa từng đổi từ đó |
| `src/modules/reports/pages/AlignedReportsPage.tsx` | `2b94a79` | WWHN-258 (PII export path) |
| `src/modules/inventory/services/inventoryService.ts` | `0dfbe98` | WWHN-198 (docs mapping reserved_qty) |
| `src/shared/api/endpoints.ts` | `32a8df9` | WWHN-242 (trích dẫn no-Stocktake) |

## 3. Reviewed diff disposition: last-tested candidate → freeze

Last-tested BE candidate = `9640091` (R1), đã re-verify nguyên trạng tại `7789250`
(re-review 76/100, 2026-08-19). Từ `9640091` tới freeze, đúng **4 commit** chạm 2
controller (`a16d1d6` WWHN-231, `d9f1769` WWHN-190, `4b88662` WWHN-266, `84e66b3`
WWHN-258); 2 commit đầu nằm trong dải đã được re-review 76/100 cover. Disposition
cho 2 commit sau re-review:

| Commit | Diff đụng gì | Disposition với 3 cơ chế WWHN-240 |
|---|---|---|
| `4b88662` (WWHN-266, DEC-REV-COMPONENT-001) | `StockMovementController`: chỉ `reversible()` (thêm COMPONENT_ISSUE vào danh sách đảo được, join `component_issue_documents` cho version If-Match, thêm `buildComponentItemCandidateMap()`) | **Không đụng** `traceSource()`/`correctionChain()`. Mở rộng phạm vi reversal sang COMPONENT_ISSUE tương thích sẵn với XNT R1 (cột `component_issue_reversal` đã tách từ đầu theo movement_type gốc). Commit message ghi regression 207/239/240/242 = 36/36 PASS khi đóng WWHN-266. |
| `84e66b3` (WWHN-258) | `OperationalReportController`: chỉ `assertKnownReportCode()` / `createExport()` / `currentActorId()` (đường export PII) | **Không đụng** `buildXnt()` và `xntReversalBreakdown()`. |
| `b90d5ef` (WWHN-252) | `config/l5-swagger.php`: +9 dòng **additive** (2 controller PV vào danh sách scan) | 2 entry WWHN-240 (`StockMovementController`, comment guard) còn nguyên. |

## 4. Execution proof tại freeze (không tin diff suông)

Toàn bộ chạy local, không dùng CI/GitHub Actions:

| Bằng chứng | Kết quả |
|---|---|
| `php vendor/bin/phpunit tests/Feature/Wwhn240ReversalNetEffectContractTest.php tests/Feature/Wwhn239ReversalItemLevelClosureTest.php tests/Feature/Wwhn266ComponentIssueReversalTest.php tests/Feature/Wwhn254ReportEvidenceTest.php` (sqlite) | `{"result":"passed","tests":39,"passed":39,"assertions":412}` |
| QA240-20 `Wwhn240ForcedRollbackInjectionTest` — sqlite | `passed, tests 1, assertions 48` |
| QA240-20 — PostgreSQL 16.14 (DB cô lập `hn_wwhn240_qa20`) | `passed, tests 1, assertions 48` |
| QA240-19 HTTP race (`scripts/wwhn240-qa19-http-race.php`, PG 16.14 DB cô lập `hn_wwhn240_qa19`) | 25/25 checks PASS — raw `runtime-qa19/qa240-19-http-race-summary.json` |
| QA240-19 service-level race 2 OS process (`scripts/wwhn239-reversal-item-race.php`, PG) | PASS exit 0 — raw `runtime-qa19/qa240-19-service-race-summary.json` |

## 5. Cùng frozen OpenAPI version với FE (input cho DEV240-P1-HANDOFF-R3)

FE pin hợp đồng tại `docs/api-evidence/BE_CONTRACT_PIN.json` (FE repo):
`be_sha = 8326d505`, `spec_sha256 = b86330ff…`. Đã xác minh bằng
`git show 8326d505:storage/api-docs/api-docs.json`:

- sha256 file khớp pin FE: `b86330ff5f29031303b46e1075f8d036e1fe0d19e5d610ec3cb1c38dcd12bf45` ✔
- Hai operation WWHN-240 **giống hệt từng byte** giữa spec FE pin và spec BE tại
  freeze (`46cbfc01…`): hash object-level `report_xnt_enhanced = f0922617…`,
  `stock_movement_trace_source = 7b56cc89…` ở CẢ HAI phía ✔

⇒ FE và BE cùng một frozen contract cho 2 operation của W240, chứng minh không
cần repin (spec `swagger-api.json` local là file gitignored/mutable — evidence
trên đối chiếu thẳng git object, không tin file local).

HEAD hai repo tại thời điểm evidence: ghi trong commit chứa tài liệu này
(commit đó chính là mốc trên của bất biến dải commit §2).
