# WWHN-330 — Evidence Pack R3 (review 71/100 → đóng register R3)

Ngày: 18/08/2026. Nhánh **`m1` duy nhất** (không tạo nhánh riêng). Repo được nhiều phiên
làm việc song song, xem mục "Truy vết commit".

Tài liệu này chỉ nói về **register R3**. Lịch sử R1/R2 (review 47/100) nằm ở
`EVIDENCE_PACK.md`; quyết định nghiệp vụ nằm ở `docs/decision-records/DEC-IMP-001.md`
(Rev.2); quy trình cho QA1 nằm ở `QA_RUNBOOK.md`.

---

## Bảng đóng register

| ID | Sev | Hiện trạng review nêu | Đã làm gì | Bằng chứng |
|---|---|---|---|---|
| **DEV330-P1-01R3** | P1 | "24h vẫn ghi TEMP"; chưa có quyết định retention/expiry, chưa tách logical expiry vs physical retention | Gỡ hẳn hằng `EXPIRY_HOURS` chết; tách **hạn dùng logic** (24h → 404) khỏi **retention vật lý** (xoá đĩa) với mốc, khoảng đệm, nhịp job và **thời gian tồn tại tối đa ≤ 48h** đều đọc từ một hợp đồng; thêm **quét file mồ côi**; thêm **audit `IMPORT_ERROR_FILE_PURGE`**; căn file lỗi theo **schema SRS §13.12** (9 cột + severity + suggested_action + che PII) | DEC-IMP-001 §5, §7 · OpenAPI 3 route Import báo cáo · `Wwhn330ImportR3ClosureTest` (7 test retention/schema) · `QA_RUNBOOK.md` §1 |
| **DEV330-P1-02R3** | P1 | 1000/10MB/physical cap chưa approved; benchmark raw artifact không truy xuất được | Chốt cả ba trong DEC-IMP-001 §1 kèm **chính sách quét dòng vật lý** (bội số 20×, trước là số cứng trong code); gỡ **bản sao hạn mức 10MB/đuôi file** còn sót trong `ImportService::assertFile()`; thêm **mục tiêu hiệu năng** §4 có assert trong test; benchmark thêm biên **1001 / sparse / oversize**, sinh **`BENCHMARK_RAW.json`** và bảng có **môi trường đo suy từ tiến trình thật** | DEC-IMP-001 §1, §4 · `BENCHMARK_RAW.json` + `BENCHMARK_RESULT.md` · `Wwhn330ImportPerformanceBenchmarkTest` (6 test) · `QA_RUNBOOK.md` §2 |
| **DEV330-P1-03R3** | P1 | Identity chỉ SHA+actor+24h; `update_existing` không nằm trong fingerprint | Đã đóng ở R3 phần FE331: định danh = `file_sha256 + actor_id + options_fingerprint`; Rev.2 bổ sung quan hệ với `idempotency_records.scope = IMPORT` của ERD | DEC-IMP-001 §2 · `Wwhn331ImportContractClosureTest` (3 test) · `QA_RUNBOOK.md` §3 |
| **DEV330-P1-04R3** | P1 | Jira source pin stale; commit bundled; chưa có PR/CI xanh | Truy vết commit + SHA chính xác ở mục dưới; đề nghị Tech Lead re-pin trên Jira (comment đã soạn) | Mục "Truy vết commit" + `SIGNOFF_COMMENTS.md` §Tech Lead |
| **DEV330-P1-05R3** | P1 | QA1 vẫn Blocked; evidence cũ không phải SHA mới nhất | Soạn **`QA_RUNBOOK.md`** đủ để QA1 chạy độc lập không cần hỏi lại dev; SHA candidate pin ở mục dưới | `QA_RUNBOOK.md` (6 mục + checklist nộp bằng chứng) |
| **DEV330-P1-06R3** | P1 | `sku.manage` có enforce nhưng chưa có rule data-scope được duyệt | Rule đã duyệt + enforce cho cả 3 route qua một query duy nhất; 404 (không 403) cho bản ghi ngoài scope theo **SRS §13.8** | DEC-IMP-001 §3 · `Wwhn331ImportContractClosureTest` (3 test cross-user) · `QA_RUNBOOK.md` §5 |
| **DEV330-P1-07R3** | P1 | `LockTimeoutException` → callback chạy **không có lock** (fail-open) | Đổi sang **fail-closed** → `ImportConcurrencyException` → **409 `CONCURRENCY_CONFLICT`**; neo vào **NFR-SRS-003** và **AC-GWT-CONC-001**; FE nhận diện riêng mã này | DEC-IMP-001 §6 · `Wwhn330ImportR3ClosureTest` (3 test) · FE `SkuImportConcurrencyError` + test · `QA_RUNBOOK.md` §4 |
| **DEV330-P2-01R3** | P2 | Description ghi BE2/Duy, owner thật là Quang | Comment PM đã soạn, ghi rõ ownership lịch sử và hiện tại | `SIGNOFF_COMMENTS.md` §PM |

