# QA independent verification — `WWHN-235` (gate của `WWHN-233`)

> Đóng `DEV233-P1-06R2`. Vòng R1 chỉ mô tả *cách chạy* negative gate; vòng này là **kết quả
> chạy thật** trên đúng candidate đã pin, do QA1 tự chạy và tự kết luận.

| Field | Value |
|---|---|
| QA gate | `WWHN-235` |
| Task | `WWHN-233` (child của `WWHN-59`) |
| Disposition dưới kiểm | `DISP-STK-233` v1.1.0 — **DEFERRED / NO CODE** |
| Controlling source | `REQ-SRS-037` (Must) — `HN_SRS_FRD_v1.12_FULL.md`, adopt tại `WWHN-334` |
| Người chạy & kết luận | **QA1** (độc lập với DEV) |
| Ngày chạy | `2026-08-19` |
| Loại kiểm | **NEGATIVE GATE** — chứng minh capability *không* tồn tại ở mọi tầng |
| Candidate SHA | `IMMUTABLE_CANDIDATE_PIN.json` → `candidate.be_commit_sha` = `__BE_CANDIDATE_SHA__` |
| Candidate fingerprint (mức đối tượng) | `__BE_FINGERPRINT__` |
| FE candidate SHA | `__FE_CANDIDATE_SHA__` (fingerprint `__FE_FINGERPRINT__`) |
| Raw evidence | `docs/evidence/WWHN-233/raw/` — 6 file, liệt kê ở §2 |

---

## 1. Environment

Ghi nguyên trạng ở `docs/evidence/WWHN-233/raw/00-environment.txt`.

| Thành phần | Giá trị |
|---|---|
| OS | Windows 11 (host) |
| PHP | 8.3.30 (cli) |
| Laravel | 13.23.0 |
| Node | v22.17.1 |
| Vitest | 4.1.10 |
| DB — probe trực tiếp | PostgreSQL **16-alpine**, database `wms_hoanam`, container `wms_warehouse_hoa_nam_be-postgres-1` |
| DB — chạy test | PostgreSQL 16 qua container `wms_warehouse_hoa_nam_be-backend-1` (`docker exec -w /var/www/html`) |
| Branch | `m1` (cả hai repo) |

**Vì sao không chạy test trên sqlite mặc định của `phpunit.xml`.** `phpunit.xml` khai
`DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:`. Bộ test này **không** dùng `RefreshDatabase`,
nên trên sqlite in-memory không có migration nào chạy và `Schema::hasTable('stocktake_*')` trả
`false` **một cách hiển nhiên** — assertion schema trở nên *vacuous*, tức là PASS mà không chứng
minh gì. QA chạy lại trên PostgreSQL đã migrate đầy đủ để bốn assertion schema có nghĩa thật.

Phần `<env>` của PHPUnit **không** có `force="true"` nên nó không đè biến môi trường đã có.
Bằng chứng cơ chế nằm ở phụ lục `raw/03-gate-test-pgsql.txt` §3b: chạy trên host với
`DB_CONNECTION=pgsql` nhưng thiếu mật khẩu thì test **báo lỗi kết nối pgsql** thay vì âm thầm
chạy sqlite — chứng tỏ biến môi trường mới là cái quyết định driver.

---

## 2. Kết quả — ma trận kiểm

