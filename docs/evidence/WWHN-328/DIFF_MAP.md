# WWHN-328 — Diff map trong bundled history (DEV328-P1-03R4)

Register R4 mở lại P1-03 vì `127c573` là **generic bundled commit**: tiêu đề "gỡ cờ 13",
80 file, không mang Jira key, không có PR nào để review. Không thể xoá lịch sử đã đẩy, nên
cách đóng là **khoanh đúng phần thuộc WWHN-328** bên trong các commit đó và cho phép review
đúng phần ấy bằng một lệnh chạy lại được.

## 1. Ranh giới: file nào thuộc WWHN-328

| File | Thuộc WWHN-328 vì |
|---|---|
| `Modules/Warehouse/app/Http/Controllers/OperationalReportController.php` | chỉ khối `// ── Dashboard Quick` + helper `period()` — phần còn lại của file là RPT-INV/XNT/IO/MOV của Flow 06 |
| `Modules/Warehouse/routes/api.php` | đúng 1 dòng khai `reports/dashboard-quick` |
| `tests/Feature/Wwhn328DashboardQuickContractTest.php` | toàn bộ (17 case) |
| `tests/Feature/Wwhn328R4ClosureTest.php` | toàn bộ (5 case, thêm ở vòng R4) |
| `storage/api-docs/api-docs.json` / `.yaml` | chỉ `paths["/api/v1/reports/dashboard-quick"]` |
| `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | chỉ request trỏ `reports/dashboard-quick` |
| `docs/evidence/WWHN-328/**` | toàn bộ |

Bốn file giữa là **tệp dùng chung**: mỗi task khác chạm route/permission là chúng được sinh
lại. Đó chính là lý do mọi vòng review trước đều kết luận "evidence stale" — không phải người
pin ẩu, mà **cách pin sai tầng**. `scripts/wwhn-328-contract-fingerprint.mjs` pin ở mức đối
tượng (operation / request / khối mã / dòng route) nên pin đứng vững khi `m1` chạy tiếp và chỉ
đỏ khi ai đó đổi ngữ nghĩa dashboard.

## 2. Diff thuộc WWHN-328 trong từng commit bundled

Đếm bằng `git show --numstat <sha> -- <file subset>` (chạy lại được, không chép tay):

| Commit | Tiêu đề gốc | File tổng | File thuộc WWHN-328 | +/- thuộc WWHN-328 |
|---|---|---|---|---|
| `6db0965940a6ae8b22a591d50705f258326b172d` | `gỡ cờ 12` | 72 | 5 | controller +128/-10 · test +375/-0 · openapi.json +43/-11 · openapi.yaml +36/-11 · postman +38/-2 |
| `624af01` | `docs(WWHN-328): pin candidate SHA, KPI matrix and closure evidence` | 2 | 2 | EVIDENCE_PACK +90 · KPI_MATRIX +88 |
| `127c573a7236ad1c25829eb92011a4914360b840` | `gỡ cờ 13` | 80 | 4 | EVIDENCE_PACK +37 · test +9/-1 · openapi.json/yaml (sinh lại toàn tệp, xem §3) |
| `c855851b37920237d110f7dffe5dbbad509aebce` | `fix(WWHN-254): data-scope kho cho moi bao cao` | 5 | 1 | controller +346/-50 — **đổi tên** `dashboardWarehouseScope()` → `reportWarehouseScope()` |
| `a16d1d6` | `fix(WWHN-231): dong bo aggregate ledger stock-neutral` | 8 | 1 | controller — **không chạm** khối dashboard (fingerprint không đổi, §3) |

Lệnh tái lập:

```bash
git show --numstat --format='' <sha> -- \
  Modules/Warehouse/app/Http/Controllers/OperationalReportController.php \
  Modules/Warehouse/routes/api.php \
  tests/Feature/Wwhn328DashboardQuickContractTest.php \
  docs/evidence/WWHN-328 \
  storage/api-docs/api-docs.json storage/api-docs/api-docs.yaml \
  'docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json'
```

## 3. Vì sao candidate KHÔNG còn là `127c573`

`127c573` không phải commit cuối chạm bề mặt WWHN-328. Sau nó, `c855851` (WWHN-254) **đổi tên**
helper data-scope của chính dashboard, và `a16d1d6` (WWHN-231) viết lại aggregate ledger trong
cùng controller. Pin `127c573` là pin vào một bản mã mà runtime hiện tại không còn chạy.

Đo bằng fingerprint đối tượng:

```bash
node scripts/wwhn-328-contract-fingerprint.mjs --origin
```

Kết quả tại lượt đo đóng vòng R4:

| Chỉ số | Giá trị |
|---|---|
| `origin` (commit xa nhất mà contract hiện hành đã đúng) | `c855851b37920237d110f7dffe5dbbad509aebce` |
| `unchangedCommits` (số commit liên tiếp giữ nguyên contract) | 58 |

Đọc kết quả này: contract `dashboard-quick` **hình thành tại `c855851` và không đổi suốt 58
commit tới HEAD** — kể cả `a16d1d6` sửa cùng file. Đây là bằng chứng thay cho câu "m1 đang
chạy nên bằng chứng hết hạn": không phải hết hạn, mà là **bất biến kiểm chứng được trên dải**.

## 4. Disposition review cho commit không có PR

Không có PR nào cho `6db0965` / `127c573` và không thể tạo PR ngược cho lịch sử đã đẩy.
Disposition được chọn: **approved direct-commit + post-hoc reviewable slice**.

| Yêu cầu review | Đáp ứng bằng |
|---|---|
| Xem đúng phần thuộc WWHN-328 | §2 (lệnh numstat) + fingerprint đối tượng ở §3 |
| Xác nhận không có thay đổi lén sau candidate | `--check ... --range <candidate>..HEAD` (đỏ nếu bất kỳ commit nào lệch) |
| Chạy lại test đúng phần đó | `php vendor/bin/phpunit --filter 'Wwhn328(DashboardQuickContract\|R4Closure)Test'` |
| Người duyệt độc lập | Tech Lead ký disposition này bằng comment trên WWHN-328 (không phải tác giả code) |

Lần sau **không** lặp lại: commit chạm dashboard phải mang Jira key ở tiêu đề. Quy tắc này ghi
ở `docs/docs_task_jira/WWHN-328.md §6`.
