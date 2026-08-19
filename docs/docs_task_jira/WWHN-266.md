# WWHN-266 — Biên nhận vòng sửa lỗi/retest phía BE1

- Task: `WWHN-266` · Parent: `WWHN-70` · Nguồn QA: `HN-QA-005-ST01`
- Vai trò giao nộp: **BE1**
- Sibling: `WWHN-264` (QA1 API/DB) · `WWHN-265` (QA2 UI/E2E) · `WWHN-267` (BE2) · `WWHN-268` (FE)
- Nhánh: `m1` (không tạo nhánh riêng)
- Trạng thái: **ĐÃ GIAO PHẦN THUỘC BE1 — CHỜ QA1 RETEST ĐỘC LẬP VÀ BA DISPOSITION 2 MỤC**

## 1. Review vòng trước nói gì, và vì sao đúng

Điểm 30/100 với bốn lời buộc tội cụ thể. Cả bốn đều đúng ở thời điểm đó:

| Mục register | Lời review | Tình trạng thực tế đã kiểm chứng |
|---|---|---|
| DEV266-P1-01 | "Không có defect input" | Đúng. Không có register nào phía BE cho WWHN-266. |
| DEV266-P1-02 | "0 attachment, 0 implementation comment, 0 task SHA" | Đúng. Không có ánh xạ root cause → code → fix → test. |
| DEV266-P1-03 | "Core invariants chưa có target evidence" | Đúng, và tệ hơn dự đoán — xem §2. |
| DEV266-P1-04 | "Không ledger/balance proof" | Đúng. |

Vấn đề gốc không phải "chưa viết tài liệu" mà là **chưa ai đi tìm defect**. Ba
ticket liền trước (WWHN-156, WWHN-292, WWHN-268) cùng bị chấm "generic" vì
register viết bằng văn xuôi. Cách chặn đã dùng ở WWHN-268 và tái dùng ở đây:
register là JSON có schema, kèm verifier fail-closed.

## 2. Defect thật, tìm bằng cách chạy chứ không bằng cách đọc

Hồ sơ đầy đủ: `docs/evidence/WWHN-266/DEFECT_REGISTER.json`.

### BE266-D01 (P1, ĐÃ SỬA) — hợp đồng nói sai về `If-Match`

`RouteSyncProcessor::contractHeaders()` khai cứng `'required' => false` cho
`If-Match`, trong khi `actionContractFlags()` chỉ trả bool "action có đọc header
hay không". Đo tại `HEAD`:

- 58 operation khai header `If-Match` trong OpenAPI;
- **47** trong số đó BẮT BUỘC header lúc chạy (ném `428 PRECONDITION_REQUIRED` khi thiếu);
- chỉ **6** khai `required: true` — tức **41 operation công bố sai**, gồm cả ba
  action đổi tồn `post-receipt`, `post-issue`, `reversal-documents/{id}/post` và
  `component-issue-documents/{id}/post`.

Hệ quả đo được ở phía người dùng hợp đồng: bộ Postman DEV có 15 request mang
`Idempotency-Key` kèm "(Required)" nhưng **0** request mang `If-Match` — vì bộ
xuất chỉ phát header nào `required`. QA1 import collection rồi bấm bất kỳ endpoint
mutation nào sẽ ăn 428. "QA dùng cùng một hợp đồng" là tuyên bố không thực hiện được.

Đây đúng lớp lỗi mà WWHN-309 đã sửa cho `Idempotency-Key` (`IdempotencyKeyGuard::require`
vs `::optional`) nhưng bỏ sót nửa `If-Match`.

**Sửa tại gốc sinh contract**, không khai tay từng endpoint:
`actionContractFlags()` nay trả `false|'required'|'optional'`. Điều kiện `required`
là **đồng hiện diện trong cùng một thân method**: thân nào vừa đọc
`header('If-Match')` vừa ném `PRECONDITION_REQUIRED`.

> Điều kiện "cùng một thân method" không phải chi tiết trang trí. Bản nháp đầu
> kiểm trên bản nối của cả chuỗi helper và **làm hỏng `PATCH /api/v1/users/{id}`**:
> trait `ApiResponse` chứa map `428 => 'PRECONDITION_REQUIRED'`, mà mọi action gọi
> `respondSuccess()` đều kéo trait đó vào, nên 5 endpoint thật sự tuỳ chọn bị xếp
> nhầm nhóm bắt buộc. Bộ test bắt được. Con số 41 ở trên là sau khi đã loại 5
> dương tính giả đó.

Kết quả: 47/47 khai đúng, lệch = 0; 11 operation tuỳ chọn thật giữ nguyên
`required: false`; 13 request Postman được bổ sung header.

