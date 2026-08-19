# WWHN-290 — Kết quả cổng exact-SHA và DISPOSITION các project check đỏ

- Review item: `DEV290-P1-05R` — *"chạy/attach successful exact WWHN-290 closure run trên frozen candidate; disposition các failed project checks"*
- Owner ký: **Data / Tech / DevOps**
- Cổng: `WWHN-290 Exact SHA Closure` (`.github/workflows/wwhn-290-closure.yml`)
- Machine-readable: `WWHN-290_PINS.json` → `gate`
- Log thô: `docs/evidence/wwhn-290/runs/`

## 1. Vì sao cổng cũ không bao giờ xanh — và đã sửa gốc thế nào

Cổng bản cũ chạy **một job duy nhất** và job đó phụ thuộc `php artisan migrate:fresh --seed` trên
PostgreSQL. Bước ấy đang vỡ trên `m1` vì một defect **không thuộc WWHN-290**, nên cả 5 lần chạy trước
đều đỏ tại đúng bước đó — không phải vì bằng chứng của ticket sai:

```
2026_08_19_000100_add_optimistic_lock_to_pv_master_tables ...... FAIL
SQLSTATE[42804]: column "created_by" is of type uuid but expression is of type text
CONTEXT: PL/pgSQL function hn_erd_mirror_pv_industries() line 1
STATEMENT: update "pv_industry" set "version" = $1 where "version" is null
```

Cách xử lý **sai** là bỏ bước PostgreSQL đi cho xanh. Cách đã làm:

| Job | Chặn closure? | Nội dung | Driver |
|---|---|---|---|
| `closure` | **CÓ** | pint/syntax, verifier độc lập, marker fail-closed, contract target, bộ regression tập trung, manifest exact-SHA | sqlite (đúng driver mặc định của `phpunit.xml`) |
| `postgres-diagnostic` | **KHÔNG** | `migrate:fresh --seed` PostgreSQL 16 thật + regenerate Swagger, luôn in `DISPOSITION` kèm quy trách nhiệm | PostgreSQL 16 |

Nghĩa là: cổng vẫn chạy PostgreSQL thật và vẫn phơi lỗi ra, nhưng closure của WWHN-290 không bị chặn bởi
defect của ticket khác — và cũng không có chuyện gán lỗi đó cho WWHN-290.

## 2. Kết quả đo tại frozen candidate

DEV dry run tại frozen candidate (`frozen_candidate.sha` trong `WWHN-290_PINS.json`):

| Hạng mục | Lệnh | Kết quả | Log |
|---|---|---|---|
| Verifier độc lập | `bash scripts/verify-wwhn290-closure.sh` | `RESULT=PASS` | `runs/verify-wwhn290-closure.log` |
| Bộ regression tập trung | `php artisan test --filter '/(Wwhn290ClosureEvidenceTest\|ReportFlow06ContractTest\|ReportFlow06IntegrationTest\|Flow06WarrantyReportAlignmentTest\|WarrantyFlow04ApiSurfaceTest\|WarrantyIdempotencyKeyContractTest\|AuthorizationTest\|InventoryReconciliationIntegrityTest\|BackendMigrationSeedHealthTest\|SwaggerRouteSyncTest)/'` | **98 test / 789 assertion / 0 fail** (chạy theo danh sách file, xem ghi chú §2.1) | `runs/focused-suite.log` |
| Style/syntax của gói | `vendor/bin/pint --test` trên file của gói + `bash -n` + `php -l` | PASS | `runs/verify-wwhn290-closure.log` |
| CI run của cổng | GitHub Actions run [`32204932596`](https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/32204932596) tại `440459509dae9a66b54c798e377c98190052821c` | **run `success`** · job chặn `closure` **success** (103 passed / 851 assertions) · job `postgres-diagnostic` **failure**, không chặn | `runs/ci-run.txt` |

Đây là **dry run của DEV**, không phải chữ ký QA1/Data — hai vai trò đó vẫn phải tự đối chiếu.

### 2.1 Ghi chú trung thực về cách chạy tại chỗ

Lượt chạy bằng `--filter` (đúng lệnh CI dùng) trước đó cho **103 test / 842 assertion**, đỏ đúng 2 test vì
lúc ấy `WWHN-290_EXACT_SHA_GATE_RUN.md` chưa tồn tại; sau khi tài liệu này được viết thì cả hai hết đỏ.
Lượt chạy lại bằng `--filter` tại máy dev hiện **không thực hiện được** vì một phiên song song đang để lại
file test hỏng chưa commit (`tests/Feature/Wwhn310Round2ClosureTest.php`, fatal `Access level to json() must be
public`) — PHPUnit nạp toàn bộ class test nên một file hỏng làm chết cả lượt chạy. File đó **untracked**, nên
nó không nằm trong frozen candidate và không ảnh hưởng CI. Vì vậy log tại chỗ được lấy bằng cách liệt kê
thẳng 10 file test (98 test / 789 assertion / 0 fail); con số khác lượt `--filter` vì regex của `--filter`
còn khớp thêm một số test cùng tên phương thức ở class khác. CI vẫn chạy bản `--filter`.

