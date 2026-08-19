# WWHN-328 — Đóng register R4 (74/100 → hồ sơ đóng)

Vòng R4 của `P0/P1/P2 EXACT FIX ACTION REGISTER`. Bảy mục P1 và hai mục P2. Không mục nào
đòi đổi hành vi runtime: hành vi đã đóng ở R3 (data-scope + cache key + trần cảnh báo). R4
đòi **bằng chứng đứng vững** — pin đúng tầng, khoanh đúng diff, chốt một verdict.

## 1. Nguyên nhân gốc của việc mở đi mở lại

Ba vòng trước đều rơi vào cùng một nhóm: *evidence stale / candidate moved / generic commit*.
Nguyên nhân không phải người pin ẩu mà là **pin sai tầng**:

- Pin theo **hash cả tệp** (`storage/api-docs/api-docs.json`, Postman collection,
  `OperationalReportController.php`). Cả ba là tệp **dùng chung**: mỗi task khác chạm
  route/permission là chúng được sinh lại ⇒ hash đổi ⇒ bằng chứng dashboard "hết hạn" dù
  dashboard không hề đổi. Blob OpenAPI trong register (`a18b4951…`) hết hạn đúng theo cơ chế đó.
- Pin theo **HEAD của `m1`**, mà `m1` là nhánh chung nhiều người/nhiều phiên đẩy song song ⇒
  bằng chứng "cuối" hết hạn ngay hôm sau.

Cách sửa tận gốc, dùng cho toàn bộ R4: **pin ở mức đối tượng + chứng minh bất biến trên dải
commit**, thay vì pin một ảnh chụp tệp.

- `scripts/wwhn-328-contract-fingerprint.mjs` băm riêng: OpenAPI operation của
  `/api/v1/reports/dashboard-quick`, các request Postman trỏ endpoint đó, khối mã
  `// ── Dashboard Quick` + helper `period()` (đã bỏ chú thích, gộp khoảng trắng), và đúng một
  dòng khai route/permission.
- `--range <candidate>..HEAD` biến "m1 vẫn chạy" từ lý do bác bằng chứng thành **một khẳng
  định kiểm chứng được**: contract không đổi trên toàn dải.

## 2. Bảng đóng theo từng ID

| ID | Trạng thái | Đóng bằng |
|---|---|---|
| DEV328-P1-01R4 | **CLOSED (DEV)** — chờ chữ ký BA | KPI matrix **v1.2** (`KPI_MATRIX.md`): công thức K0–K10, nguồn authoritative + ánh xạ ERD, filter, timezone/cutoff đo trên DB tham chiếu (§5), permission/data-scope (§1), expected totals (§3), bất biến đối soát (§7). Mỗi dòng có **cột "Test khoá"**. §9 quy ước comment ký một-verdict. |
| DEV328-P1-02R4 | **CLOSED** | `m1-be-2` và `b8cfcf0`/`6db0965`/`127c573` khai superseded ở `EVIDENCE_INDEX.md §2`, lý do kỹ thuật ở `DIFF_MAP.md §3`. Candidate mới: repo `WMS_Warehouse_Hoa_Nam_BE`, branch `m1`, SHA đầy đủ `b610b1ff191f8c1f2cce3c24571de850f946d690`. Không dùng HEAD đang chạy: xem §3. |
| DEV328-P1-03R4 | **CLOSED** | `DIFF_MAP.md` — ranh giới file thuộc WWHN-328, numstat từng commit bundled (chạy lại được), disposition *approved direct-commit + post-hoc reviewable slice*, và lệnh chạy test đúng phạm vi. Gate xanh tại exact SHA: §4. |
| DEV328-P1-04R4 | **CLOSED** | `CONTRACT_PIN.json` sinh bởi fingerprint script: pin OpenAPI operation, Postman request, khối controller, dòng route — kèm hash cả tệp ở mục `whole_file_informational` (tham chiếu lịch sử, **không** dùng làm điều kiện đạt). Scope/timezone/warning count+sample/ngoại lệ warranty/auth-error đều nằm trong operation được pin. |
| DEV328-P1-05R4 | **CLOSED (DEV/PM-ready)** | `EVIDENCE_INDEX.md` — cụm 88/327/328/329, map `HN-STR-002-ST01`, bảng "WWHN-327 tiêu thụ đúng cái gì", pin FE `16a3eea9f5241ba3ca24e36c53c811a45f9ae45d`. Native issue link vẫn do PM/BAS tạo trên Jira. |
| DEV328-P1-06R4 | **READY FOR QA2** | `QA2_RERUN_PROTOCOL.md` — cặp SHA, dữ liệu/tài khoản tối thiểu, 12 hạng mục T1–T12 phủ đúng: totals, scope, cache isolation, timezone/boundaries, 401/403/422, reconciliation, warranty, FE integration; hình thức báo cáo trên WWHN-329. Đây là **việc của QA2**, hồ sơ chỉ bỏ hết lý do không chạy được. |
| DEV328-P1-07R4 | **READY** | Cặp đóng băng và version contract đã cố định (§3); BA và QA2 ký độc lập trên cùng cặp đó. |
| DEV328-P2-01R4 | **READY (PM/BAS)** | Narrative thống nhất về DEV/Quang; BE2/Duy/`m1-be-2` giữ ở mục historical của `EVIDENCE_INDEX.md §2`. Thao tác sửa metadata nằm trên Jira. |
| DEV328-P2-02R4 | **READY (PM/Jira owner)** | Trường `Sprint` (Sprint 93 W3) là authoritative; `Sprint Scope` (W4) là trường phái sinh. Thao tác trên Jira. |

