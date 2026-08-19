# WWHN-382 — Đóng register R4 của dashboard aggregate

Đối tượng: `GET /api/v1/reports/dashboard-quick` (WWHN-88 → WWHN-327 FE / WWHN-328 BE / WWHN-329 QA2).
Điểm vòng trước: **74/100 — chưa đạt**. Hồ sơ này đóng cả 9 mục của register R4.

Machine-readable: [`R4_REGISTER.json`](./R4_REGISTER.json).
Cổng kiểm: `node scripts/verify-wwhn-382-closure.mjs`.

---

## 0. Vì sao ba vòng liên tiếp đóng không được — chẩn đoán trước khi sửa

Không mục nào trong register R2/R3/R4 nói rằng dashboard **chạy sai**. Chín trên chín mục là
truy vết và hợp đồng. Đọc kỹ thì cả ba vòng đều hỏng vì cùng ba cơ chế, không phải vì người làm
cẩu thả:

| Cơ chế hỏng | Biểu hiện trong register | Vì sao nó tự tái phát |
|---|---|---|
| **H1 — candidate là commit gộp** | P1-03R4: "generic bundled commit; no Jira-keyed PR/commit" | Nếp làm việc commit `gỡ cờ N` gom nhiều task. Diff của commit ≠ diff của task, nên reviewer không có cách nào đọc "WWHN-328 đã đổi gì". |
| **H2 — pin theo hash cả tệp** | P1-04R4: "OpenAPI current blob a18b4951…; R3 evidence stale" | `storage/api-docs/api-docs.json` và Postman collection là tệp dùng chung: bất kỳ task nào chạm route/permission đều sinh lại chúng. Hash pin hôm nay chắc chắn sai vào tuần sau, **kể cả khi dashboard không đổi một dòng**. |
| **H3 — pin theo tên nhánh** | P1-02R4: "candidate moved… không dùng moving m1 làm evidence cuối" | `m1` là nhánh chung của nhiều phiên làm việc song song. Một cái tên di động không bao giờ là bằng chứng. |

Bằng chứng H2 có thật, đo được ngay trên repo này:

| Ref | git blob của `storage/api-docs/api-docs.json` | fingerprint operation `dashboard-quick` |
|---|---|---|
| `127c573…` (candidate R4 đầu vòng) | `a18b4951c55843dc5e58fc2ac7d679914a9709a2` | `03445d7afebcfc44f3a83f57f44a1a554f2b4a7b09a7f6f96924d1889f5f1742` |
| `HEAD` sau vài commit của task khác | `245857ba19038e67c9644ecb61282ec806a77192` | `03445d7afebcfc44f3a83f57f44a1a554f2b4a7b09a7f6f96924d1889f5f1742` |

Hash tệp đã đổi, **hợp đồng dashboard thì không**. Vòng R4 chấm "evidence stale" là chấm đúng
hiện tượng nhưng nhắm sai vật: cái cũ là cách đo, không phải cái được đo.

**Ba sửa chữa gốc của vòng này:**

1. **H1** → candidate là commit **mang mã Jira** `WWHN-382`, không còn `gỡ cờ N`; kèm
   [`DIFF_SCOPE_MAP.md`](./DIFF_SCOPE_MAP.md) liệt kê từng tệp thuộc task bên trong lịch sử gộp cũ.
2. **H2** → pin ở **mức đối tượng** (`scripts/wwhn-328-contract-fingerprint.mjs`): operation
   OpenAPI, request Postman, khối mã dashboard, helper `period()`, dòng route. Tệp dùng chung
   được sinh lại bao nhiêu lần cũng không làm rot pin.
3. **H3** → thay "tin vào nhánh" bằng **semantic anchor + kiểm tra bất biến trên dải**:
   `--range <anchor>..HEAD` chứng minh hợp đồng không đổi trên toàn bộ commit từ mốc ngữ nghĩa
   tới hiện tại. `m1` chạy tiếp cũng không phá được bằng chứng; ngược lại, ai sửa ngữ nghĩa
   dashboard thì cổng đỏ ngay.

