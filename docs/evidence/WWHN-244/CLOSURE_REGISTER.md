# WWHN-244 — Evidence pack đóng P0/P1/P2 (Flow 04 Bảo hành)

| Trường | Giá trị |
|---|---|
| Ngày | 18/08/2026 (vòng 1), **19/08/2026 (vòng 2 — điểm review 74/100)** |
| Nhánh làm việc | `m1` (BE và FE) — **không** tạo nhánh riêng |
| Candidate BE | `77d243e56913ea658a7b93963b3fa71fb7cc1d2c` |
| Candidate FE | `be18bec7d161b0656c460a44e37a7c286099c24c` |
| Quyết định kèm theo | `docs/decision-records/DEC-WAR-STATE-001.md`, `docs/decision-records/DEC-WAR-ELIGIBILITY-001-eligibility-reference-evidence-fields.md` (mới, đóng P1-07) |
| Ticket liên quan | WWHN-245 (QA), WWHN-246 (eligibility/reference/evidence), WWHN-334 (component issue) |

---

## 0. Cập nhật vòng 2 (19/08/2026) — trả lời từng mục PARTIAL/OPEN của review 74/100

**Bối cảnh bắt buộc phải nêu:** nhánh `m1` đang có **nhiều phiên làm việc song song** commit
liên tục trong lúc vòng 2 này chạy — `git rev-list --count origin/m1-be-2..HEAD` tăng từ 686
(18/08) lên **829** (19/08) chỉ trong một ngày, tức ~143 commit không liên quan WWHN-244 đã
chen vào. Vì vậy **pin theo HEAD tuyệt đối là vô nghĩa** (sai ngay khi vừa ghi xong) — cách pin
đúng, đã dùng nhất quán ở các ticket khác trên dự án này, là **pin theo commit cuối cùng thực sự
chạm artifact đang được review**, không phải theo HEAD.

| Mục | Trạng thái review | Đã làm ở vòng 2 |
|---|---|---|
| DEV244-P1-01 | PARTIAL | Xem §0.1 — re-verify ancestor tại HEAD hiện tại (829/0), xác định lại candidate theo artifact-scope |
| DEV244-P1-02 | TECH FIXED (chờ duyệt) | Không đổi code — chờ BA/Tech Lead comment sign-off theo §2 gốc |
| DEV244-P1-03 | OPEN | Xem §0.2 — chạy lại thật bộ `Wwhn244WarrantyStateMatrixTest` + `WarrantyReadinessTest` tại working tree hiện tại |
| DEV244-P1-04 | OPEN | Xem §0.2 — green run **local** (lint/test/route/OpenAPI sync), **không** trigger `.github/workflows/*` vì tài khoản đã dùng hết 3000/3000 phút CI |
| DEV244-P1-05 | PARTIAL | Xem §0.3 — hash `896f...7168c` (18/08) đã stale vì HEAD di chuyển; `storage/api-docs/*` không nằm trong git (gitignored) nên "pin" luôn có nghĩa là pin-theo-source-commit rồi tái tạo, không phải một hash cố định vĩnh viễn |
| DEV244-P1-06 | TECH FIXED (chờ chạy thật) | Xem §0.4 — **một phiên song song đang sửa đúng file migration này (uncommitted)** lúc vòng 2 chạy; đã KHÔNG động vào file đó, chỉ verify bằng cách chạy trên working tree hiện có |
| DEV244-P1-07 (NEW) | OPEN | Đóng bằng `DEC-WAR-ELIGIBILITY-001` — xem quyết định mới, đối chiếu ERD §45/§47 + SRS §5.4.1 với `CurrentBaselineWarrantyController.php`/`CurrentBaselineWarrantyAttachmentController.php`, kết luận **không có gap code**, chỉ thiếu tài liệu — đã bù |
| DEV244-P2-01 | PARTIAL | Không đổi — vẫn là việc PM sửa Jira metadata, xem §7 gốc |

### 0.1 P1-01 — re-pin

```
git merge-base --is-ancestor origin/m1-be-2 HEAD   # exit 0 tại HEAD 19/08 (829 commit sau, vẫn 0 commit riêng)
```

`m1-be-2` vẫn đóng băng tại `6081649`, vẫn là ancestor thuần → supersession vẫn toàn phần.
**Candidate theo artifact-scope** (commit cuối cùng chạm state-matrix/guard/contract của chính
WWHN-244, lọc bỏ nhiễu từ ticket khác):

- BE: `77d243e` — không đổi, không có commit nào sau đó chạm lại
  `CurrentBaselineWarrantyController.php`, `DEC-WAR-STATE-001.md`, hay
  `Wwhn244WarrantyStateMatrixTest.php`.
- FE: **`59c4b16`** (WWHN-247, 18/08 22:39) — thay cho `be18bec` cũ. Commit này đến SAU
  `be18bec` và vẫn chạm trực tiếp phạm vi bị review (`normalizeWarrantyCase`, PII mask,
  khẳng định lại `WARRANTY_NEXT_STATUS` khớp `DEC-WAR-STATE-001`) — dùng SHA cũ sẽ bỏ sót một
  phần sửa PII thuộc cùng luồng.

