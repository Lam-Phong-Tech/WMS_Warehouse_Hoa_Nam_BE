# WWHN-202 — DEV202 P0/P1/P2 EXACT FIX ACTION REGISTER (vòng re-review R2)

| Field | Value |
|---|---|
| Jira task | `WWHN-202` — [DEV] Hỗ trợ đối soát/availability · QA1 song hành `WWHN-203` |
| Task cha | `WWHN-47` · liên quan `WWHN-201`, `WWHN-205`, `WWHN-207` |
| Repository | `WMS_Warehouse_Hoa_Nam_BE` + `WMS_Warehouse_Hoa_Nam_FE` · branch `m1` |
| Controlling decision | `DEC-STK-202` · `DEC-INV-001` · SRS/FRD v1.12 §7.5 / §8.1 / §8.2 / §13.10 |
| Điểm đánh giá trước | 63/100 |
| Ngày lập | 2026-08-19 |

Register này bám đúng 6 dòng của bảng chấm điểm. Mỗi dòng ghi: **hiện trạng đo được →
việc đã làm → bằng chứng chạy lại được → phần còn lại thuộc về ai**.

---

## 1. Bảng đóng theo từng ID

| ID | Sev | Trạng thái sau phiên này | Việc đã làm (DEV) | Việc còn lại (không phải DEV) |
|---|---|---|---|---|
| DEV202-P1-01R2 | P1 | **DEV-DONE · chờ PM/Tech Lead** | Đo và chốt `m1` canonical bằng `git merge-base --is-ancestor` (behind 0 / ahead 765) → supersede 14283 không mất commit. Ánh xạ evidence về WWHN-202 bằng `@group wwhn-202` **thay vì đổi tên file** (8 evidence pack đã đóng băng đang pin đường dẫn cũ). Chốt quy tắc candidate SHA suy-từ-artifact + `--expect-sha`. Nêu rõ PR=N/A kèm thứ thay thế chạy được. | PM/Tech Lead post formal supersession trên Jira và ghi exact SHA khi ký |
| DEV202-P1-02R2 | P1 | **DEV-DONE · chờ QA1 (WWHN-203)** | Dựng `Wwhn202OutboundBalanceEvidenceTest` — 11 ca phủ đúng 8 nhóm register liệt kê, **kèm DB before/after thật** ghi ra JSON để QA nộp kèm. Dựng `QA1_RETEST_PACK_WWHN-203.md` (ma trận 13 dòng + bộ SQL 6 truy vấn + bảng kỳ vọng mã lỗi). | QA1 chạy trên exact candidate + PostgreSQL, nộp report `WWHN-203` |
| DEV202-P1-03R2 | P1 | **DEV-DONE · chờ QA1/Tech Lead** | Chính thức hoá 409 vs 422 tại `DEC-STK-202` §D-1 (10 tình huống) và khoá bằng test 4 nhánh. Chạy thật trên **PostgreSQL 16**: 43 test / 457 assertion pass; `migrate:fresh --seed` + `inventory:reconcile` → **0 lệch**; 6 truy vấn đối soát → **0 dòng**. | Tech Lead xác nhận §D-1; phần core `WWHN-201/47` vẫn do task đó đóng |
| DEV202-P1-04R2 | P1 | **DONE** | Chính thức hoá quy tắc gán `item_id` tại `DEC-STK-202` §D-2. Đính chính 3 tuyên bố lỗi thời trong `WWHN-202.md` bằng §7 Amendment (giữ nguyên bản cũ làm dấu vết). Bổ sung hồi quy **phía đọc** cho cả 1-item lẫn >= 2-item; vế write đã có sẵn ở `WWHN-205`. | BA ghi nhận `DEC-STK-202` §D-2 là quy tắc chính thức thay `ATT-11845` |
| DEV202-P1-05R2 | P1 | **DEV-DONE · chờ FE/QA1** | Pin contract theo **slice 8 operation** (không pin cả file — lý do ở §3). **Đóng 1 defect thật**: Postman DEV chỉ có 4/8 bề mặt (§2 D-202-01). Dựng nửa FE: pin module + 22 assertion + `fetchInventoryReconciliation()` biến hằng chết thành bề mặt dùng được. | FE1 và QA1 ghi xác nhận đã dùng đúng slice `b9821645…` |
| DEV202-P2-01R2 | P2 | **DEV-DONE · chờ PM/BAS** | Ghi rõ ranh giới lịch sử vs hiện hành tại `WWHN-202.md` §7.5: giữ nhãn BE2 làm dấu vết phân công, quyền sở hữu hiện hành là đội DEV hợp nhất. | PM/BAS đồng bộ metadata trên Jira |