| # | `expected_check` | Kỳ vọng khi DEFERRED | Cách QA kiểm | Raw evidence | Kết quả |
|---|---|---|---|---|---|
| 1 | `ROUTE-SURFACE` | 0/239 route chứa 6 mảnh bị cấm | `php artisan route:list --json` rồi lọc | `docs/evidence/WWHN-233/raw/01-route-surface-probe.txt` | **PASS** — `TOTAL_ROUTES=239`, `MATCHING_FORBIDDEN_FRAGMENT=0` |
| 2 | `RUNTIME-REACHABILITY` | 7 URL count/recount/apply-adjustment đều `404` | gọi thật qua middleware stack | `docs/evidence/WWHN-233/raw/03-gate-test-pgsql.txt` | **PASS** — `test_count_and_apply_adjustment_urls_are_unreachable_at_runtime` |
| 3 | `OPENAPI` | 0/84 path runtime thuộc bề mặt bị cấm | quét `storage/api-docs/api-docs.yaml` | `docs/evidence/WWHN-233/raw/04-openapi-permission-probe.txt` | **PASS** — `TOTAL_PATHS=84`, `FORBIDDEN_PATHS=0` |
| 4 | `PERMISSION` | 0/31 permission kiểm kê | baseline contract + middleware `permission:` của route thật | `docs/evidence/WWHN-233/raw/04-openapi-permission-probe.txt` | **PASS** — `FORBIDDEN_PERMISSIONS=0` |
| 5 | `SCHEMA-END-STATE` | 4 bảng `stocktake_*` vắng mặt; `stock_movements` không giữ FK variance | truy vấn `information_schema` trên PostgreSQL thật | `docs/evidence/WWHN-233/raw/02-pg-stock-effect-probe.txt` §A, §B | **PASS** — `(0 rows)` cả hai |
| 6 | `STOCK-EFFECT` | Không tồn tại đường ghi tồn thứ hai | ghi thẳng vào `stock_movements` với 4 movement type ngoài canonical | `docs/evidence/WWHN-233/raw/02-pg-stock-effect-probe.txt` §E | **PASS** — xem §3 |
| 7 | `BASELINE-CONTRACT` | `forbidden_path_fragments` phủ đủ 6 mảnh; `movement_types` đúng 5 giá trị | đọc contract | `docs/evidence/WWHN-233/raw/04-openapi-permission-probe.txt` | **PASS** |
| 8 | `DORMANT-SOURCE` | 0 class/route kiểm kê; tập file còn nhắc bề mặt trùng khớp đăng ký | verifier quét 478 file PHP trên cây làm việc | `docs/evidence/WWHN-233/raw/06-be-verifier.txt` | **PASS** — 10 file, tất cả đã có disposition |
| 9 | `FE-SURFACE` | 0 route / menu / permission kiểm kê ở FE | verifier FE + 10 vitest | `docs/evidence/WWHN-233/raw/05-fe-gate.txt` | **PASS** — 12 check PASS, 10/10 test |

Bộ gate BE: `docs/evidence/WWHN-233/raw/03-gate-test-pgsql.txt` — **13/13 test PASS, 359 assertion**.
Verifier BE: `docs/evidence/WWHN-233/raw/06-be-verifier.txt`.

---

## 3. Bằng chứng mạnh nhất — thử ghi tồn trực tiếp trên PostgreSQL thật

Đây là phần QA quan tâm nhất, vì "không có endpoint" là điều kiện *cần* chứ chưa *đủ*: câu hỏi
thật sự là **có tồn tại đường ghi tồn thứ hai hay không**.

Thiết kế probe (`raw/02-pg-stock-effect-probe.txt` §E) có **control**:

- `E1` — payload y hệt, `movement_type = 'REVERSAL'` (canonical) ⇒ `INSERT 0 1`. Chứng minh
  hình dạng bản ghi hợp lệ, không có FK/NOT NULL nào chắn đường.
- `E2..E5` — **biến duy nhất thay đổi là `movement_type`**: `ADJUST_IN`, `ADJUST_OUT`,
  `STOCKTAKE`, `COUNT` ⇒ cả bốn đều bị

  ```
  ERROR: new row for relation "stock_movements" violates check constraint "chk_stock_movement_type_current"
  ```

Vì E1 thành công còn E2–E5 bị chặn, nguyên nhân bị cô lập chính xác vào `movement_type`, không
phải vào lỗi phụ nào khác. Chặn nằm ở **tầng DB**, không phụ thuộc code ứng dụng.

Hai chi tiết về cách chạy, ghi lại để lần sau khỏi mất công:

1. Mỗi bước bọc `SAVEPOINT` + `ROLLBACK TO SAVEPOINT`. PostgreSQL abort toàn bộ transaction sau
   lỗi đầu tiên (`25P02`), nên nếu không bọc thì E3–E5 chỉ trả *"current transaction is aborted"*
   — trông giống bị chặn nhưng thực chất **chưa được kiểm**.
2. Toàn khối nằm trong `BEGIN … ROLLBACK`; §F xác nhận sau đó `SELECT … LIKE 'PROBE-233-%'` trả
   `(0 rows)` — probe không để lại dữ liệu trong DB dùng chung.

`chk_stock_movement_type_current` đọc được nguyên văn ở §C:
`OPENING, RECEIPT, ISSUE, COMPONENT_ISSUE, REVERSAL`. Trigger `trg_stock_movements_append_only`
còn nguyên (§D).

---

## 4. Ba điều QA ghi nhận kèm kết luận

**4.1 — Bộ test trên sqlite là vacuous cho nhóm assertion schema.** Không phải lỗi của
`WWHN-233`, nhưng nếu chỉ chạy lệnh mặc định thì bốn assertion `stocktake_*` PASS mà không chứng
minh gì. QA kết luận trên bản chạy PostgreSQL; ai chạy lại phải dùng đúng lệnh ở §6.