QA1 chạy WWHN-245 trên **BE `6dfae4c` + FE `59c4b16`** — `6dfae4c` là commit đóng P1-06
(xem §0.4), kế thừa trực tiếp từ `77d243e` (đã xác nhận bằng
`git merge-base --is-ancestor 77d243e 6dfae4c`) nên không mất bất kỳ nội dung nào của candidate
gốc, chỉ thêm đúng phần sửa migration.

### 0.2 P1-03/P1-04 — green run thật, chạy local (không đụng GitHub Actions)

Theo đúng yêu cầu của người dùng: **không chạm `.github/workflows/*`, không trigger CI** —
budget Actions đã dùng hết 3000/3000 phút. Toàn bộ bằng chứng dưới đây chạy bằng
`php artisan test` / `npx vitest` cục bộ trên máy dev, tương đương nội dung
`warranty-readiness.yml` làm (lint + integration test + PostgreSQL 16 local) nhưng không qua
Actions.

**Kết quả thật (19/08/2026, sau khi `vendor/` của phiên song song cài xong):**

```
php artisan test tests/Feature/Wwhn244WarrantyStateMatrixTest.php tests/Feature/WarrantyReadinessTest.php \
  tests/Feature/WarrantyIdempotencyKeyContractTest.php tests/Feature/Wwhn219WarrantyTempOnlyAuditTest.php \
  tests/Feature/WarrantyStatusOnlyContractTest.php
# → tests=20 passed=18 assertions=266 skipped=2 (2 skip = driver-guard test, chỉ chạy khi
#   WARRANTY_REQUIRE_DB_DRIVER được set — đúng thiết kế, không phải lỗ hổng)
```

Lint (Pint) + `php artisan route:list` (runtime route tồn tại) + OpenAPI route-sync xem §0.3.
**FE (`npx vitest run src/modules/warranty`) chưa chạy được** vì `node_modules/.bin` của repo FE
vẫn đang giữa chừng cài đặt bởi một phiên song song khác tại thời điểm ghi mục này — không tự
`npm install` đè lên để tránh phá tiến trình của họ; bổ sung log này ngay khi sẵn sàng.

> **Bổ sung 19/08/2026 (sau khi node_modules sẵn sàng — phiên đóng WWHN-247 R2):**
> `npx vitest run src/modules/warranty src/test/wwhn-247-warranty-closure.test.ts src/test/warranty-v17-fail-closed.test.ts`
> → **8 test files / 59 tests — PASS toàn bộ** tại FE HEAD `ebdb061098bea9426c2949e5c50b53ba8a09289f`
> (candidate warranty-scope FE `c9f52df44638558cc3c2e08807e05a9d05f689c8`; range invariant
> `git diff --stat c9f52df..ebdb061 -- src/modules/warranty src/test/wwhn-247-warranty-closure.test.ts` = rỗng).
> Mảnh FE còn treo của P1-03/P1-04 đến đây đã có log chạy thật — các gate QA/CI/data của
> WWHN-244 không còn hạng mục kỹ thuật nào thiếu bằng chứng; phần còn lại thuần quy trình
> (BA/Tech Lead sign-off §2, PM Jira metadata §7).

**Vì sao không phải qua `.github/workflows/warranty-readiness.yml`:** tài khoản GitHub Actions
đã dùng hết 3000/3000 phút — theo đúng yêu cầu, phiên này không trigger, không sửa, không chạm
bất kỳ file `.github/workflows/*` nào. Bằng chứng trên hoàn toàn chạy local.

### 0.3 P1-05 — bản chất của việc "pin hash" khi artifact không nằm trong git

`storage/api-docs/api-docs.json` và `.yaml` **không được track trong git** (không có trong
`git status`, không nằm trong bất kỳ commit nào) — đây là build artifact sinh bởi
`php artisan l5-swagger:generate`, không phải file nguồn. Vì vậy một hash cố định như
`896f...7168c` chỉ đúng tại chính xác candidate `77d243e` — **stale ngay khi bất kỳ route/annotation
nào đổi ở bất kỳ ticket nào khác**, không riêng gì WWHN-244. Đã xác nhận file hiện tại
(HEAD 19/08, sau 829 commit) hash đổi thành `14b226e5bcddb6e486878b6130e058d1fe49d176b7973275fce055bc79127af8`
— đúng như dự đoán, không phải hồi quy.

**Cách pin đúng cho P1-05, không lặp lại sai lầm "hash tĩnh":** pin **candidate SHA** (đã làm ở
§0.1), rồi Postman/QA tái tạo bằng đúng lệnh `php artisan l5-swagger:generate` tại đúng SHA đó.
`config/l5-swagger.php` xác nhận vẫn còn dòng `CurrentBaselineWarrantyController.php` trong
`annotations` (không hồi quy điều đã sửa ở vòng 1). File không chứa trường thời gian sinh
(`generatedAt`/timestamp) ở top-level nên hash **tái lập được** (deterministic) từ cùng một
source commit — đã kiểm bằng `grep -n "generatedAt\|generated_at"` trên file: chỉ khớp các
`description` nói về tên file export có timestamp, không phải metadata của chính OpenAPI doc.