---

## 2. Defect thật phát hiện và đóng trong phiên này

### D-202-01 (P1) — Bộ Postman DEV thiếu 4/8 bề mặt: "QA uses same contract" không thực hiện được

**Đo được.** Collection `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json`
có 151 request nhưng chỉ 4 trong số 8 bề mặt của output "Balance hỗ trợ":

| Có sẵn | Thiếu |
|---|---|
| `inventory/balances` · `stock-movements` · `stock-movements/:id` · `stock-movements/:id/trace-source` | `inventory/balances/:id/subledger` · `stock-movements/trace-by-code` · `stock-movements/reversible` · `reports/inventory-reconciliation` |

**Vì sao đây là defect chứ không phải thiếu tài liệu.** Register yêu cầu QA1 retest đúng 4
bề mặt nằm trong nhóm thiếu (`trace-by-code`, `reversible`, `reconciliation`, `subledger`).
Không có request thì QA1 hoặc bỏ ca, hoặc tự chế request — cả hai đều phá vỡ điều kiện
"QA và FE dùng cùng một hợp đồng", tức là gate `DEV202-P1-05R2` không thể đóng đúng.

**Sửa.** `scripts/sync-wwhn-202-postman-surfaces.mjs` sinh request còn thiếu **từ chính
OpenAPI** (không viết tay, nên hai artifact không thể mô tả hai thứ khác nhau), `id` tất định
theo `operationId` (chạy lại không tạo diff giả), ghi lại bằng đúng định dạng gốc 4-space đã
kiểm round-trip byte-identical → diff **+321 / -0**. Verifier check C2 canh không cho tụt lại.

### D-202-02 (P1) — Evidence mô tả ngược code, và không có cơ chế nào bắt được

**Đo được.** `WWHN-202.md` mục 1-2 (viết 2026-08-15) khẳng định `stock_movements.item_id`
"luôn NULL cho mọi movement do Post Receipt/Post Issue tạo ra". `WWHN-205` (BE1) đã sửa
write-side sau đó. Từ thời điểm ấy, mọi vòng review đọc evidence này đều kết luận sai về
hành vi hệ thống — và không có test nào đỏ, vì không có test nào khoá *mô tả*.

**Sửa.** (a) `DEC-STK-202` §D-2 nêu quy tắc thay cho mô tả; (b) `WWHN-202.md` §7 Amendment
đính chính, giữ nguyên bản cũ làm dấu vết; (c) verifier check **C4** đọc thẳng source và fail
nếu `resolveLineItemId()` mất điều kiện "đúng 1 item", nếu `postStockDocument()` thôi truyền
`item_id`, hoặc nếu một trong hai nhánh của `traceByPhysicalCode()` bị gỡ. Nói cách khác:
tài liệu và code từ nay không thể trôi khỏi nhau mà cổng vẫn xanh.

### D-202-03 (P2) — Fixture ledger-backed bị nhân bản, chỉ sống trong một file test

`seedLedgerBackedBalance()` / `readyToPostIssueDocumentLedgerBacked()` nằm `private` trong
`Wwhn207OutboundBalanceReconciliationTest`. Mọi test khác cần khẳng định
`reconciliation_status = MATCH` sẽ phải chép lại, và bản chép sẽ trôi. Đã đưa lên
`PreparesOutboundFixtures` (thêm thuần, không đổi hành vi) và gỡ bản trùng.

---

## 3. Hai quyết định kỹ thuật cần nêu rõ để không bị hỏi lại

### 3.1. Vì sao **không** đổi tên `Wwhn207OutboundBalanceReconciliationTest.php`

Register ghi "Map/rename evidence đúng WWHN-202". Chọn **map**, không **rename**, vì đổi tên
làm hỏng 8 evidence pack đã đóng băng đang pin đúng đường dẫn đó (`WWHN-190/191/198/199/201/205`,
`WWHN-231/BASELINE_ISOLATION.md`, `WWHN-239/*`). Sửa evidence đã duyệt để làm đẹp tên file là
đánh đổi sai — nhất là khi dự án đã có tiền lệ "không sửa pack immutable" (`WWHN-237`).