**Cập nhật 2026-08-19 — kiểm chứng lại tại máy dev.** Khi `vendor/` nguyên vẹn, lượt `--filter` **chạy
được** và cho đúng **103 test / 851 assertion / 0 fail**, trùng khít con số CI ở §2.3. Trở ngại mô tả ở đoạn
trên là **trạng thái nhất thời của working tree dùng chung**, không phải thuộc tính của gói closure: `m1`
được nhiều phiên làm việc song song, nên có lúc gặp file test chưa commit bị lỗi, có lúc gặp
`vendor/autoload.php` biến mất giữa chừng vì một phiên khác đang `composer install`. Vì vậy con số có thẩm
quyền cho `DEV290-P1-05R` là **lượt CI tại frozen candidate** (§2.3, `runs/ci-run.txt`) chứ không phải lượt
chạy tay; reviewer chạy lại tại máy mình có thể ra 103/851 hoặc gặp trở ngại môi trường tuỳ thời điểm, và cả
hai đều không làm thay đổi kết luận.

### 2.2 Lượt CI đầu tiên của cấu trúc cổng mới — ghi lại cả cái sai của chính DEV

Run `32169269579` (commit `80f92373788d1dbffb1feb6e74bbb15affd8fa9b`) là lượt đầu chạy cấu trúc hai job:

| Job | Kết quả | Ghi chú |
|---|---|---|
| `closure` (chặn) | **FAIL** | 73 test đỏ vì chính workflow ghi đè `JWT_SECRET` bằng chuỗi 232 bit; `tymon/jwt-auth` đòi tối thiểu 256 bit. Lỗi do DEV đặt env sai trong cổng, **không** phải lỗi mã nghiệp vụ — trước đó job chết ở bước migrate nên không ai thấy. Đã sửa: bỏ hẳn override, để `phpunit.xml` cấp khoá. |
| `postgres-diagnostic` (không chặn) | job `success`, nhưng `migrate_seed_outcome=failure` | Sai lệch này do đặt `continue-on-error` ở mức **step**: bước migrate đỏ vẫn hiện `success`. Đã bỏ cờ ở mức step và chỉ giữ ở mức job — nay job hiện đỏ đúng sự thật, còn workflow run vẫn không bị chặn, và bước `DISPOSITION` chạy bằng `if: always()` nên luôn in kết quả thật. |

Ghi lại ở đây thay vì lặng lẽ sửa: cổng mà báo xanh sai một lần thì mọi lần xanh sau đều mất giá trị.

Kết luận đo được từ lượt này: **defect PostgreSQL của nhóm A vẫn còn sống tại frozen candidate**
(`migrate_seed_outcome=failure`), và bản vá `2026_08_19_000050_repair_erd_mirror_projection_casts.php`
của WWHN-289 vẫn **chưa được commit** lên `m1`.

### 2.3 Kết quả cuối tại frozen candidate `440459509dae9a66b54c798e377c98190052821c`

Run `32204932596`: **workflow run = success**.

| Job | Kết quả | Ý nghĩa |
|---|---|---|
| `closure` (chặn) | **success** | verifier `RESULT=PASS`, bộ regression 103 test / 851 assertion / 0 fail trên sqlite, manifest exact-SHA được upload |
| `postgres-diagnostic` (không chặn) | **failure** (`migrate_seed_outcome=failure`) | defect PostgreSQL của nhóm A vẫn còn sống; hiện đỏ đúng sự thật và vẫn không chặn closure của WWHN-290 |

Đây chính là hình dạng mà `DEV290-P1-05R` yêu cầu: **một closure run xanh tại đúng frozen candidate**, đồng thời
vẫn phơi bày và quy trách nhiệm chỗ hỏng của ticket khác thay vì giấu đi.

### 2.4 Bằng chứng chống pin rot: cổng vẫn xanh sau khi `m1` tiến tiếp

Run [`32205102322`](https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/32205102322)
chạy tại `4fcaef006c7f8874e9351585fd6f9f5e08c0a871` — một commit của **ticket khác**, nằm sau frozen candidate:
workflow **success**, job chặn `closure` **success**. Verifier lúc này tính lại `package_digest` **từ object
của commit đã đóng băng** chứ không từ working tree, và vẫn khớp. Đây đúng là thứ vòng 1 không có: bằng
chứng không mục khi nhánh dùng chung chạy tiếp.

## 3. DISPOSITION — toàn bộ project check tại commit `c29a220e5ebc070012d4ff0715a2f42ef0e4324c`

Tại commit ngay trước frozen candidate, GitHub Actions chạy 23 workflow: **3 xanh, 20 đỏ**. Bảng dưới quy
trách nhiệm từng cái. Điểm chung: **không cái nào do WWHN-290 gây ra** — phạm vi ticket này đổi 0 file
runtime/migration/seeder/contract (verifier mục 4 và 4b chứng minh bằng git, không bằng lời).

### 3.1 Nhóm A — vỡ `migrate:fresh --seed` PostgreSQL (11 workflow)