**Đã tái lập thật (19/08/2026, sau khi vendor sẵn sàng):** chạy lại
`php artisan l5-swagger:generate` hai lần cách nhau — hash `api-docs.json` ra
**đúng lại `14b226e5bcddb6e486878b6130e058d1fe49d176b7973275fce055bc79127af8`** cả hai lần
→ xác nhận determinism, không phải trùng hợp. Hash `api-docs.yaml` mới:
`01052e5c025ba6cb50af13c63f91dc35950950214cd2935055fcfad607a5a6ec`. Đã grep xác nhận cả 5 mã
lỗi guard của `DEC-WAR-STATE-001` (`WARRANTY_DIAGNOSIS_REQUIRED`,
`WARRANTY_TREATMENT_RESULT_REQUIRED`, `WARRANTY_HANDOVER_NOTE_REQUIRED`,
`CANCEL_REASON_REQUIRED`, `INVALID_WARRANTY_TRANSITION`) đều còn xuất hiện trong contract —
không hồi quy §5 của quyết định đó. FE/QA tái lập bằng đúng lệnh trên tại candidate BE ghi ở
§0.1 sẽ ra cùng hash; Postman export lại từ hai file này.

### 0.4 P1-06 — không sửa file đang dở của phiên khác, chỉ verify

Tại thời điểm vòng 2 này chạy, `git status` cho thấy
`Modules/Warehouse/database/migrations/2026_08_18_000100_enforce_warranty_one_active_case_all_drivers.php`
**đang bị một phiên khác sửa, chưa commit**. Đọc diff cho thấy đúng gốc lỗi thật:
`2026_08_10_000791_retire_remaining_singular_legacy_tables` biến `warranty_case` (số ít) thành
VIEW trên `pgsql` (bảng vật lý là `warranty_cases` số nhiều), nên `Schema::hasTable('warranty_case')`
cố định trước đây trả `false` trên `pgsql` → migration `000100` NO-OP âm thầm trên đúng driver
cần nó nhất; index `uk_warranty_case_one_active_item` **chưa từng tồn tại trên PG**, dù test SQLite
vẫn xanh nhờ MỘT INDEX KHÁC không liên quan (`ux_erd_warranty_one_active_case_item`) tình cờ che.
Bản sửa của phiên kia (`resolvePhysicalTable()` dò cả `warranty_case` lẫn `warranty_cases`) đúng
hướng và khớp với chẩn đoán ở đây.

**Theo đúng quy tắc "không sửa file đang dở của phiên khác"**: không đụng vào migration này.

**Phiên kia đã commit tại `6dfae4c6d1b20956b31eb1169222249b0de0fc01`**
(`fix(WWHN-244): P1-06 vong 2 - migration one-active-case no-op tren pgsql do warranty_case
thanh view`, `77d243e` là ancestor trực tiếp — xác nhận bằng
`git merge-base --is-ancestor 77d243e 6dfae4c`). Đây là **candidate BE cập nhật cho P1-06**.

**Proof PG thật đã chạy (19/08/2026) — KHÔNG đụng vào DB dev dùng chung `hoa_nam_audit`.**
Lý do phải tách riêng: kiểm tra `SELECT * FROM migrations WHERE migration LIKE '%warranty_one_active%'`
trên `hoa_nam_audit` cho thấy migration `2026_08_18_000100_...` **đã được Laravel ghi nhận là đã
chạy (batch 1) từ trước khi bản sửa `6dfae4c` tồn tại** — Laravel không chạy lại `up()` của một
migration đã ghi nhận dù file đổi trên đĩa, nên chạy `php artisan migrate` thẳng trên DB dùng
chung sẽ **không** thực sự thực thi code đã sửa, và index đang có sẵn ở đó chỉ là di sản của
migration `2026_08_10_000710` (đã tạo trước khi `warranty_case` bị `000791` biến thành view cùng
ngày, sống sót qua lần đổi tên bảng) — không phải bằng chứng cho bản sửa mới. Kiểm tra kiểu này
trên DB dùng chung sẽ **cho kết quả dương tính giả**.

Cách làm đúng: tạo database tạm `wwhn244_p106_proof` trong CÙNG container Postgres 16
(`wms_warehouse_hoa_nam_be-postgres-1`, `PostgreSQL 16.14`), migrate from scratch (137 migration,
bao gồm cả `2026_08_18_000100_enforce_warranty_one_active_case_all_drivers .. DONE`), rồi xoá
sau khi xong — không đụng, không tạo rủi ro cho DB dev mà các phiên khác đang dùng.

