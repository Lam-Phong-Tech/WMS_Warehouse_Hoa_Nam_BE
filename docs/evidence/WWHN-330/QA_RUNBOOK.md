# WWHN-330 / WWHN-332 — QA Runbook: import SKU bằng Excel

Mục đích: QA1 chạy **độc lập** trên đúng SHA candidate (DEV330-P1-05R3) mà không phải hỏi
lại dev điều gì. Mỗi mục ghi rõ *chuẩn bị gì*, *làm gì*, *kỳ vọng gì*, và *bằng chứng cần
nộp*. Hợp đồng nghiệp vụ tham chiếu: `docs/decision-records/DEC-IMP-001.md` (Rev.2).

**Trước khi bắt đầu — ghi lại vào biên bản test:**

| Mục | Cách lấy | Ghi lại |
|---|---|---|
| SHA BE | `git -C <BE> rev-parse HEAD` | 40 ký tự đầy đủ |
| SHA FE | `git -C <FE> rev-parse HEAD` | 40 ký tự đầy đủ |
| Nhánh | `git rev-parse --abbrev-ref HEAD` | phải là `m1` |
| Hợp đồng máy chủ đang áp dụng | `GET /api/v1/skus/import-reports` → `meta.constraints` | chụp nguyên khối JSON |
| Môi trường | tên env, DB driver, phiên bản PHP | |

`meta.constraints` là **nguồn sự thật của kỳ vọng**: mọi con số dưới đây phải khớp khối đó,
không lấy từ tài liệu này. Nếu lệch → defect hợp đồng, dừng và báo ngay.

---

## 1. Error file (DEV330-P1-01R3)

### 1.1 Nội dung và schema

1. Import một file có ít nhất 2 loại lỗi khác nhau (ví dụ: 2 dòng trùng `sku_code`, 1 dòng
   `sku_code` sai định dạng, 1 dòng `brand_code` không tồn tại).
2. Kỳ vọng: **422**, `error.code` = `SKU_IMPORT_VALIDATION_FAILED`, `data.committed=false`,
   `data.error_file_available=true`, và **không SKU nào** được tạo/cập nhật (kiểm bằng
   `GET /api/v1/skus?search=<mã trong file>`).
3. Tải file lỗi: `GET /api/v1/skus/import-reports/{id}/error-file`.
4. Kỳ vọng — **9 cột đúng thứ tự** (SRS §13.12):
   `source_file, sheet, row_no, field, error_code, message, raw_value, severity, suggested_action`
5. Kiểm từng dòng: `source_file` = tên file đã nộp; `sheet` = tên sheet thật; `severity` =
   `CRITICAL` với `DUPLICATE_CODE` / `DUPLICATE_SKU_CODE_IN_FILE` / `INACTIVE_SKU_CONFLICT`
   / `UNKNOWN_MASTER_CODE`, `ERROR` với các mã còn lại; `suggested_action` không rỗng.
6. Mở file bằng **Excel thật**: tiếng Việt không lỗi font (BOM), và không ô nào bị Excel
   diễn giải thành công thức.

**Bằng chứng:** file CSV tải về + ảnh chụp mở bằng Excel + response 422 đầy đủ.

### 1.2 Sanitize & che PII

1. Nộp file có một dòng `sku_code` = `=cmd|' /C calc'!A0` → kỳ vọng ô `raw_value` trong CSV
   bắt đầu bằng dấu nháy `'`, Excel **không** thực thi.
2. Nộp file có một dòng `sku_code` = `khach.hang@example.com` → kỳ vọng `raw_value` hiển thị
   dạng đã che (`kh***@***`), **không** còn địa chỉ đầy đủ.
3. Nộp file có `sku_code` chứa số điện thoại `0912345678` → kỳ vọng bị che một phần.

### 1.3 Hạn dùng LOGIC (tải được đến khi nào)