---

## 1. DEV328-P1-01R4 — KPI matrix có version, một verdict (Domain/G1)

[`../WWHN-328/KPI_MATRIX.md`](../WWHN-328/KPI_MATRIX.md) nâng lên **v1.2 (2026-08-19)**. Đủ sáu trục
mà register đòi: công thức, nguồn, bộ lọc, timezone/cutoff, permission/data scope, expected totals;
và nói thẳng `WAREHOUSE_SCOPED` với ngoại lệ `warranty_cases_by_status` GLOBAL.

Bốn khiếm khuyết của bản v1.1 được sửa ở vòng này — đây là lý do v1.1 không nên được ký:

| # | v1.1 nói | Thực tế | v1.2 |
|---|---|---|---|
| D1 | Implementation gồm `dashboardWarehouseScope()` | Method này **không còn tồn tại**: WWHN-254 đổi tên thành `reportWarehouseScope()` khi mở data-scope ra mọi `/reports/*` | Sửa tên, ghi rõ hành vi không đổi |
| D2 | "`occurred_at` là timestamp không mang timezone" | Đúng, nhưng là khẳng định không kèm bằng chứng — mà nếu sai thì mọi KPI theo kỳ lệch 7 giờ | Thay bằng số đo `information_schema` trên DB tham chiếu: `timestamp without time zone`, `datetime_precision = 0`, kèm hệ quả cho biên `23:59:59` |
| D3 | Nguồn ghi "`stock_movements`" | ERD entity 30 tên là `inventory_movements`; `warranty_case` là **view** trên PostgreSQL | Thêm ánh xạ nguồn ↔ ERD và ghi rõ dashboard đọc **cùng bảng** với RPT-INV-01 nên không thể lệch nguồn |
| D4 | Mỗi dòng KPI chỉ là văn bản | Không có cách nào để một dòng matrix "đỏ" được | Thêm cột **Test khoá**: mỗi dòng KPI ánh xạ vào một test chạy được |

**Ngoại lệ warranty GLOBAL** trước vòng này chỉ tồn tại dưới dạng chữ (OpenAPI + matrix + một khối
`role="note"` trên UI). Nay có test khoá:
`Wwhn328R4ClosureTest::test_warranty_kpi_stays_global_for_a_warehouse_scoped_user` —
so hai payload GLOBAL và WAREHOUSE_SCOPED, bắt buộc `receipt_qty` **khác nhau** (chứng minh
data-scope thật sự đang chạy) và `warranty_cases_by_status` **bằng nhau**.

## 2. DEV328-P1-02R4 — Supersede và pin canonical (Traceability)

Xem [`SOURCE_SUPERSESSION.md`](./SOURCE_SUPERSESSION.md) và [`CANDIDATE_PIN.json`](./CANDIDATE_PIN.json).
Bảy nguồn lịch sử được khai kèm **SHA đủ 40 ký tự** và lý do bị thay, trong đó có `m1-be-2`
(`refs/heads/m1-be-2` @ `60816499066f9f493596e326dc41116ef32f5c4b`).

Về "không dùng moving m1 làm evidence cuối": pin không dựa vào tên nhánh. `CANDIDATE_PIN.json`
ghi `semantic_anchor` — commit cuối cùng chạm **ngữ nghĩa** dashboard — và câu lệnh chứng minh
bất biến trên dải `anchor..HEAD`. Nhánh chạy tiếp không làm hồ sơ hết hạn; chỉ có thay đổi ngữ
nghĩa thật mới làm cổng đỏ.

## 3. DEV328-P1-03R4 — Phạm vi diff và chạy được tại exact SHA (Done/Build)

Xem [`DIFF_SCOPE_MAP.md`](./DIFF_SCOPE_MAP.md).

- Candidate vòng này là commit **mang mã Jira `WWHN-382`**, chấm dứt việc lấy `gỡ cờ N` làm
  candidate.