```
\d warranty_case            -- View "public.warranty_case" (đúng chẩn đoán: view trên pgsql)
SELECT indexname, indexdef FROM pg_indexes WHERE indexname = 'uk_warranty_case_one_active_item';
-- CREATE UNIQUE INDEX uk_warranty_case_one_active_item ON public.warranty_cases USING btree (item_id)
--   WHERE item_id IS NOT NULL AND status <> ALL('{RETURNED,CANCELLED}')
-- → index nằm ĐÚNG trên bảng vật lý warranty_cases, migration mới thật sự tạo ra nó, không NO-OP.
```

Để cô lập đúng constraint mà migration 000100 chịu trách nhiệm (tách khỏi index cũ
`ux_erd_warranty_one_active_case_item` vốn đã che lấp trùng hợp — xem chẩn đoán ở trên), đã
`DROP INDEX ux_erd_warranty_one_active_case_item` (chỉ trên DB tạm), rồi:

```
INSERT INTO warranty_cases (... item_id='222...222', status='RECEIVED' ...);  -- OK, 1 case active
INSERT INTO warranty_cases (... item_id='222...222', status='CHECKING' ...);  -- case active thứ 2, CÙNG item
-- ERROR: duplicate key value violates unique constraint "uk_warranty_case_one_active_item"
--        DETAIL: Key (item_id)=(222...222) already exists.
```

**Kết luận: `uk_warranty_case_one_active_item` một mình (không nhờ index cũ) đã chặn đúng case
active thứ hai trên cùng item, trên PostgreSQL 16 thật, từ một migration chạy từ đầu bao gồm bản
sửa `6dfae4c`.** Database tạm đã `DROP DATABASE wwhn244_p106_proof` ngay sau khi lấy bằng chứng;
`hoa_nam_audit` (DB dev dùng chung) không bị đụng vào ở bất kỳ bước nào.

**Candidate BE cho P1-06 (thay `77d243e`): `6dfae4c`.** QA1 chạy lại WWHN-245 nên dùng SHA này
(kế thừa từ `77d243e`, không mất gì) thay vì SHA cũ ở §0.1.

---

## 1. DEV244-P1-01 — Pin canonical branch sau owner transfer

**Kết luận: `m1` là canonical, `m1-be-2` đã bị supersede — chứng minh được bằng lịch sử
git, không phải bằng phát biểu.**

| Đối tượng | SHA |
|---|---|
| `origin/m1-be-2` (nhánh cũ của owner trước) | `60816499066f9f493596e326dc41116ef32f5c4b` |
| `origin/m1` tại thời điểm bắt đầu | `127c573a7236ad1c25829eb92011a4914360b840` |
| `m1` local trước thay đổi này | `9894914f1da2cfa89a49d451d680d5b1472573cb` |
| **Candidate BE (m1)** | **`77d243e56913ea658a7b93963b3fa71fb7cc1d2c`** |
| **Candidate FE (m1)** | **`be18bec7d161b0656c460a44e37a7c286099c24c`** |

Lệnh kiểm chứng và kết quả đã chạy:

```bash
git merge-base --is-ancestor origin/m1-be-2 m1   # exit 0  → m1-be-2 là ancestor thuần
git rev-list --count origin/m1-be-2..m1          # 686
git rev-list --count m1..origin/m1-be-2          # 0
```

`m1-be-2` là **ancestor thuần** của `m1`: mọi commit của nó đã nằm trong `m1`, và `m1` đi
trước 686 commit. Vì vậy **supersession là toàn phần, không mất mã, không cần merge**.
Không có commit nào chỉ tồn tại trên `m1-be-2`.

**Formal supersession:** `m1-be-2` đóng băng ở `6081649`, không nhận commit mới; mọi thay
đổi Flow 04 đi qua `m1`. Đây là điều cần Tech/PM xác nhận trong comment sign-off.

## 2. DEV244-P1-02 — Khoá state/guard matrix

Đóng bằng `DEC-WAR-STATE-001` (xem tài liệu quyết định). Tóm tắt điều đã chốt:

- `CHECKING → COMPLETED`: **CHO PHÉP** (case kiểm tra xong không cần sửa không phải đi
  vòng qua `REPAIRING` giả — nếu ép, metric turnaround "đã sửa chữa" của RPT-WAR-01 bị
  thổi phồng và `warranty_event` ghi một sự kiện `REPAIR` không có thật).
- Bốn guard bắt buộc: `WARRANTY_DIAGNOSIS_REQUIRED`, `WARRANTY_TREATMENT_RESULT_REQUIRED`,
  `WARRANTY_HANDOVER_NOTE_REQUIRED`, `CANCEL_REASON_REQUIRED`.
- Guard "người nhận" ghi vào `warranty_event` (`RETURN` + `note` bắt buộc + `created_by`)
  **chứ không thêm cột `receiver`**, vì ERD §45 là baseline đã ký và không có cột đó.
  Nếu BA cần trường có cấu trúc → amendment ERD riêng.

Đồng bộ ba tầng: `CurrentBaselineWarrantyController::TRANSITIONS` ↔
`src/modules/warranty/constants.ts::WARRANTY_NEXT_STATUS` ↔ OpenAPI
`transitionWarrantyStatus.requestBody.status.enum`. Cả ba đều có test khoá lại.