**4.2 — `verify-wwhn236-adjustment-disposition.mjs` đang FAIL 4 mục.** Cả 4 thuộc **register
riêng của `WWHN-236`** ở vòng R3 (`APPROVAL-RECORD`, `CANDIDATE-PIN`, `PARENT-REBASELINE`,
`QA-RERUN`) — không mục nào chạm bề mặt kiểm kê. Toàn bộ 12 check *bề mặt* của `DISP-STK-236`
vẫn PASS. Verifier của `WWHN-233` phân tách đúng hai loại: fail cứng nếu bề mặt hỏng, cảnh báo
nếu chỉ là governance của nhánh kia. QA đồng ý cách phân tách này; **không** coi đây là defect
của `WWHN-233` và **không** yêu cầu `WWHN-233` sửa artifact của nhánh khác.

**4.3 — `docs/postman/WWHN-180 Canonical Contract.postman_collection.json` còn khai
`/api/v1/stocktake/sessions/{{stocktake_id}}/apply-adjustment`.** Đây là **bản khai trong pack
immutable**, không phải route runtime — kiểm #1 và #2 chứng minh runtime có 0 route tương ứng và
URL trả `404`. Pack thuộc `WWHN-180`/`WWHN-237`; sửa nội dung một immutable pack là sai quy trình
(nó chỉ được superseded bằng decision record trỏ tới nó). QA ghi nhận là **known red thuộc nhánh
`WWHN-237`**, đã có disposition tại `docs/evidence/WWHN-233/DORMANT_SOURCE_DISPOSITION.json`.

---

## 5. VERDICT

> **VERDICT: PASS — `WWHN-235` = DEFERRED / N/A cho MVP v1.12.**

Cụ thể: tại đúng candidate đã pin, QA xác nhận **không** tồn tại route, endpoint OpenAPI,
permission, bảng, movement type, màn hình hay đường ghi tồn nào thuộc bề mặt count / recount /
partial / stale / variance / adjustment; và bốn URL apply-adjustment/count được gọi thật đều
không với tới được.

`WWHN-235` **không** phải `Blocked` và **không** phải `Pending`: không có vướng mắc nào để gỡ,
vì capability mà nó định test đã bị controlling source loại khỏi phạm vi. Điều kiện re-open dùng
chung với `WWHN-233` (`DISP-STK-233` §2).

**QA không thực hiện** — và cố ý không: test duplicate/partial/stale/RBAC trên exact build cho
một capability không tồn tại. Khi re-enable đủ 4 điều kiện, `WWHN-235` quay lại đúng nguyên văn
register, và bộ negative gate này trở thành regression suite của lần re-enable đó.

---

## 6. Chạy lại (bất kỳ ai cũng tái lập được)

```bash
node scripts/verify-wwhn233-count-disposition.mjs --expect-sha=__BE_CANDIDATE_SHA__
docker exec -w /var/www/html -e DB_CONNECTION=pgsql wms_warehouse_hoa_nam_be-backend-1 \
  php artisan test tests/Feature/Wwhn233CountDeferredGateTest.php
docker exec -i wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -d wms_hoanam \
  -v ON_ERROR_STOP=0 < docs/evidence/WWHN-233/raw/02-pg-stock-effect-probe.sql
```

Repo FE:

```bash
npm run wwhn-233:verify
npx vitest run src/test/wwhn-233-count-deferral-gate.test.ts
```

Nếu bất kỳ file nguồn nào của `WWHN-233` bị sửa mà pin chưa phát hành lại, `--expect-sha` và
check `CANDIDATE-PIN` sẽ đỏ — đó là hành vi đúng, không phải nhiễu.

---

## 7. Bản ghi Jira đề xuất cho `WWHN-235`

```text
Disposition: DEFERRED / N/A cho MVP v1.12 (khong phai Blocked, khong phai Pending)
Can cu: REQ-SRS-037 (Must, SRS/FRD v1.12 @ WWHN-334) + DISP-STK-233 v1.1.0 §8
QA result: PASS (negative gate) - QA1 tu chay, khong dua tren ket luan cua DEV
Candidate: BE __BE_CANDIDATE_SHA__ / FE __FE_CANDIDATE_SHA__
Env: PHP 8.3.30, Laravel 13.23.0, PostgreSQL 16, Node 22.17.1
Evidence: docs/evidence/WWHN-233/QA_INDEPENDENT_VERIFICATION_WWHN-235.md + raw/ (6 file)
Dieu kien re-open: dung chung voi WWHN-233 (DISP-STK-233 §2)
```