- Với phần lịch sử đã lỡ nằm trong commit gộp (`6db0965`, `127c573`), map liệt kê **từng tệp
  thuộc task** kèm blob và commit chạm cuối, để reviewer đọc được đúng phần WWHN-328 mà không
  phải lọc thủ công diff của cả commit gộp.
- Disposition direct-commit: repo này không dùng PR cho nhánh `m1`; thay cho review PR là cổng
  máy chạy được `.github/workflows/wwhn-382-dashboard-closure.yml` — verifier + test khoá trên
  **cả hai driver** tại đúng SHA, và `--expect-sha` chặn cổng chạy lệch commit.

Kết quả chạy tại candidate (log thô kèm trong thư mục này):

| Driver | Lệnh | Kết quả |
|---|---|---|
| SQLite (mặc định phpunit.xml) | `php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn328R4ClosureTest.php` | 5 test, 5 passed, 25 assertions |
| PostgreSQL 16 (`hn_wwhn382`, DB tạo mới) | `DB_CONNECTION=pgsql … php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn328R4ClosureTest.php` | 5 test, 5 passed, 25 assertions |
| SQLite | `Wwhn328R4ClosureTest` + `Wwhn328DashboardQuickContractTest` (hợp nhất R3+R4) | **22 test, 22 passed, 86 assertions** |
| PostgreSQL 16 (DB tạo mới) | `Wwhn328R4ClosureTest` + `Wwhn328DashboardQuickContractTest` (hợp nhất R3+R4) | **22 test, 22 passed, 86 assertions** |
| Style | `./vendor/bin/pint --test tests/Feature/Wwhn328R4ClosureTest.php` | passed |

**Một khiếm khuyết fixture chỉ lộ trên PostgreSQL đã được sửa trong vòng này.** Lượt chạy đầu
tiên trên PG đỏ ở `test_warranty_kpi_stays_global_for_a_warehouse_scoped_user`:
`SQLSTATE[23514] chk_erd_warranty_identification_shape`. Nguyên nhân: fixture ghi
`identification_status = 'IDENTIFIED'` mà không có `item_id`, và dùng `status = 'CLOSED'` vốn
không nằm trong vocabulary `chk_erd_warranty_cases_2`. Hai CHECK này **chỉ tồn tại trên
PostgreSQL**, nên SQLite xanh giả. Đã sửa sang nhánh `TEMP_ONLY` hợp lệ và `status = 'COMPLETED'`.
Đây đúng loại đỏ mà CI chạy SQLite không bắt được — cùng họ với đỏ `uk_warehouses_single_active`
đã xử lý ở vòng R3.

## 4. DEV328-P1-04R4 — Contract pin và phê duyệt (Contract)

[`CONTRACT_PIN.json`](./CONTRACT_PIN.json) do `scripts/wwhn-328-contract-fingerprint.mjs` sinh, gồm
năm fingerprint: operation OpenAPI, request Postman, khối mã dashboard, helper `period()`, dòng
route/permission. Hash cả tệp vẫn được ghi lại nhưng nằm dưới khoá
`whole_file_informational` và **không** dùng làm điều kiện đạt — vì chính chúng là nguồn của
"evidence stale".

Nội dung Tech cần duyệt là **một** hợp đồng, đã gom đủ ở matrix v1.2: data scope (§1),
timezone/cutoff (§5), `reconciliation_warning_count` vs mẫu 50 dòng (§2 K9/K10), ngoại lệ
warranty (§1), auth/error 401/403/422 (§4).

Chống lệch OpenAPI ↔ runtime bằng test, không bằng lời hứa:
`test_openapi_operation_declares_exactly_the_runtime_field_set` so **đúng từng khoá** giữa
`data` của response thật và `properties` của schema trong `api-docs.json`.

## 5. DEV328-P1-05R4 — Dependency index WWHN-327 ↔ WWHN-328 (G2)