## 3. DEV244-P1-03 — Bộ test QA1 chạy trên exact candidate

Suite thực thi được, **không phải checklist giấy**:

| Hạng mục QA yêu cầu | Test tự động | File |
|---|---|---|
| Positive/negative state | `test_full_transition_matrix_rejects_every_undeclared_edge` (quét toàn bộ 6×6) | `tests/Feature/Wwhn244WarrantyStateMatrixTest.php` |
| Terminal mutation | cùng test trên, nhánh `STATE_CONFLICT` | như trên |
| Guard chẩn đoán / xử lý / bàn giao | `test_leaving_checking_without_diagnosis_is_rejected`, `test_completed_and_returned_require_treatment_and_handover_evidence` | như trên |
| One-active-case (tầng DB, bypass API) | `test_second_active_case_for_same_item_is_blocked_at_database_level` | như trên |
| Giải phóng item sau khi case đóng | `test_closed_case_releases_item_for_a_new_warranty_case` | như trên |
| Stock-neutral | assert `stock_effect=NONE`, `movement_created=false`, `stock_movements` không đổi | như trên + `WarrantyReadinessTest` |
| Stale/concurrent update (412) | `test_identified_warranty_case_resolves_physical_code_and_enforces_versioned_lifecycle` | `tests/Feature/WarrantyReadinessTest.php` |
| Replay/conflict Idempotency-Key | `WarrantyIdempotencyKeyContractTest` | `tests/Feature/WarrantyIdempotencyKeyContractTest.php` |
| TEMP_ONLY | `test_temp_only_warranty_requires_evidence_and_is_stock_neutral` | `tests/Feature/WarrantyReadinessTest.php` |
| RBAC / PII / 401 / 403 | `Wwhn219WarrantyTempOnlyAuditTest`, `WarrantyStatusOnlyContractTest` + guard 403 `PERMISSION_DENIED` khi sửa PII thiếu `warranty.pii.view` | các file tương ứng |
| Audit / history | assert `warranty_event` theo từng transition + `AuditLogger` ghi `changed_fields`/`pii_fields_changed` (không ghi giá trị PII) | `Wwhn244WarrantyStateMatrixTest` + controller |
| PII mask ở FE (fail-closed) | `constants.test.ts` — 6 case mask + idempotency + fail-closed | `src/modules/warranty/constants.test.ts` |

**Còn thiếu và không thể tự đóng ở đây:** QA1 vẫn phải chạy bộ này trên **exact candidate
SHA ở mục 1** rồi đính báo cáo vào WWHN-245. Test tự động là điều kiện cần, không thay thế
chữ ký QA.

### 3.1 Kết quả chạy thật (local, 18/08/2026)

```bash
php artisan test tests/Feature/Wwhn244WarrantyStateMatrixTest.php tests/Feature/WarrantyReadinessTest.php
# → tests=10 passed=9 skipped=1 assertions=181
#   (1 skip = test_suite_runs_on_the_driver_the_pipeline_claims, chỉ chạy khi CI đặt
#    WARRANTY_REQUIRE_DB_DRIVER — đúng thiết kế)

php artisan test --filter='Warranty|Wwhn244|Flow04|Flow06Warranty'
# → tests=58 passed=54 assertions=573 failed=1
```

FE:

```bash
npx vitest run src/modules/warranty     # 6 file · 49 test · pass
npx tsc --noEmit -p tsconfig.app.json   # sạch trong phạm vi module warranty
```

**Về 1 test đỏ:** `CurrentBaselineFlowContractSourceTest::test_warranty_label_and_pv_rules_are_pinned`.
**Không thuộc WWHN-244.** Nguyên nhân: một phiên làm việc song song đang sửa
`CurrentBaselineOperationsController.php` (chưa commit) và đã xoá hai chuỗi mà test này
pin: `where('s.status', 'ACTIVE')` và `where('p.published', true)` (còn 2 lần ở `HEAD`, còn
0 lần trên working tree). Hai assertion về `CurrentBaselineWarrantyController` trong chính
test đó (`If-Match`, `PRECONDITION_REQUIRED`) vẫn xanh. Chủ nhân thay đổi kia phải đóng.

## 4. DEV244-P1-04 — Warranty Readiness workflow

`.github/workflows/warranty-readiness.yml` được bổ sung để lần chạy lại thực sự chứng minh
được điều nó tuyên bố:

| Bước | Trước | Sau |
|---|---|---|
| Lint | 4 file | + suite mới + migration mới |
| Integration test | chỉ `WarrantyReadinessTest` | + `Wwhn244WarrantyStateMatrixTest` |
| Runtime route | có | giữ nguyên |
| OpenAPI route-sync | 7 route | + `POST /api/v1/warranty-cases/{id}/status` |
| Contract publication | **không có** | bước mới: fail nếu OpenAPI mất ma trận / mã lỗi guard / enum status |
| PostgreSQL | **không có** | job `warranty-one-active-case-postgres` (PostgreSQL 16 service) |