---

## Ba lỗi của chính bản trước, đã sửa (không giấu)

1. **DEC-IMP-001 Rev.1 trích số benchmark sai.** §1 viết "dry-run 1 000 dòng ~217 ms và
   commit ~1,76 s" trong khi `BENCHMARK_RESULT.md` cùng repo ghi 3 308 ms và 18 532 ms.
   Một tài liệu quyết định trích số không khớp artifact thì không dùng làm căn cứ được.
   **Rev.2 bỏ hẳn việc nhắc lại số đo** và trỏ thẳng tới artifact — hai nơi không còn cơ
   hội lệch lần nữa.
2. **Artifact benchmark tự mâu thuẫn.** Dòng môi trường ghi `pgsql (hn_wwhn310_full)`,
   phần "Cách đọc" ngay dưới lại khẳng định "chạy trên sqlite in-memory" — vì đoạn văn đó
   được viết cứng trong test. Nay **mọi câu mô tả môi trường suy ra từ tiến trình thật**
   (`DB::connection()->getDriverName()`, `PHP_VERSION`, `memory_limit`), nên artifact
   không thể tự nói ngược nhau.
3. **Hạn mức bị nhân bản.** Rev.1 chuyển validator HTTP sang đọc config nhưng để lại
   `10 * 1024 * 1024` và `['xlsx','xls']` viết cứng trong `ImportService::assertFile()`.
   Nâng `max_file_size_kb` lên 20 MB thì HTTP cho qua còn service vẫn chặn ở 10 MB, và
   thông điệp lỗi nói một con số không còn đúng. Nay cả hai tầng đọc chung hợp đồng.

## Bốn lỗ hổng tự phát hiện khi rà, đã vá

| # | Lỗ hổng | Vì sao là lỗi thật |
|---|---|---|
| 1 | **File lỗi mồ côi không bao giờ bị xoá** | `attachErrorFile()` ghi file **trước** rồi mới lưu path vào DB. Bước lưu thất bại (đã có nhánh log riêng cho ca đó) để lại một file mà vòng dọn theo `import_reports` không bao giờ chạm tới → nội dung file người dùng nộp nằm trên đĩa vĩnh viễn |
| 2 | **Lần chạy purge không để lại bằng chứng** | SRS §13.8 yêu cầu purge job "audit số record anonymized/purged". Bản trước chỉ `$this->info(...)` ra stdout của cron — không truy vấn lại được, không phải bằng chứng tuân thủ |
| 3 | **Chính sách quét dòng vật lý là số cứng** | Bội số `20` nằm trong `ImportService` như hằng số kỹ thuật, trong khi nó là **chính sách chống DoS** và là hạn mức **người dùng chạm phải** (file thưa) — nhưng không được công bố ra `constraints`, nên UI không giải thích nổi vì sao file 300 dòng bị từ chối |
| 4 | **File lỗi lệch schema baseline** | SRS §13.12 là nơi duy nhất trong baseline định nghĩa error-file (9 cột, có `severity`, `suggested_action`, `raw_value` che PII). Bản trước tự đặt 5 cột riêng → người vận hành phải học hai định dạng file lỗi cho cùng một hệ thống |

---

## Kết quả chạy thật (18/08/2026, sqlite theo `phpunit.xml`)