1. Lấy `error_file_expiry_hours` từ `constraints` (mặc định 24).
2. Ngay sau import: `error_file_available=true`, tải → **200**.
3. Đẩy thời gian (hoặc chỉnh `error_file_expires_at` về quá khứ trong DB test):
   `error_file_available=false`, tải → **404**.
4. Kỳ vọng: **bản ghi Import báo cáo và `row_errors` vẫn còn** (`GET .../import-reports/{id}`
   trả 200) — chỉ file tải về hết hạn, không phải audit trail bị xoá.

### 1.4 Retention VẬT LÝ (file còn trên đĩa đến khi nào)

Đây là mục **mới ở R3** và là mục dễ bỏ sót nhất: "hết hạn" ở API không có nghĩa file đã bị
xoá khỏi đĩa.

```bash
php artisan import:purge-error-files --dry-run
```

1. Kỳ vọng: liệt kê đúng những file đã quá hạn, **không xoá gì**; chạy lại `--dry-run` cho
   kết quả y hệt.
2. Chạy thật:
   ```bash
   php artisan import:purge-error-files
   ```
   Kỳ vọng: file biến mất khỏi `storage/app/import-error-files/`; `error_file_path` và
   `error_file_expires_at` trên báo cáo về `null`; `import_reports` và `row_errors` **giữ
   nguyên**.
3. Chạy lại lần nữa → 0 file, không lỗi (idempotent).
4. **Audit**: kiểm `audit_log` có bản ghi `action = 'IMPORT_ERROR_FILE_PURGE'` với
   `after_data` chứa `expired_reports_purged`, `orphan_files_purged`, `max_on_disk_hours`.
   *Không có bản ghi audit = defect* (SRS §13.8 / NFR-SRS-018).
5. **File mồ côi**: đặt tay một file `.csv` vào `storage/app/import-error-files/` không ứng
   với báo cáo nào, chỉnh `mtime` lùi quá `error_file_max_on_disk_hours`, chạy lệnh → file
   bị xoá. Đặt thêm một file **mới tinh** → **không** bị xoá.
6. **Lịch chạy**: `php artisan schedule:list` phải hiển thị `import:purge-error-files` chạy
   hằng ngày đúng giờ ghi trong `warehouse.sku_import.error_file_purge_daily_at`.
   *DevOps xác nhận scheduler chạy thật trên PROD — không có cron thì toàn bộ mục 1.4 vô
   nghĩa dù code đúng.*

---

## 2. Hạn mức & hiệu năng (DEV330-P1-02R3)

### 2.1 Biên số dòng

| Case | File | Kỳ vọng |
|---|---|---|
| Đúng hạn mức | `max_rows` dòng dữ liệu | 200, `total_rows` = `max_rows`, ghi đủ |
| Vượt 1 dòng | `max_rows + 1` | **422** `SKU_IMPORT_ROW_LIMIT_EXCEEDED`, `data.actual_rows` = `max_rows+1`, **0 SKU** ghi |
| Gấp 20 lần | `20 × max_rows` | 422 cùng mã, trả về **nhanh** (không nạp hết workbook), 0 SKU ghi |
| File thưa | `max_rows` dòng dữ liệu, xen 1 dòng trống giữa mỗi dòng | **200**, ghi đủ `max_rows` — không bị cắt âm thầm |
| Trải quá trần quét | 2 dòng dữ liệu nhưng dòng cuối nằm sau `max_physical_rows_to_scan` | **422** `SKU_IMPORT_ROW_LIMIT_EXCEEDED`, thông điệp hướng dẫn xoá dòng trống thừa |

Kiểm "0 SKU ghi" bằng `GET /api/v1/skus` chứ không tin vào response.

### 2.2 Biên 10 MB (mục QA phải tự dựng file thật)

Benchmark tự động chỉ kiểm *đường chặn* bằng cách hạ trần qua hợp đồng. **Con số tuyệt đối
10 MB do QA xác minh ở đây.** Dựng file khó nén (xlsx là zip nên dữ liệu lặp lại sẽ nén rất
nhỏ):