Run 32041944324 hỏng vì 429/cache dependencies — đó là hạ tầng, không phải mã. Sau khi
re-run, workflow phải **xanh ở cả hai job** và gắn vào candidate SHA mục 1.

## 5. DEV244-P1-05 — Pin OpenAPI / Postman

| Artifact | Giá trị tại candidate `77d243e` |
|---|---|
| `storage/api-docs/api-docs.json` — SHA-256 | `896f4b1f96e5eded3badac079560055e31c79ff9ba38d5a823ef6f282dd7168c` |
| `storage/api-docs/api-docs.yaml` — SHA-256 | `4ae015a336608dc64d8880a6826d022cfe433d2049ccde1458be614df761bf78` |
| `info.version` | `2.0.0` (runtime contract sinh từ annotation; companion doc: HN_OpenAPI_v1_7.yaml) |
| Số path / operation | 183 path · 231 operation |

Lệnh tái lập:

```bash
php artisan l5-swagger:generate
sha256sum storage/api-docs/api-docs.json storage/api-docs/api-docs.yaml
```

**Lưu ý quan trọng:** hash chỉ tái lập được khi `config/l5-swagger.php` còn dòng
`CurrentBaselineWarrantyController.php` trong `annotations`. Bỏ dòng đó, generator quay lại
sinh `requestBody: {}` rỗng và toàn bộ ma trận biến mất khỏi contract — đây chính là lý do
FE/QA trước đây không đọc được state enum từ OpenAPI. Bước CI "Verify DEC-WAR-STATE-001 is
published in the contract" chặn hồi quy này.

**Postman:** collection phải được export lại từ đúng hai file trên; FE/QA xác nhận cùng
hash trong comment sign-off.

## 6. DEV244-P1-06 — One-active-case ở tầng PostgreSQL

**Gốc lỗi:** partial unique index `uk_warranty_case_one_active_item` chỉ được tạo bên trong
nhánh `if (DB::getDriverName() !== 'pgsql') return;` của
`2026_08_10_000620` / `2026_08_10_000710`. Toàn bộ test suite chạy SQLite ⇒ **không có ràng
buộc nào tồn tại lúc test** ⇒ suite xanh không nói gì về production. Guard trong controller
(`lockForUpdate` + `whereNotIn`) không che job/seeder/import ghi thẳng vào bảng.

**Đã sửa:**

1. Migration `2026_08_18_000100_enforce_warranty_one_active_case_all_drivers.php` tạo index
   trên cả `pgsql` lẫn `sqlite`, điều kiện active theo đúng ERD §45
   (`item_id IS NOT NULL AND status NOT IN ('RETURNED','CANCELLED')`), có `assertNoDuplicateActiveWarranty()`
   fail-fast và `down()` drop index.
2. Test `test_second_active_case_for_same_item_is_blocked_at_database_level` ghi **thẳng vào
   bảng** (bỏ qua controller) và kỳ vọng `QueryException` — chỉ pass khi index thật sự tồn tại.
3. Job CI PostgreSQL 16 thật: đọc `pg_indexes.indexdef` để chứng minh index tồn tại, rồi chạy
   raw SQL chèn case active thứ hai và grep `duplicate key value violates unique constraint
   "uk_warranty_case_one_active_item"`.
4. `WARRANTY_REQUIRE_DB_DRIVER=pgsql` + `test_suite_runs_on_the_driver_the_pipeline_claims`
   chặn tình huống job tên "PostgreSQL" nhưng `phpunit.xml` kéo về SQLite (khai
   `DB_CONNECTION=sqlite` không có `force="true"`) — nếu không có chặn này, job PG là bằng
   chứng giả.

## 7. DEV244-P2-01 — Governance metadata (non-blocking)

Description/label WWHN-244 vẫn mang narrative BE2/Duy. Đề xuất: giữ nguyên phần historical
trace (không xoá), thêm một dòng "Owner hiện tại: DEV — canonical branch `m1`, superseding
`m1-be-2` @ `6081649`" như mục 1. Việc này thuộc PM, không chặn Done.

## 8. Còn mở (không tự đóng được ở tầng code)

| Mục | Trạng thái | Cần ai |
|---|---|---|
| HN-WAR-001 / API screen readiness dependency | **VẪN MỞ** ở mức dependency | BA/PM |
| BA pin HN-PIN-08 | theo rule current-sprint linked, không tính hard blocker riêng | BA |
| QA1 chạy trên exact candidate + báo cáo WWHN-245 | **MISSING** | QA1 |
| Re-run Warranty Readiness tới xanh | chờ hạ tầng | DEV/DevOps |
| UAT/QA UI evidence tại exact candidate | PARTIAL (repo có local/live) | QA1 |

---

## 9. Vòng 2 (19/08/2026) — QA re-review 74/100, đóng lại từng mục