Native issue link trên Jira là thao tác của PM/BAS. Phần kỹ thuật đã dựng **evidence index hai
chiều** để dependency không phụ thuộc vào việc ai đó nhớ bấm nút:

| Chiều | Tệp | Nội dung |
|---|---|---|
| BE → FE | `docs/evidence/WWHN-382/CANDIDATE_PIN.json` | `candidate.frontend` = repo + branch + FE SHA đủ 40 ký tự |
| FE → BE | `HoaNam_FE/.../docs/evidence/WWHN-382/FE_CONSUMER_PIN.json` | BE SHA + fingerprint operation + field set FE tiêu thụ |

Output WWHN-327 được tiêu thụ pin ở `99dff42fb4272ed8930e8fb9bf4da6368f4cfd96` (bản đưa ngoại lệ
warranty GLOBAL lên UI và thêm gate closure FE). Nó được ghi là **nguồn đầu vào đã hợp nhất**,
không phải candidate cuối — xem `SOURCE_SUPERSESSION.md` §2.

## 6. DEV328-P1-06R4 — Chạy lại độc lập của QA2 (QA hard gate)

Xem [`QA2_RERUN_RUNBOOK.md`](./QA2_RERUN_RUNBOOK.md).

DEV **cố ý không** sinh kết quả thay QA2: một hard gate độc lập mà bị người làm code tự tick thì
mục P1-07 sẽ trượt tiếp ở vòng sau. Runbook cung cấp lệnh chạy được cho từng hạng mục register
(totals, scope, cache isolation, timezone/boundaries, 401/403/422, reconciliation, warranty, tích
hợp FE), kèm ánh xạ hạng mục → test khoá để QA2 đối chiếu thay vì suy diễn.

## 7. DEV328-P1-07R4 — BA và QA2 chấp nhận cùng một cặp (Parent Ready)

Cặp candidate và contract version được phát hành ở **một chỗ duy nhất** — `CANDIDATE_PIN.json`
và `CONTRACT_PIN.json` — nên hai vai không thể vô tình ký trên hai bản khác nhau, vốn là cách
mục này trượt ở vòng trước.

## 8. DEV328-P2-01R4 — Narrative và historical trace (Governance)

`SOURCE_SUPERSESSION.md` §3 tách rõ "nguồn hiện hành" khỏi "historical trace" và giải thích vì
sao `m1-be-2` / luồng BE2 bị **hạ cấp** chứ không bị xoá khỏi hồ sơ: xoá đi là mất truy vết của
chính những kết quả test cũ đang được trích dẫn trong các comment trước.

## 9. DEV328-P2-02R4 — Sprint vs Sprint Scope (Governance)

Đề xuất chốt để PM/Jira owner ra quyết định: **`Sprint` là trường authoritative**, `Sprint Scope`
là nhãn mô tả không dùng để lập kế hoạch. Lý do: `Sprint` là trường hệ thống của Jira, tham gia
báo cáo burndown/velocity và không sửa được tuỳ tiện; `Sprint Scope` là custom field văn bản.
Khi hai trường lệch (W3 vs W4), lấy trường không tham gia báo cáo làm chuẩn sẽ làm sai mọi số
liệu sprint phía sau.

---

## 10. Điều KHÔNG thay đổi trong vòng này

Hành vi runtime của `dashboard-quick` **không đổi** so với candidate R4 đầu vòng: fingerprint
`openapi_operation`, `postman_requests`, `controller_period_helper`, `route_permission_line` giữ
nguyên; `controller_dashboard_block` chỉ đổi do WWHN-254 đổi tên `dashboardWarehouseScope` →
`reportWarehouseScope` (đã ghi ở matrix v1.2 §0 C1). Vòng R4 là vòng đóng **truy vết + hợp đồng
+ độ phủ test**, không phải vòng sửa nghiệp vụ. Nói rõ ở đây để QA2 không phải retest lại các
kịch bản đã xanh ở R3 mà chỉ tập trung vào phần mới.
