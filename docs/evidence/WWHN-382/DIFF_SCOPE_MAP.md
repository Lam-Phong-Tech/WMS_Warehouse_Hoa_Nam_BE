# WWHN-382 — Diff scope map trong lịch sử bundled (DEV328-P1-03R4)

Register R4 chấm: *"generic bundled commit; no Jira-keyed PR/commit; no WWHN-328 green gate"*.
Tài liệu này đóng cả ba vế.

Danh sách blob máy đọc được: [`CANDIDATE_PIN.json`](./CANDIDATE_PIN.json) → khoá `scope_files`
(mỗi mục có `path`, `git_blob`, `last_touched.commit/date/subject`).

## 1. Vì sao "diff của commit" không phải "diff của task"

Nếp làm việc trên `m1` gom nhiều task vào một commit tên `gỡ cờ N`. Ví dụ đo được:

| Commit | Tên | Số tệp đổi | Trong đó thuộc dashboard |
|---|---|---|---|
| `6db0965940a6ae8b22a591d50705f258326b172d` | `gỡ cờ 12` | hàng chục (kèm WWHN-330/331) | 5 |
| `127c573a7236ad1c25829eb92011a4914360b840` | `gỡ cờ 13` | hàng chục (kèm WWHN-299/309/310) | 1 |

Reviewer mở diff của commit sẽ thấy phần lớn nội dung **không** thuộc WWHN-328. Đó là lý do mục
này bị mở lại ba vòng liền, không phải vì thiếu thiện chí.

## 2. Sửa gốc: candidate vòng này là commit mang mã Jira

Candidate của WWHN-382 là commit có tiêu đề bắt đầu bằng `test(WWHN-382)` / `docs(WWHN-382)` —
chấm dứt việc lấy `gỡ cờ N` làm candidate. Từ vòng này trở đi, phạm vi task đọc được trực tiếp
từ `git show <candidate>` mà không cần lọc tay.

Phần lịch sử đã lỡ nằm trong commit gộp vẫn được truy vết bằng bảng §3.

## 3. Phạm vi tệp thuộc WWHN-328 / WWHN-382