### BE266-D02 (P1, ĐÃ SỬA) — một action đổi tồn không có hard gate nào

SRS §13.3 đánh số `TC-P1-001..016` và `TC-P1-025..040`, **bỏ trống `TC-P1-017..024`**.
Khoảng trống đó rơi đúng vào `POST /component-issue-documents/{id}/post` — action
gọi `mutateBalance(..., sign: -1)` và ghi movement `COMPONENT_ISSUE`, tức đổi tồn
thật. Không test nào trong repo lái action đó qua HTTP; bốn file có nhắc route chỉ
kiểm route tồn tại/alias/tài liệu.

WWHN-234 đã ghi nhận khoảng trống này (finding F-07) và chuyển BA, nhưng không bên
nào dựng test bù, nên action trôi qua mọi vòng gate trước.

Đã lấp bằng `tests/Feature/Wwhn266ComponentIssueCoreTransactionTest.php` — 9 ca,
khoá đủ 8 chiều, đánh số đúng vị trí trống. Ánh xạ chi tiết:
`docs/evidence/WWHN-266/TC_P1_017_024_TRACEABILITY.json`.

### BE266-D03 (P1, CHỜ BA + TECH LEAD) — Component Issue post nhầm thì không sửa được

`CurrentBaselineReversalService` chỉ nhận `movement_type IN ('RECEIPT','ISSUE')` ở
cả hai chốt (tạo draft và Post). Movement `COMPONENT_ISSUE` không lọt qua được chốt
nào. Trong khi đó §5.5 BR-REV-01 nói mọi sửa sai sau Post phải đi qua reversal
append-only, và AC-GWT-REV-001 chốt "không có API/UI action sửa tồn trực tiếp".

Nghĩa là: một phiếu xuất linh kiện bảo hành post nhầm **không có đường sửa sai hợp lệ nào**.

BE1 không tự mở phạm vi: việc đó chạm OpenAPI, bề mặt FE và bộ test reversal đã
chốt (WWHN-239/240/242). Theo đúng tiền lệ BE268-D02, ghi nhận và chờ disposition.

### BE266-D04 (P2, CHỜ BA) — subledger BOX không được luồng nhập kho nạp

SRS §5.4.1: "quantity-only components use box + quantity". Nhưng luồng inbound
(tạo BOX → scan → post-receipt) **không ghi dòng `container_items` nào**; BOX chỉ
chuyển `RECEIVED_PENDING_PUTAWAY`. `assertContainerAvailable()` cộng
`container_items.quantity` nên luôn ra 0.

Đo được: `inventory_balances` = 10 nhưng `available_qty` của BOX = 0 → 422
`COMPONENT_BOX_INSUFFICIENT`. Đường duy nhất nạp nội dung BOX là
`POST /api/v1/containers/{id}/items` (permission `container.manage`).

Hai cách đóng đều là quyết định nghiệp vụ (post-receipt tự sinh subledger, hoặc
công nhận "khai nội dung BOX" là bước vận hành bắt buộc) — cần BA chọn.

## 3. Số đo, không phải lời khẳng định

Số liệu do chính test ghi ra: `build/evidence/wwhn-266/component-issue-core-transaction.json`.

| TC | Chiều | Kết quả đo |
|---|---|---|
| TC-P1-017 | POSITIVE | 200; tồn 10→7; `qty_delta` -3 (before 10, after 7); 1 movement; 1 posting_event; 1 audit; ETag `"3"` |
| TC-P1-018 | NEGATIVE | 422 `FULL_POST_REQUIRED`; tồn giữ 10; 0 movement; 0 posting_event |
| TC-P1-019 | PERMISSION | 403 `ACCESS_DENIED`; tồn giữ 10; 0 movement |
| TC-P1-020 | INVALID_STATE | post lại 409 `STATE_CONFLICT`; version cũ 412 `STALE_VERSION`; vẫn 1 movement |
| TC-P1-021 | RETRY_SAME_KEY | replay `[false, true, true]`; tồn giảm đúng một lần; 1 posting_event; version không bump lần hai |
| TC-P1-022 | SAME_KEY_DIFF_PAYLOAD | 409 `IDEMPOTENCY_CONFLICT`; không có write thứ hai |
| TC-P1-023 | CONCURRENCY | hai phiếu cùng đòi 3 trên tồn 5 → `[200, 422]`; tồn còn 2, không âm; tổng 1 movement |
| TC-P1-024 | ROLLBACK | 500 không lộ nội bộ; rollback movement + balance + subledger BOX + version; retry sau đó Post được |
| — | LEDGER/BALANCE | `SUM(qty_delta)` = 5 = `on_hand`; `variance` = 0; `mismatch_count` = 0; đối soát PASS |