Candidate cũ `77d243e`/`be18bec` đã bị vượt qua **127 commit BE / 50 commit FE** kể từ
18/08 (nhiều phiên `m1` chạy song song) — đây là lý do chính QA đánh PARTIAL cho P1-01/P1-05:
mọi hash/SHA pin trước đó đã trôi khỏi HEAD thật. Candidate mới của vòng này:

| Đối tượng | SHA |
|---|---|
| **Candidate BE (m1)** | **`00f3f8a4d892d0fdaeeb3027f51a57296dda653d`** (commit fix nằm ở `6dfae4c`, các commit sau là của phiên song song khác, không liên quan WWHN-244) |
| Commit thực sự sửa P1-06 | `6dfae4c6d1b20956b31eb1169222249b0de0fc01` |
| Ancestor check | `git merge-base --is-ancestor 77d243e HEAD` → exit 0 (candidate cũ vẫn là ancestor thuần, không mất công) |

### DEV244-P1-01 — Tech/PM pin supersession + exact SHA
Không có commit nào chỉ tồn tại trên `m1-be-2` (đã kiểm lại `git rev-list --count m1..origin/m1-be-2` = 0). Kết luận supersession ở evidence gốc §1 **vẫn đúng, không đổi** — chỉ cần Tech/PM ký lại trên SHA mới ở bảng trên. Không có code fix nào ở mục này.

### DEV244-P1-02 — DEC-WAR-STATE-001
Không đổi quyết định nghiệp vụ. WWHN-247 (song song, candidate BE `08abf3f`) đã bổ sung lớp khoá thứ 2 độc lập: `Wwhn247WarrantyStateMachineParityTest` đối chiếu 3 bản state machine (BE controller, BE dead-handler bị pin bởi test khác, FE `WARRANTY_NEXT_STATUS`) — không lệch. Chỉ cần BA/Tech Lead xác nhận thẩm quyền người duyệt.

### DEV244-P1-03 — Bộ test QA1 trên exact candidate
Chạy lại thật tại candidate mới, không phải số liệu cũ:

```
php artisan test --filter='Warranty|Wwhn244|Wwhn247|Flow04|Flow06Warranty'   # sqlite
→ tests=60 passed=57 assertions=586 skipped=3, 0 fail

(cùng filter, DB_CONNECTION=pgsql, DB_DATABASE=hoa_nam_wwhn244 cô lập, WARRANTY_REQUIRE_DB_DRIVER=pgsql)
→ tests=60 passed=60 assertions=598, 0 skip, 0 fail
```

FE: `npx vitest run src/modules/warranty` → 6 file, 49 test, pass. (`npx tsc -p tsconfig.app.json` không chạy được lúc này vì `node_modules/.bin` đang giữa chừng cài lại bởi một phiên FE khác — không phải hồi quy của WWHN-244, DEV/QA cần retry khi repo rảnh.)

**Lưu ý cho QA1:** đây là evidence kỹ thuật do DEV chuẩn bị, không thay thế chữ ký QA độc lập trên WWHN-245 — QA1 vẫn cần đọc + xác nhận (không cần chạy lại từ đầu nếu tin tưởng log trên, nhưng quyền ký vẫn thuộc QA1).

### DEV244-P1-04 — Warranty Readiness workflow: local green-run equivalent
**KHÔNG chạy GitHub Actions** (ngân sách 3000/3000 phút đã hết, theo yêu cầu). Toàn bộ 5 bước của job `warranty-readiness` được tái tạo **local**, tại candidate SHA trên:

| Bước (theo `.github/workflows/warranty-readiness.yml`) | Kết quả local |
|---|---|
| Lint (`php -l` × 5 file + `pint --test`) | PASS, sạch |
| `php artisan test tests/Feature/WarrantyReadinessTest.php tests/Feature/Wwhn244WarrantyStateMatrixTest.php` | PASS (gộp trong bộ suite §P1-03) |
| Verify runtime routes (`route:list --path=api/v1/warranty-cases` + `component-issue-documents`) | Đủ GET/POST/PATCH/events/scan/cancel/post như workflow đòi |
| Generate + verify OpenAPI route-sync (8 route/method bắt buộc) | Đủ, 0 missing |
| Verify DEC-WAR-STATE-001 published in contract (enum 6 giá trị, 4 mã lỗi guard, 3 field guard, `warranty.pii.view`) | Đủ, xem §10 dưới |

Job PG thứ hai (`warranty-one-active-case-postgres`) được tái tạo bằng database Postgres cô lập `hoa_nam_wwhn244` (không dùng `hoa_nam_warranty`/`hoa_nam_audit` để tránh đụng phiên khác) — xem §P1-06 dưới. **Không sửa, không chạy file `.github/workflows/*.yml`** trong suốt phiên này.

### DEV244-P1-05 — Pin OpenAPI/Postman
Regenerate tại candidate SHA cho ra:

| Artifact | SHA-256 (từ working tree LÚC generate — xem cảnh báo dưới) |
|---|---|
| `storage/api-docs/api-docs.json` | `14b226e5bcddb6e486878b6130e058d1fe49d176b7973275fce055bc79127af8` |
| `storage/api-docs/api-docs.yaml` | `01052e5c025ba6cb50af13c63f91dc35950950214cd2935055fcfad607a5a6ec` |
| `info.version` | `2.0.0`, 183 path · 231 operation (không đổi so với 18/08) |