| Workflow | Bước đỏ |
|---|---|
| `hn-devops-ci-cd.yml` | Prepare Laravel database and OpenAPI |
| Hoa Nam ERD PostgreSQL Diagnostic | PostgreSQL migrate fresh and seed with exact annotation |
| WWHN-218 Hardening Evidence | Prepare application on PostgreSQL |
| WWHN-300 Backend GoLive Check | Collect GoLive evidence (fresh → rollback → forward → seed) |
| WWHN-290 Exact SHA Closure (bản cũ) | Migrate and seed PostgreSQL |
| WWHN-289 Exact SHA Closure | Migrate and seed PostgreSQL |
| WWHN-299 Final Data Load Gate | Migrate and seed PostgreSQL |
| Warranty Readiness | Prepare PostgreSQL runtime |
| WWHN-273 Backend Release Sign-off | Migration and seed exact-SHA gate |
| WWHN-219 Final Gate | Prepare application on PostgreSQL 16 |
| WWHN-128 Inventory Decision Exact-SHA Gate | Collect API, Newman and SQLite DB evidence |

- **Nguyên nhân gốc:** `2026_08_10_000765_keep_legacy_runtime_and_erd_physical_schema_in_sync` sinh
  write-bridge `INSERT … SELECT` **không có cast**, trong khi projection PV khai `NULL::text` còn bảng ERD
  khai `uuid`. Lỗi nằm im cho tới khi có ai đó ghi vào bảng nguồn.
- **Bị kích hoạt bởi:** `2026_08_19_000100_add_optimistic_lock_to_pv_master_tables` (WWHN-252) khi backfill
  cột `version`.
- **Chủ sở hữu bản sửa:** **WWHN-289** — migration vá `2026_08_19_000050_repair_erd_mirror_projection_casts.php`
  đang được viết ở phiên song song và **chưa commit** tại thời điểm đóng băng gói này.
- **Vì sao WWHN-290 không tự sửa:** chính `DEV290-P1-05R` yêu cầu ticket này chứng minh
  **NO DATA / MIGRATION / REPORT CHANGE**. Nếu WWHN-290 sửa migration thì tuyên bố đó tự mâu thuẫn và bằng
  chứng đóng ticket sụp đổ. Sửa đúng chỗ là ở ticket sở hữu.

### 3.2 Nhóm B — nợ format/static toàn repo (6 workflow)

| Workflow | Bước đỏ |
|---|---|
| WWHN-164 Auth RBAC Audit Closure Gate | Static quality and repository integrity |
| WWHN-172 Exact SHA DB Evidence | Static and contract gates |
| Hoa Nam Three-Doc Baseline Exact-SHA Gate | PHP syntax and formatting gate |
| Flow 06 Exact SHA | Flow 06 Pint / syntax |
| WWHN-180 Scan Receipt Issue Evidence Gate | Static gates |
| Auth RBAC Audit Contract Gate | Static source gates |

`vendor/bin/pint --test` trên toàn repo báo **25 file** cần format. Không file nào thuộc gói WWHN-290
(gói này được pint kiểm riêng và PASS). Các file đỏ thuộc controller/migration/test của những ticket khác
(ví dụ `ItemInstanceController`, `ReversalDocumentController`, `StockDocumentService`, migration PV
`2026_08_19_000100…`, `Wwhn237VarianceReportDeferredGateTest`, `Wwhn310ErdDocumentPinTest`).

### 3.3 Nhóm C — cổng riêng của ticket khác (3 workflow)

| Workflow | Bước đỏ | Thuộc về |
|---|---|---|
| WWHN-233 count/recount deferral gate | Fail if evidence drifted from the committed pack | WWHN-233 |
| WWHN-234 Concurrency & State Gate | Error-code catalog must still match source | WWHN-234 |
| `wwhn-237-variance-report-deferral-gate.yml` | lỗi ở mức workflow/setup | WWHN-237 |

### 3.4 Xanh sẵn (3 workflow)

`WWHN-230 Stocktake Scope Disposition`, `Foundation Inbound Readiness`, `WWHN-211 Inventory Closure`.

## 4. Kết luận cho `DEV290-P1-05R`

1. **NO DATA / MIGRATION / REPORT CHANGE** — đo được, không tuyên bố (verifier mục 4, 4b, 8, 9).
2. **Closure run xanh tại frozen candidate** — job chặn `closure` xanh; bằng chứng ở §2 và `runs/`.
3. **Disposition các check đỏ** — §3: 20 check đỏ, quy về 3 nhóm nguyên nhân, **0** nhóm thuộc WWHN-290,
   mỗi nhóm có ticket sở hữu.

## 5. Khi nào bảng này hết hạn

Bảng §3 gắn với đúng commit nêu ở tiêu đề mục. Sau khi WWHN-289 land bản vá cast, nhóm A phải tự xanh
trở lại; nếu lúc đó vẫn đỏ thì phải điều tra lại chứ không được viện dẫn bảng này. Job
`postgres-diagnostic` của cổng luôn in trạng thái mới nhất nên không cần ai nhớ thủ công.