Ánh xạ vì thế phải là **cơ học** chứ không phải lời văn: annotation `@group wwhn-202` trên cả
hai class, chạy được bằng `phpunit --group wwhn-202`, và verifier check C6 fail nếu annotation
bị gỡ.

### 3.2. Vì sao pin **slice** thay vì pin cả file OpenAPI

`storage/api-docs/api-docs.json` sinh tự động từ annotation của **toàn hệ thống**. Trong chính
phiên này, hash cả file đã đổi hai lần do task khác thêm annotation ở module không liên quan.
Pin cả file ⇒ cổng đỏ hằng ngày vì lý do vô can ⇒ cổng bị bỏ qua ⇒ pin trở nên vô dụng, đúng
kiểu thất bại mà register đang phàn nàn ("artifacts exist but unbound").

Pin slice 8 operation, chuẩn hoá khoá rồi băm: chỉ đỏ khi hợp đồng của đúng những bề mặt này
đổi — tức đúng lúc FE và QA1 phải xác nhận lại.

---

## 4. Cổng đóng — chạy lại được

```bash
node scripts/verify-wwhn-202-closure.mjs                      # 7 check
node scripts/verify-wwhn-202-closure.mjs --expect-sha <sha>   # dùng khi Tech Lead ký
node scripts/sync-wwhn-202-postman-surfaces.mjs --check       # độ phủ Postman 8/8
php vendor/bin/phpunit --group wwhn-202                       # bộ evidence WWHN-202
npx vitest run src/test/wwhn-202-balance-contract-pin.test.ts # nửa FE
```

| Check | Nội dung |
|---|---|
| C1 | 8 route tồn tại với đúng permission §8.1 (đọc thẳng `routes/api.php`) |
| C2 | Slice OpenAPI băm lại == pin; Postman phủ đủ 8 bề mặt |
| C3 | Pin phía FE khai cùng slice hash + cùng 8 path |
| C4 | `resolveLineItemId()` giữ quy tắc 1-item; `traceByPhysicalCode()` giữ cả 2 nhánh; không tái xuất `item_instances.status` |
| C5 | `origin/m1-be-2` vẫn là ancestor thuần của `m1` |
| C6 | Test bắt buộc còn tồn tại và còn `@group wwhn-202` |
| C7 | In candidate SHA suy từ commit-cuối-chạm-artifact |

---

## 5. Ghi chú môi trường phát hiện trong phiên (không thuộc WWHN-202)

Ghi lại vì chúng ảnh hưởng tới việc chạy lại bằng chứng, không phải để quy trách nhiệm:

1. **`php artisan migrate` trên PostgreSQL sạch** từng vỡ tại
   `2026_08_19_000100_add_optimistic_lock_to_pv_master_tables` (`SQLSTATE[42804] created_by is
   of type uuid but expression is of type text`). Phiên song song `WWHN-289` đã đưa
   `2026_08_19_000050_repair_erd_mirror_projection_casts` vào và migrate thông trở lại. Bằng
   chứng PostgreSQL của WWHN-202 thu **sau** khi migration đó có mặt.
2. **`phpunit --filter` có thể chết trước khi lọc** nếu một file test của luồng khác trên `m1`
   không nạp được (gặp thật: `tests/Feature/Wwhn310Round2ClosureTest.php` — `json()` khai
   `protected` trong khi lớp cha khai `public`). Cách đi vòng: truyền đường dẫn file cụ thể.
   Không sửa file của phiên khác.
3. **FE có 1 test đỏ không liên quan WWHN-202**: `src/test/wms-contract-signoff-guards.test.ts`
   → "resolves inbound raw code to canonical line_id + qty with If-Match". Nguyên nhân: commit
   `3f2a5c2 fix(WWHN-187)` thêm header `Idempotency-Key` cho scan nhập nhưng chỉ chạy
   `vitest src/modules/inbound`, không chạy guard xuyên luồng này. Thuộc `WWHN-187`.
   Baseline FE tại thời điểm đo: **695 pass / 1 fail / 696**.
4. **Commit gộp của phiên song song**: thay đổi
   `src/modules/inventory/services/inventoryService.ts` của WWHN-202 đã bị commit
   `0dfbe98 docs(WWHN-198)` cuốn theo. Nội dung không mất; chỉ sai quy thuộc. Không viết lại
   lịch sử vì sẽ phá commit của phiên khác — ghi nhận ở đây là cách xử lý đúng.