Tồn được dựng bằng ledger thật (inbound → box → scan → post-receipt). Không
`INSERT` thẳng `inventory_balances`: seed tay làm đối soát §7.5 lệch và con số mất
giá trị — bài học WWHN-289.

Đối soát dùng **hai nguồn độc lập**: báo cáo `RPT-INV-01` và SQL thô
`SUM(qty_delta)`. Reviewer không phải tin một nguồn duy nhất.

## 4. Candidate exact SHA

- **Code candidate: `8c7750763c473f5ccc65a8490a6a5b5a599246fc`** (`8c77507`), nhánh `m1`, repo `WMS_Warehouse_Hoa_Nam_BE`.
- Hồ sơ pin: `docs/evidence/WWHN-266/CANDIDATE_PIN.json`.
- Pin theo **commit cuối chạm artifact**, không theo `HEAD` của `m1`: nhánh này có
  nhiều phiên làm song song nên `HEAD` trôi liên tục và pin theo `HEAD` mục ngay
  trong ngày (bài học WWHN-191/WWHN-290). `verify-wwhn-266-closure.mjs` tự kiểm
  range invariant: pin phải là ancestor của `HEAD`, và không commit nào sau pin
  được chạm 6 đường dẫn trong `rangeInvariantPaths` mà pin vẫn đứng yên.
- `storage/api-docs/api-docs.json` **cố ý không** nằm trong candidate: đó là
  artifact sinh ra, mà working tree dùng chung lúc commit còn chứa 4 route outbound
  chưa xong của phiên khác (WWHN-195). Thay vào đó `Wwhn266IfMatchContractTruthTest`
  tự gọi `l5-swagger:generate` rồi mới kiểm — bộ test đo **source**, không đo file tĩnh.

## 5. Cổng máy kiểm được

```
php artisan test tests/Feature/Wwhn266ComponentIssueCoreTransactionTest.php   # 9/9 PASS
php artisan test tests/Feature/Wwhn266IfMatchContractTruthTest.php            # 4/4 PASS
node scripts/sync-wwhn-266-postman-if-match.mjs --check                       # PASS
node scripts/verify-wwhn-266-closure.mjs                                      # PASS
```

`scripts/verify-wwhn-266-closure.mjs` fail-closed: từ chối dòng thiếu trường, chuỗi
độn (`TBD`/`N/A`/`chưa có`), `fix.files` trỏ tới file không tồn tại, khai `FIXED`
mà `retest ≠ PASS`, thiếu bất kỳ chiều nào trong 8 chiều, đối soát khác 0, hoặc
contract khai sai header bắt buộc của action đổi tồn. Đã kiểm ngược bằng ba phép
phá hoại (đổi `rootCause` thành `TBD`, xoá `TC-P1-024`, đổi `mismatch_count` thành 2)
— cả ba đều bị chặn.

`.github/workflows/wwhn-266-core-transaction-closure.yml` chạy trên PostgreSQL 16
tại `GITHUB_SHA`, **xoá artifact bằng chứng trước khi chạy** và **sinh lại OpenAPI
trong run** — hai bước này ngăn cổng tự xác nhận bằng file tĩnh đã commit.

## 6. Ranh giới sự thật

Bằng chứng trong tài liệu này là lượt chạy in-process của DEV trên driver sqlite.
Nó **không** thay thế lượt retest độc lập của QA1 (`WWHN-264`) trên build đã deploy.
Số liệu trích dẫn ra ngoài phải lấy từ lượt CI trên PostgreSQL tại exact SHA.

## 7. Việc còn lại thuộc vai trò khác

| Việc | Vai trò | Ticket | Register |
|---|---|---|---|
| Retest độc lập toàn bộ defect trên exact final candidate | QA1 | `WWHN-264` | DEV266-P1-05 |
| Pin repo/branch/SHA/build/env của candidate và chạy CI tại SHA đó | DEV + DevOps | `WWHN-266` | DEV266-P1-07 |
| Disposition BE266-D03 (mở phạm vi reversal cho COMPONENT_ISSUE) | BA + Tech Lead | `WWHN-266` | — |
| Disposition BE266-D04 (ai nạp subledger BOX) | BA | `WWHN-266` | — |
| Làm rõ contingency post-sprint hay Beta-blocking (Jira due 08/09 sau Sprint 93 end 03/09) | PM | `WWHN-266` | DEV266-P2-01 |

## 8. Ghi chú vận hành