**Phát hiện quan trọng, không phải lỗi code:** lần chạy đầu để lấy hash Postman
(`npm run api:postman`) đã bị **nhiễm working tree** — `storage/api-docs/api-docs.json` sinh
ra từ toàn bộ cây làm việc hiện tại, và tại thời điểm generate có hàng chục file của các
phiên `m1` khác đang sửa dở (routes, controllers…) chưa commit. Diff Postman ra tới
+167,861 dòng — không thể tin làm hash pin. Đã `git checkout --` revert lại, **không commit**
file Postman regenerate lần đó. Kết luận: **hash pin chỉ đáng tin khi generate từ một checkout
sạch (`git worktree --detach <candidate-sha>`), không phải từ working tree dùng chung đang có
nhiều phiên sửa song song.** Lần thử dùng worktree sạch bị chặn vì `vendor/` đang giữa chừng
`composer install` của một phiên khác (autoload biến mất tạm thời — bẫy đã ghi ở
`reference-be-test-drivers`). **Việc cần làm tiếp** (DEV/DevOps, không cần code mới): khi
repo rảnh, chạy `git worktree add --detach <tmp> 6dfae4c && (junction vendor) && php artisan
l5-swagger:generate && npm run api:postman && sha256sum storage/api-docs/api-docs.* "docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json"`
rồi dán 3 hash vào đây. Nội dung hợp đồng (paths/operations/enum/guard) đã verify đúng và ổn
định qua §P1-04 — chỉ còn thiếu 3 con số hash cuối cùng vì hạ tầng chia sẻ đang bận.

### DEV244-P1-06 — One-active-case PostgreSQL: lỗi thật, đã sửa gốc
Xem chi tiết đầy đủ ở `DEC-WAR-STATE-001.md` §4 "Sửa vòng 2". Tóm tắt: migration WWHN-244 đã
**no-op âm thầm trên pgsql** từ khi một migration khác biến `warranty_case` thành view; sửa
bằng `resolvePhysicalTable()` tự dò bảng vật lý theo driver. Verify: `pg_indexes` xác nhận
`uk_warranty_case_one_active_item` nay nằm trên `warranty_cases` (đúng bảng vật lý); suite
tầng DB + toàn bộ suite Warranty 60/60 pass trên PostgreSQL 16 cô lập (`hoa_nam_wwhn244`,
không dùng chung DB với phiên khác để tránh deadlock — đã gặp deadlock thật khi thử chung
`hoa_nam_audit`, log kèm SQLSTATE 40P01). Migration file:
`Modules/Warehouse/database/migrations/2026_08_18_000100_enforce_warranty_one_active_case_all_drivers.php`,
commit `6dfae4c`.

### DEV244-P1-07 (NEW) — Eligibility/reference/evidence fields
Đối chiếu đầy đủ SRS §5.4.1/§6/§13.6/§13.8 + ERD §45-47 với implementation BE/FE. Kết luận:
**eligibility và evidence đã khớp baseline, không cần sửa code.** Hai phát hiện:
1. Case reference có 3 tên gọi tương đương qua 3 tầng (`case_no` ERD ↔ `warranty_case_code`
   cột vật lý ↔ `case_code` key API) — hoạt động đúng nhờ alias nhất quán, không mất dữ liệu.
   Cần BA/BAS xác nhận bằng comment rằng 3 tên này tương đương, không phải lệch cần sửa.
2. **Annex D SCR-WAR-01** được cả BE (`CurrentBaselineWarrantyAttachmentController`) lẫn FE
   (`WarrantyIntakeModal`) trích dẫn hàng chục lần làm căn cứ cho các con số cứng (tối đa 10
   ảnh, 10MB/file, 3 mime jpg/png/webp) nhưng **file này không tồn tại trong repo** (đã
   `Glob`/`Grep` xác nhận). BE/FE tự nhất quán với nhau nên hệ thống chạy đúng, nhưng thẩm
   quyền nghiệp vụ cho 3 con số đó hiện chỉ là code tự trích dẫn vòng. Cần BA xác định: nếu
   Annex D tồn tại ở nơi khác → đính kèm vào `docs/`; nếu chưa từng viết → chốt chính thức 3
   con số này vào SRS §13.6.

Đã dọn 1 khoản dead code liên quan (không phải fix nghiệp vụ): field `consent_contact`/
`consent_photo` khai trong FE `types/index.ts` nhưng BE không nhận, FE không gửi/render —
xoá khỏi `CreateWarrantyCaseRequest`. Vitest 49/49 vẫn xanh sau khi xoá.

### DEV244-P2-01 — Governance metadata
Không đổi khuyến nghị gốc (PM domain, không phải code). Xem đề xuất comment ở cuối cuộc trò chuyện Claude — không ghi nội dung comment vào file này theo yêu cầu người dùng.
