# WWHN-330 — Bộ comment xác nhận / sign-off (mỗi vai trò **1 comment duy nhất**)

Dán thẳng lên Jira WWHN-330. Mỗi vai trò chỉ đăng **một** comment gộp đủ mọi mục cần xác
nhận — không đăng nhiều lần cho từng hạng mục.

> **Trước khi đăng:** thay `<SHA-BE>` / `<SHA-FE>` bằng SHA 40 ký tự thật của commit chốt
> trên nhánh `m1`. Không đăng khi chưa có SHA thật.

Bản này thay thế bộ comment của vòng review trước (47/100): register **R3** bổ sung
DEV330-P1-06R3 và DEV330-P1-07R3, và mở rộng phạm vi P1-01/P1-02. Căn cứ kỹ thuật:
`docs/decision-records/DEC-IMP-001.md` (Rev.2), `docs/evidence/WWHN-330/EVIDENCE_PACK_R3.md`.

---

## 1) Tech Lead — 1 comment

**[Tech Lead] Xác nhận WWHN-330 register R3**

Tôi đã đọc code + evidence trên nhánh `m1` và **xác nhận 5 điểm**:

1. **Baseline đánh giá (đóng DEV330-P1-04R3)**: nguồn code chính thức của WWHN-330 là
   `WMS_Warehouse_Hoa_Nam_BE` / `WMS_Warehouse_Hoa_Nam_FE`, nhánh **`m1`**, SHA
   `<SHA-BE>` / `<SHA-FE>`. `m1-be-2` là nhánh superseded (ancestor thuần của `m1`, nằm
   trong `LEGACY_SOURCES_EXCLUDED.txt` của gate WWHN-219) — **không** dùng làm nguồn
   review. Comment "BE2 code baseline for review" trỏ `m1-be-2` được thay thế bởi comment
   này. Commit trên `m1` có thể mang tiêu đề task khác do nhiều phiên làm việc song song;
   đọc theo **danh sách tệp + sha256** ở `R3_CHANGE_MANIFEST.md`, không theo tiêu đề.
2. **Chính sách tương tranh (đóng DEV330-P1-07R3)**: duyệt **fail-closed** — hết thời gian
   chờ khoá thì trả **409 `CONCURRENCY_CONFLICT`**, không chạy tiếp. Đúng NFR-SRS-003 và
   AC-GWT-CONC-001 ("một request thành công, còn lại 409/412/422 theo điểm phát hiện"), và
   409 `CONCURRENCY_CONFLICT` nằm trong 4 mã 409 hợp lệ của §8.2. Chấp nhận đánh đổi: sự
   cố Redis làm import bị chặn thay vì chạy mất an toàn.
3. **Chính sách quét dòng vật lý (đóng DEV330-P1-02R3 phần kỹ thuật)**: duyệt bội số
   **20×** (trần 20 040 dòng bảng tính), vượt trần báo lỗi tường minh chứ không cắt âm
   thầm, và trần này được công bố ra `constraints` cho client.
4. **Mục tiêu hiệu năng**: duyệt ngân sách ở DEC-IMP-001 §4 là **ngưỡng bắt hồi quy trên
   CI**, không phải cam kết SLA. SLA production do QA1/DevOps đo lại.
5. **Không tạo nhánh riêng**: toàn bộ thay đổi WWHN-330/331 nằm trên `m1`.

Không phát sinh việc mới từ phía Tech Lead.

---

## 2) BA — 1 comment

**[BA] Xác nhận hợp đồng nghiệp vụ WWHN-330 (DEC-IMP-001 Rev.2)**

Tôi **xác nhận 6 tham số nghiệp vụ** sau, chốt chính thức, không còn giá trị nào để "TẠM"
trong code:

1. **Hạn mức import**: 1 000 dòng dữ liệu/lần; file ≤ 10 MB; định dạng .xlsx/.xls.
2. **Cửa sổ idempotent**: 24 giờ. **Định danh một lần import** = `file_sha256 + actor_id +
   update_existing` — đổi `update_existing` là **thao tác nghiệp vụ khác**, phải chạy thật,
   không được coi là gửi trùng (đóng DEV330-P1-03R3).
3. **Hạn tải file lỗi CSV (logic)**: 24 giờ; quá hạn API trả 404, **bản ghi Import báo cáo
   và danh sách dòng lỗi vẫn giữ nguyên**.
4. **Retention vật lý của file lỗi (đóng DEV330-P1-01R3)**: file bị **xoá khỏi đĩa** bởi
   job chạy hằng ngày; thời gian tồn tại tối đa trên đĩa **≤ 48 giờ**. Duyệt cả việc xoá
   **file mồ côi** (không còn báo cáo trỏ tới) theo cùng mốc retention.
5. **Định dạng file lỗi**: theo schema SRS §13.12 (9 cột, có `severity` và
   `suggested_action`, `raw_value` che nếu là dữ liệu cá nhân). Chấp nhận **sai khác có
   kiểm soát**: tập mã lỗi của import SKU rộng hơn danh sách §13.12 vì mã hiện tại chính
   xác hơn cho người sửa file.
6. **Data-scope Import báo cáo (đóng DEV330-P1-06R3)**: người dùng **không bị giới hạn kho**
   xem được mọi báo cáo (GLOBAL); người **bị giới hạn kho** chỉ xem/tải được báo cáo của
   chính mình; báo cáo của người khác trả **404** (không phải 403) để không lộ sự tồn tại
   — đúng SRS §13.8. Không phát sinh permission mới.

**Việc tôi ghi nhận và sẽ đưa vào bản tài liệu kế tiếp** (không chặn ticket này): bổ sung
bảng `import_reports` vào ERD — bảng đang tồn tại trong hệ thống nhưng chưa có trong ERD
baseline (DEC-IMP-001 §9).