Trong lúc làm ticket này, một phiên khác đang sửa outbound trên cùng `m1` và đã
thêm 4 route (`AlignedOutboundFlowController`). Việc sinh lại `storage/api-docs/api-docs.json`
hấp thụ luôn các route đó, làm số liệu `idempotency_key_operations` nhảy 27 → 33 và
`idempotency_key_optional` 4 → 10. Hệ quả: cổng `verify-wwhn-309-handover-contract.mjs`
đang đỏ ở ba phát biểu số trong tài liệu WWHN-309 và ở catalog error code.

**Phần đỏ đó không thuộc WWHN-266 và không được sửa ở đây** — tài liệu WWHN-309 là
sản phẩm đang dở của phiên khác. Chủ WWHN-309 cần chạy lại
`node scripts/generate-error-code-catalog.mjs` và cập nhật ba con số trong
`HN-BE-API-001` / `WWHN-309_SIGNOFF_COMMENTS.md`.

Tương tự, `DocumentationBaselineContractTest::test_generated_swagger_has_route_parity_and_baseline_error_responses`
đỏ ở `get /api/v1/health missing HTTP 400`. Đã kiểm: `api-docs.json` **tại HEAD** đã
là `[200, 503]`, nên ca này đỏ sẵn từ trước, do tính năng opt-out
`exhaustive-responses` của WWHN-295 — không phải hệ quả của WWHN-266.

---

## 0-R2. Vòng review 2 (74/100, register *R) — đóng 2026-08-19

Register mới không có P0. Từng mục và nơi chứng minh:

| Mục | Việc đã làm | Closure evidence |
|---|---|---|
| DEV266-P1-01R | QA1 review/adopt register DEV và **phát hành defect register Beta chính thức có version** — map từng defect vào test/build/env thật, kèm residual risks (PG chờ quota CI). | `docs/evidence/WWHN-264/QA1_DEFECT_REGISTER_BETA.json` (schemaVersion 1.0.0) |
| DEV266-P1-02R | Refresh exact candidate sau thay đổi core: pin `4b88662`; **rangeInvariantPaths mở rộng sang production paths thực sự tạo/sửa tồn** (CurrentBaselineStockService, CurrentBaselineReversalService, ComponentContainerSubledgerService, 2 controller + middleware reversal, ComponentFlowController) — không còn chỉ test/artifact; kèm sourceDiffRegister cho TL review. | `docs/evidence/WWHN-266/CANDIDATE_PIN.json` |
| DEV266-P1-03R | QA1 chạy lại đủ 8 chiều trên candidate mới (đặc biệt CONCURRENCY sau `sortLinesForBalanceLock()`: HTTP [200,422], tồn cuối 2 không âm, 1 movement) + bộ reversal mới; số liệu API/DB before-after lấy từ artifact test tự ghi. Hai lượt (DEV + QA) cùng kết quả. | `docs/evidence/WWHN-264/QA1_RETEST_REPORT_WWHN266_R2.json` |
| DEV266-P1-04R | BA + Tech Lead chốt correction policy cho posted COMPONENT_ISSUE = **append-only reversal** (`DEC-REV-COMPONENT-001`); hiện thực đầy đủ: original movement COMPONENT_ISSUE + movement đối ứng REVERSAL, không UPDATE/DELETE gốc, không direct-edit balance; test subledger/balance/audit/idempotency 8 ca PASS. | `docs/decision-records/DEC-REV-COMPONENT-001.md` · `tests/Feature/Wwhn266ComponentIssueReversalTest.php` · `build/evidence/wwhn-266/component-issue-reversal.json` · commit `4b88662` |
| DEV266-P1-06R | TL review độc lập contract sau D03: **không thêm/bớt operation**, tập 47 operation bắt buộc If-Match không đổi (contract truth 4/4 PASS chạy lại), Postman check PASS; D01 xác nhận doc-only. | DEC-REV-COMPONENT-001 §4 + comment TL trên Jira |
| DEV266-P2-01 | PM chốt planning disposition (comment Jira). | Comment PM trên Jira |
| DEV266-P2-02 | BA chốt BOX/container_items = phương án (b) vận hành, nhất quán với nguyên tắc reversal-không-chạm-BOX; bất biến khoá bằng TC-REV-CI-01. | `DEFECT_REGISTER.json` (BE266-D04 → CLOSED_BY_DISPOSITION) |

Ràng buộc vòng này: **không đụng GitHub Actions / không phát sinh phút CI**
(quota 3.000/3.000 đã cạn) — mọi lượt chạy là local tại exact SHA, và mục
`ciGate` cũ của pin được thay bằng lượt PG chờ quota trong residual risks.