| Lệnh | Kết quả |
|---|---|
| BE `phpunit tests/Feature/Wwhn330ImportR3ClosureTest.php` | **14/14 pass**, 63 assertions |
| BE `phpunit tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php` | **6/6 pass**, 36 assertions (sinh lại 2 artifact) |
| BE regression import (hardening + residual + purge + contract closure + validation + dry-run + batch) | **44/44 pass**, 256 assertions |
| BE `phpunit` (full suite) | **761 test — 734 pass, 0 fail, 27 skip**, 8 642 assertions |
| BE `pint --test` các file thuộc WWHN-330 R3 | **PASS** |
| FE `vitest run src/modules/master-data` | **24/24 pass** |
| FE `npm run typecheck` | **PASS** |
| FE `npm run lint` | **0 error**, 25 warning (pre-existing, không ở file WWHN-330); `eslint` riêng 4 tệp thay đổi: **0 cảnh báo** |
| FE `npx vitest run` (full) | **69 file — 526/526 pass** |
| FE `npm run build` | **ĐỎ** ở `src/modules/reverse/components/ReversalDetailModal.tsx` (`TS6133 'staleRecovered'`) — tệp **không thuộc** WWHN-330/331, phiên làm việc khác đang sửa dở trên cùng nhánh `m1`. Không đụng vào để tránh xung đột; chạy lại sau khi phiên kia xong |

**Ghi chú trung thực:** `pint --test` trên toàn repo vẫn đỏ ở các file **không thuộc**
WWHN-330/331 (debt có sẵn: `ItemInstanceController`, `StockDocumentService`, một số
migration/seeder/test cũ). Lần rà này không đụng vào chúng để tránh diff lớn ngoài phạm vi
ticket; cần một task dọn Pint riêng.

---

## Truy vết commit (DEV330-P1-04R3)

| Repo | Nhánh | SHA candidate | Ghi chú |
|---|---|---|---|
| `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `6916df47b5d9186b799e4a79c64c1b0556b7388d` | |
| `WMS_Warehouse_Hoa_Nam_FE` | `m1` | `6c096808447ec6935a0ebe6ac3950c032084f4ab` | |

**Baseline đánh giá:** `m1` là nguồn chuẩn duy nhất. `m1-be-2` đã được xác nhận là nhánh
superseded, ancestor thuần của `m1`, và nằm trong `LEGACY_SOURCES_EXCLUDED.txt` của gate
WWHN-219. Toàn bộ code WWHN-330 chỉ tồn tại trên `m1`. Đề nghị Tech Lead **re-pin chính
thức trên Jira** theo comment đã soạn — đây là văn bản hoá lại quyết định đã ngầm định
trong repo, không phải tự đổi baseline.

**Về "commit bundled":** repo được nhiều phiên làm việc song song trên cùng nhánh `m1`,
nên một commit có thể mang tiêu đề của task khác mà vẫn chứa file của WWHN-330. Cách đọc
đúng là theo **danh sách tệp**, không theo tiêu đề commit — xem `R3_CHANGE_MANIFEST.md`
(sha256 từng tệp) đi kèm.

---

## Còn mở — không thuộc thẩm quyền DEV

1. **QA1 (WWHN-332)** chạy độc lập theo `QA_RUNBOOK.md` trên đúng SHA ở bảng trên.
   *Đây là gate cứng; DEV không tự nhận thay QA.*
2. **DevOps** xác nhận scheduler PROD thực sự chạy `import:purge-error-files`. Không có
   cron thì toàn bộ hợp đồng retention vật lý (§5) đúng trong code nhưng sai trong thực tế.
3. **BA/Tech Lead** đưa `import_reports` vào **ERD amendment** — bảng đang tồn tại trong DB
   và code nhưng không có trong ERD baseline (DEC-IMP-001 §9). Cùng lớp việc mà
   `DEC-DATA-001` đã xử lý cho defect catalogs.
4. **BA** quyết định có thống nhất tuyệt đối tập `error_code` với danh sách §13.12 hay
   không — hiện là sai khác **có kiểm soát**, đã ghi ở DEC-IMP-001 §7.
5. **Tech Lead/BA** chốt lại mâu thuẫn ErrorEnvelope giữa SRS §8.2 (`code`, `field_errors[]`,
   `request_id` top-level) và Dev Guide (`error_code`, `errors{}`, `meta.request_id`). FE
   hiện đọc **cả hai** để không phụ thuộc một phía; đây là giải pháp phòng thủ, không phải
   lời giải cho mâu thuẫn tài liệu.