```bash
php -r '
$s = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$sh = $s->getActiveSheet();
$sh->fromArray(["sku_code","sku_name","unit"], null, "A1");
for ($i = 1; $i <= 900; $i++) {
  $sh->fromArray(["QA-BIG-".$i, bin2hex(random_bytes(3000)), "CAI"], null, "A".($i+1));
}
(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($s))->save("qa-10mb.xlsx");
' -d memory_limit=1G
ls -lh qa-10mb.xlsx   # tăng/giảm 900 và 3000 tới khi vượt 10 MB
```

- File **dưới** `max_file_size_kb`: qua được rule upload (có thể vẫn 422 vì lỗi dòng — đó là
  đường khác, ghi rõ trong biên bản).
- File **trên** `max_file_size_kb`: **422 validation**, thông điệp nói về kích thước, **0
  SKU** ghi.

### 2.3 Hiệu năng

1. Chạy benchmark tự động trên môi trường test:
   ```bash
   php vendor/bin/phpunit tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php
   ```
   Nộp kèm `docs/evidence/WWHN-330/BENCHMARK_RAW.json` và `BENCHMARK_RESULT.md` **do chính
   lần chạy của QA sinh ra** (không dùng lại file trong repo).
2. Đo trên môi trường **production-like** bằng đồng hồ thật (Postman/curl `-w '%{time_total}'`)
   cho 3 kịch bản: dry-run `max_rows`, commit `max_rows`, reject `20 × max_rows`.
3. Ghi lại: thời gian, cấu hình máy (CPU/RAM), DB driver, có/không có dữ liệu nền.
   **Đây mới là số dùng để bàn SLA** — ngân sách trong DEC-IMP-001 §4 chỉ là ngưỡng bắt hồi
   quy trên CI.

---

## 3. Idempotency & tùy chọn (DEV330-P1-03R3)

| # | Thao tác | Kỳ vọng |
|---|---|---|
| 1 | Import commit file F, `update_existing=false` | 200, `duplicate=false`, ghi SKU |
| 2 | Nộp lại **đúng** F, **cùng** người, `update_existing=false` | 200, **`duplicate=true`**, `duplicate_of_import_report_id` = báo cáo lần 1, **không** tạo báo cáo mới, **không** ghi SKU lần 2 |
| 3 | Nộp lại F, **đổi** `update_existing=true` | 200, **`duplicate=false`** — chạy thật (thao tác nghiệp vụ khác) |
| 4 | Người **khác** nộp F, cùng tùy chọn | 200, `duplicate=false` — chạy độc lập |
| 5 | Kiểm bản ghi gốc | `duplicate_of_replay = true` sau bước 2 |

**Đối chiếu số liệu (bắt buộc nộp):** trước/sau mỗi bước ghi lại `COUNT(*)` của `skus` và
`import_reports`, và `file_sha256` của file đã nộp. Bước 2 phải cho **cả hai số không đổi**.

**Tự dựng lại vân tay không cần đọc code:**

```bash
printf 'update_existing=1' | sha256sum   # phải bằng options_fingerprint của lần chạy update_existing=true
printf 'update_existing=0' | sha256sum
```

---

## 4. Tương tranh — 409 (DEV330-P1-07R3) ⚠️ MỤC MỚI Ở R3

Đây là hành vi **đã đổi**: trước đây hết thời gian chờ khoá thì hệ thống vẫn chạy tiếp
(fail-open); nay nó **từ chối**.

1. Gửi **hai** request commit **giống hệt nhau** (cùng người, cùng file, cùng
   `update_existing`) gần như đồng thời. Cách đơn giản nhất:
   ```bash
   curl -s -o r1.json -w '%{http_code}\n' -X POST "$BASE/api/v1/skus/import-excel" \
     -H "Authorization: Bearer $TOKEN" -F file=@big.xlsx &
   curl -s -o r2.json -w '%{http_code}\n' -X POST "$BASE/api/v1/skus/import-excel" \
     -H "Authorization: Bearer $TOKEN" -F file=@big.xlsx &
   wait
   ```
   Dùng file đủ lớn (gần `max_rows`) để lần chạy thứ nhất còn đang giữ khoá.