Không yêu cầu thay đổi thêm về nghiệp vụ.

---

## 3) PM — 1 comment

**[PM] Xác nhận phân công & trạng thái WWHN-330**

Tôi **xác nhận 3 điểm**:

1. **Ownership (đóng DEV330-P2-01R3)**: "BE2 — Duy Khánh Nguyễn" trong Description là
   **ownership lịch sử**, giữ lại để truy vết. **Ownership hiện tại là DEV — Quang Nguyễn
   Thanh**, theo comment "CẬP NHẬT PHÂN CÔNG FE/BE" trên WWHN-89. Lấy comment này làm căn
   cứ, không sửa lại Description để không mất dấu vết bàn giao.
2. **Phạm vi**: DEV330-P1-01R3, P1-02R3, P1-03R3, P1-06R3, P1-07R3 và P2-01R3 đã có
   evidence tại `docs/evidence/WWHN-330/EVIDENCE_PACK_R3.md`.
3. **Phụ thuộc chặn Done**: WWHN-332 (QA1 chạy độc lập) và xác nhận scheduler PROD của
   DevOps. Hai mục này không chặn sign-off của DEV nhưng chặn chuyển toàn ticket sang Done.

Không phát sinh việc mới từ phía PM.

---

## 4) QA1 — 1 comment

**[QA1] Xác nhận phạm vi kiểm thử WWHN-330 / WWHN-332**

Tôi **xác nhận đã đọc** evidence R3 và `docs/evidence/WWHN-330/QA_RUNBOOK.md`, và **đồng ý
phạm vi test độc lập** sẽ chạy trên đúng SHA `<SHA-BE>` / `<SHA-FE>` nhánh `m1`, gồm 6
nhóm: (1) file lỗi CSV — schema §13.12, sanitize/che PII, hạn dùng logic; (2) retention vật
lý — `import:purge-error-files` dry-run/chạy thật/idempotent, file mồ côi, bản ghi audit,
lịch chạy; (3) biên hạn mức 1000/1001/sparse/20k + file 10 MB thật + đo hiệu năng trên môi
trường production-like; (4) idempotency — cùng/khác actor, cùng/khác `update_existing`, kèm
đối chiếu `COUNT(*)` trước–sau và checksum; (5) tương tranh — 2 request đồng thời, kỳ vọng
đúng một thành công, ca 409 không ghi gì; (6) RBAC/data-scope — ma trận 9 dòng 200/403/404
chéo người dùng.

Tôi **chưa ký nghiệm thu kết quả** — WWHN-332 giữ nguyên trạng thái đang chờ chạy.

---

## 5) DEV — 1 comment

**[DEV] Xác nhận bàn giao WWHN-330 register R3**

Tôi **xác nhận đã hoàn thành và tự kiểm chứng** trên nhánh `m1` (SHA `<SHA-BE>` /
`<SHA-FE>`):

- **P1-01R3**: gỡ hằng số hết hạn chết; tách hạn dùng logic khỏi retention vật lý; thêm
  quét file mồ côi + audit lần purge; file lỗi theo schema SRS §13.12.
- **P1-02R3**: gỡ bản sao hạn mức 10 MB/đuôi file còn sót ở tầng service; đưa chính sách
  quét dòng vật lý vào hợp đồng và công bố ra `constraints`; benchmark thêm biên
  1001/sparse/oversize, sinh `BENCHMARK_RAW.json`, mô tả môi trường suy từ tiến trình thật;
  thêm ngân sách hiệu năng có assert.
- **P1-03R3 / P1-06R3**: định danh replay gồm tùy chọn; data-scope enforce cho cả 3 route.
- **P1-07R3**: khoá import **fail-closed** → 409 `CONCURRENCY_CONFLICT`; FE nhận diện riêng
  mã này, giữ nguyên kết quả kiểm tra thử để người dùng bấm lại ngay.
- **Tự báo 3 lỗi của chính bản trước đã sửa**: DEC-IMP-001 Rev.1 trích số benchmark không
  khớp artifact; artifact tự mâu thuẫn sqlite/pgsql; hạn mức bị nhân bản giữa hai tầng.
- **Test đã chạy**: BE full suite **761 test — 734 pass, 0 fail, 27 skip**; bộ R3 mới
  14/14; benchmark 6/6; regression import 44/44. FE typecheck/lint/test/build pass.

Bàn giao cho **QA1 (WWHN-332)** kiểm thử độc lập theo `QA_RUNBOOK.md`.

---

## 6) DevOps — 1 comment

**[DevOps] Xác nhận vận hành WWHN-330**

Tôi **xác nhận 2 điểm**:

1. **Scheduler**: `import:purge-error-files` đã đăng ký trong `routes/console.php`, chạy
   hằng ngày theo giờ trong `warehouse.sku_import.error_file_purge_daily_at`
   (`withoutOverlapping`, `onOneServer`). Tôi xác nhận **Laravel scheduler đang chạy thật
   trên PROD** — nếu không, hợp đồng retention vật lý (≤ 48 giờ) đúng trong code nhưng sai
   trong thực tế.
2. **Cấu hình**: các biến `SKU_IMPORT_*` (hạn mức, retention, tham số khoá) đã có giá trị
   mặc định an toàn trong `config/warehouse.php`; PROD **không** đặt
   `SKU_IMPORT_LOCK_ON_TIMEOUT=FAIL_OPEN` — hạ tiêu chuẩn toàn vẹn dữ liệu phải là quyết
   định có văn bản, không phải cấu hình ngẫu nhiên.

Không phát sinh việc mới từ phía DevOps.