| Tệp | Vai trò trong register |
|---|---|
| `Modules/Warehouse/app/Http/Controllers/OperationalReportController.php` | `dashboardQuick()` + `reportWarehouseScope()` + `buildDashboardQuick()` + `period()` — nơi data-scope, cache key, công thức KPI và chuẩn hoá kỳ được thực thi. Tệp **dùng chung** với các báo cáo khác nên bị task khác chạm thường xuyên; xem §4. |
| `Modules/Warehouse/routes/api.php` | Đúng một dòng route + `permission:report.view`. Bằng chứng cho AC "tái sử dụng, không tạo route mới". |
| `tests/Feature/Wwhn328DashboardQuickContractTest.php` | 17 test contract từ vòng R3 (data-scope, cache isolation, biên kỳ, error contract). |
| `tests/Feature/Wwhn328R4ClosureTest.php` | 5 test mới của vòng R4: ngoại lệ warranty GLOBAL, bất biến đối soát, trần mẫu 50, OpenAPI == runtime, một route aggregate duy nhất. |
| `storage/api-docs/api-docs.json` / `.yaml` | Hợp đồng công bố. Là tệp **sinh ra**, dùng chung toàn hệ thống — pin bằng fingerprint operation, không bằng hash tệp (xem §4). |
| `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | Bộ sưu tập cho QA. Cũng là tệp dùng chung. |
| `docs/evidence/WWHN-328/KPI_MATRIX.md` | Matrix v1.2 — vật để BA ký. |
| `docs/evidence/WWHN-328/EVIDENCE_PACK.md` | Hồ sơ vòng R3, giữ để truy vết. |
| `docs/evidence/WWHN-382/*` | Hồ sơ đóng vòng R4 (tài liệu này, register, pin, supersession, runbook). |
| `docs/docs_task_jira/WWHN-382.md` | Task doc. |
| `scripts/wwhn-328-contract-fingerprint.mjs` | Sinh và kiểm fingerprint hợp đồng. |
| `scripts/wwhn-382-pin-candidate.mjs` | Sinh `CANDIDATE_PIN.json` + `CONTRACT_PIN.json`. |
| `scripts/verify-wwhn-382-closure.mjs` | Cổng đóng register, fail-closed. |
| `.github/workflows/wwhn-382-dashboard-closure.yml` | Chạy cổng + test khoá tại exact SHA, hai driver. |

## 4. Tệp dùng chung: vì sao pin phải ở mức đối tượng

Ba tệp trong bảng trên là tệp dùng chung của toàn hệ thống. Đo thật trên repo:

| Ref | blob `storage/api-docs/api-docs.json` | fingerprint operation `dashboard-quick` |
|---|---|---|
| `127c573…` | `a18b4951c55843dc5e58fc2ac7d679914a9709a2` | `03445d7afebcfc44f3a83f57f44a1a554f2b4a7b09a7f6f96924d1889f5f1742` |
| `HEAD` (sau vài commit của task khác) | `245857ba19038e67c9644ecb61282ec806a77192` | `03445d7afebcfc44f3a83f57f44a1a554f2b4a7b09a7f6f96924d1889f5f1742` |

Kết luận đọc được ngay từ bảng: **hash tệp rot, hợp đồng không rot**. Vì vậy hồ sơ này pin
`fingerprints` (operation OpenAPI, request Postman, khối mã dashboard, helper `period()`, dòng
route) và chỉ ghi hash cả tệp dưới khoá `whole_file_informational` để đối chiếu lịch sử.

Với `OperationalReportController.php` cũng vậy: giữa `127c573…` và `HEAD` tệp đổi +389/−64 dòng
vì WWHN-231/WWHN-240/WWHN-254, nhưng khối mã dashboard chỉ đổi **một** thứ — tên
`dashboardWarehouseScope` → `reportWarehouseScope`. Fingerprint bắt đúng thay đổi đó và bỏ qua
phần còn lại, nên nó vừa đủ nhạy vừa không báo động giả.

## 5. Disposition cho direct-commit (thay cho review PR)

Nhánh `m1` của dự án không dùng PR — nhiều phiên làm việc commit trực tiếp. Vì vậy thay cho
"independent review" bằng PR, hồ sơ này dùng **cổng máy chạy được**, và nêu rõ nó thay thế cái gì:

| Điều PR thường bảo đảm | Cách thay thế ở đây |
|---|---|
| Có người thứ hai đọc diff | `DIFF_SCOPE_MAP.md` §3 khoanh đúng phần cần đọc; Tech Lead ký ở DEV328-P1-03R4 |
| CI xanh trước khi merge | `.github/workflows/wwhn-382-dashboard-closure.yml` chạy verifier + test khoá trên **sqlite và PostgreSQL** |
| Không merge nhầm commit | `verify-wwhn-382-closure.mjs --expect-sha` (truyền `candidate.backend.sha` từ `CANDIDATE_PIN.json`) đỏ nếu cổng chạy lệch commit |
| Không có thay đổi lén sau khi duyệt | Kiểm tra bất biến fingerprint trên dải `semantic_anchor..HEAD` |

## 6. Kết quả chạy tại candidate

| Driver | Lệnh | Kết quả | Log thô |
|---|---|---|---|
| SQLite | `php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn328R4ClosureTest.php` | 5 test, 5 passed, 25 assertions | `RAW_phpunit_wwhn382_sqlite.log` |
| PostgreSQL 16 | `DB_CONNECTION=pgsql DB_DATABASE=hn_wwhn382 … php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn328R4ClosureTest.php` | 5 test, 5 passed, 25 assertions | `RAW_phpunit_wwhn382_pgsql.log` |
| Style | `./vendor/bin/pint --test tests/Feature/Wwhn328R4ClosureTest.php` | passed | — |

Lượt PostgreSQL **đầu tiên đỏ** và đó là phát hiện có giá trị của vòng này: fixture bảo hành ghi
`identification_status='IDENTIFIED'` không kèm `item_id` và dùng `status='CLOSED'`, vi phạm
`chk_erd_warranty_identification_shape` và `chk_erd_warranty_cases_2`. Hai CHECK đó **chỉ tồn tại
trên PostgreSQL** nên SQLite xanh giả. Đã sửa sang nhánh `TEMP_ONLY` hợp lệ với `status='COMPLETED'`.
Cùng họ với đỏ `uk_warehouses_single_active` đã xử lý ở vòng R3 — đây là lý do cổng CI của task
này chạy **cả hai driver** chứ không chỉ driver mặc định.