2. Kỳ vọng (SRS NFR-SRS-003 / AC-GWT-CONC-001): **đúng một** request thành công. Request
   còn lại là **200 `duplicate=true`** (nếu nó vào sau khi lần 1 đã COMMITTED) hoặc **409
   `CONCURRENCY_CONFLICT`** (nếu nó hết thời gian chờ khoá). **Không được** có hai response
   200 cùng `duplicate=false`.
3. Kỳ vọng số liệu: `import_reports` chỉ tăng **1** dòng COMMITTED cho file đó; `skus` không
   có bản ghi nhân đôi.
4. Ca 409 phải: `data.committed=false`, `data.partial_write=false`, có `data.constraints`,
   và **không** tạo Import báo cáo nào.
5. **UI**: màn hình import hiện cảnh báo "đang import, thử lại sau", **giữ nguyên** kết quả
   kiểm tra thử và nút Import vẫn bấm lại được — không hiện bảng lỗi trống.
6. **Ca lock timeout cưỡng bức** (nếu QA có quyền chỉnh config): đặt
   `SKU_IMPORT_LOCK_WAIT_SECONDS=1`, giữ khoá bằng một request dài, gửi request thứ hai →
   409 trong ~1 giây.

---

## 5. RBAC & data-scope (DEV330-P1-06R3)

Chuẩn bị 3 tài khoản: **A** (có `sku.manage`, **bị giới hạn kho**), **B** (có `sku.manage`,
bị giới hạn kho, khác A), **C** (không có `sku.manage`), và **S** (SUPER_ADMIN, không giới
hạn kho).

| # | Người gọi | Request | Kỳ vọng |
|---|---|---|---|
| 1 | C | `GET /skus/import-reports` | **403** |
| 2 | C | `POST /skus/import-excel` | **403** |
| 3 | A | `GET /skus/import-reports` | 200, `meta.data_scope = ACTOR_SCOPED`, chỉ thấy báo cáo của A |
| 4 | A | `GET .../{id_của_A}` và `.../error-file` | 200 |
| 5 | B | `GET .../{id_của_A}` | **404** `RESOURCE_NOT_FOUND` (**không** 403 — không được xác nhận id tồn tại) |
| 6 | B | `GET .../{id_của_A}/error-file` | **404** |
| 7 | B | `GET /skus/import-reports` | 200, danh sách **rỗng** |
| 8 | S | `GET .../{id_của_A}` và `.../error-file` | 200, `meta.data_scope = GLOBAL` |
| 9 | Bất kỳ | mọi response báo cáo | **không** chứa `error_file_path` |

Bước 5 và 6 là mục an ninh chính: file lỗi chứa nguyên nội dung file người khác nộp.

---

## 6. Checklist nộp bằng chứng

- [ ] SHA BE + SHA FE (40 ký tự), nhánh `m1`, tên môi trường
- [ ] Khối `meta.constraints` chụp từ chính môi trường test
- [ ] File CSV lỗi tải về + ảnh chụp mở bằng Excel
- [ ] Output `import:purge-error-files --dry-run` và chạy thật + bản ghi audit tương ứng
- [ ] `BENCHMARK_RAW.json` + `BENCHMARK_RESULT.md` do lần chạy của QA sinh ra
- [ ] Số đo hiệu năng thủ công trên môi trường production-like (3 kịch bản)
- [ ] Bảng đối chiếu `COUNT(*)` skus/import_reports trước–sau cho mục 3
- [ ] Log 2 request đồng thời + mã HTTP của từng request (mục 4)
- [ ] Ma trận 9 dòng RBAC/data-scope với mã HTTP thực tế
- [ ] Kết luận PASS / danh sách defect kèm mức độ