## 3. Cặp đóng băng và cách nó sống sót khi `m1` chạy tiếp

| Thành phần | Repo | Branch | Exact SHA |
|---|---|---|---|
| Backend candidate | `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `b610b1ff191f8c1f2cce3c24571de850f946d690` |
| Backend contract origin | `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `c855851b37920237d110f7dffe5dbbad509aebce` |
| Frontend candidate | `WMS_Warehouse_Hoa_Nam_FE` | `m1` | `16a3eea9f5241ba3ca24e36c53c811a45f9ae45d` |
| Contract version | — | — | KPI matrix **v1.2** |

`m1` có nhiều phiên đẩy song song, nên bằng chứng **không** neo vào HEAD. Nó neo vào candidate,
và tính hợp lệ được duy trì bằng bất biến:

```bash
node scripts/wwhn-328-contract-fingerprint.mjs \
  --check docs/evidence/WWHN-328/CONTRACT_PIN.json --range b610b1ff191f8c1f2cce3c24571de850f946d690..HEAD
```

Lệnh này **đỏ ngay** khi bất kỳ commit nào sau candidate đổi ngữ nghĩa dashboard, và **xanh**
khi các commit đó chỉ chạm phần khác của tệp dùng chung. Nhờ vậy câu hỏi "candidate có còn
đúng không" trả lời được bằng máy, không bằng niềm tin.

Đo tại lượt đóng: contract hình thành ở `c855851`, giữ nguyên **58 commit liên tiếp** tới HEAD
(`node scripts/wwhn-328-contract-fingerprint.mjs --origin`) — kể cả `a16d1d6` (WWHN-231) có
sửa cùng controller.

## 4. Chạy thật tại candidate

| Lệnh | Kết quả |
|---|---|
| `php vendor/bin/phpunit --filter Wwhn328DashboardQuickContractTest` | **17 passed**, 61 assertions |
| `php vendor/bin/phpunit --filter Wwhn328R4ClosureTest` | **5 passed**, 25 assertions |
| `node scripts/wwhn-328-contract-fingerprint.mjs --check docs/evidence/WWHN-328/CONTRACT_PIN.json --ref <candidate>` | PASS |
| `node scripts/verify-wwhn-328-closure.mjs` | PASS |

Năm case mới ở `Wwhn328R4ClosureTest` khoá đúng những khẳng định mà R4 đòi nhưng trước đây chỉ
tồn tại dưới dạng văn bản:

| Case | Khoá điều gì | Vì sao cần |
|---|---|---|
| `test_warranty_kpi_stays_global_for_a_warehouse_scoped_user` | ngoại lệ warranty GLOBAL | trước đó chỉ có trong OpenAPI/matrix; một lần "sửa cho nhất quán" sẽ đi lọt |
| `test_reconciliation_warning_count_matches_the_inventory_balance_report` | bất biến I1–I3 với RPT-INV-01 | dashboard và màn Tra cứu tồn có thể lệch định nghĩa MISMATCH mà cả hai vẫn xanh |
| `test_warning_count_is_the_total_while_the_embedded_sample_is_capped_at_fifty` | 55 lệch ⇒ `count=55`, mảng = 50 | trần bị gỡ, hoặc `count` bị nối lại vào `length`, sẽ không ai biết |
| `test_openapi_operation_declares_exactly_the_runtime_field_set` | OpenAPI == field set runtime | contract công bố cho FE/QA không được là bản mô tả song song |
| `test_dashboard_aggregate_is_served_by_exactly_one_reused_route` | đúng 1 route aggregate | AC "tái sử dụng, không tạo route mới" |

## 5. Ranh giới sự thật

- Bằng chứng ở đây là **kiểm thử tự động ở tầng contract + integration của backend**. Không
  phải UAT, không thay QA2 độc lập trên WWHN-329.
- Test chạy trên driver `sqlite` theo `phpunit.xml`. Fixture của `Wwhn328R4ClosureTest` được
  viết để hợp lệ cả với ràng buộc **chỉ có trên PostgreSQL** (`uk_warehouses_single_active`,
  `chk_erd_warranty_identification_shape`, `chk_erd_warranty_cases_2`) — nêu rõ vì CI SQLite
  không bắt được nhóm đỏ này.
- DEV không ký thay BA, Tech Lead, QA2 hay PM. Các mục `READY` ở §2 đóng khi đúng vai trò
  đăng comment.
