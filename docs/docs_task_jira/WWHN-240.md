# WWHN-240 — Reversal net effect trong báo cáo XNT và trace chain

Nhánh: `m1` · Ngày: 2026-08-18 · Evidence: `docs/evidence/WWHN-240/`

| Repo | Exact SHA |
|---|---|
| BE | `9640091cf780fce1ef54102f6955971a57c5678b` (ancestor bắt buộc `c855851b37920237d110f7dffe5dbbad509aebce`) |
| FE | `79474b8c6cd873f26bc6b42ca1bd9af2171ce7cf` |

---

## 1. Trạng thái từng mục trong register

| ID | Sev | Trạng thái | Bằng chứng |
|---|---|---|---|
| DEV240-P1-01 | P1 | **DEV phần đã xong — chờ Decision Owner transition** | `docs/decision-records/DISPOSITION-WWHN-239-REVERSAL-CANDIDATE.md`, `DEC-REV-001` §13 |
| DEV240-P1-02A | P1 | **Đóng** | `OperationalReportController::buildXnt()` + `xntReversalBreakdown()` + `resolveCorrectionRootTypes()`; 6 test |
| DEV240-P1-02B | P1 | **Đóng** | `StockMovementController::traceSource()` + `correctionChain()`; 5 test |
| DEV240-P1-02C | P1 | **Đóng** | `app/Swagger/ReversalNetEffectSchemas.php`, `config/l5-swagger.php`, `openapi-wwhn240-slice.json` |
| DEV240-P1-03 | P1 | **Đóng** | Manifest §1 (SHA + remote + branch + lưu ý attribution) |
| DEV240-P1-04 | P1 | **Đã giao QA1** | `docs/evidence/WWHN-240/QA1_RUNBOOK_WWHN-241.md` (20 TC) |
| DEV240-P1-05 | P1 | **Đóng phía DEV/FE — chờ comment BA/QA** | FE `79474b8`; 3 test FE mới; toàn suite 578/578 |
| DEV240-P2-01 | P2 | **Đóng** | §5 dưới đây |

## 2. Lỗi gốc và cách sửa

Xem chi tiết ở `docs/evidence/WWHN-240/BE_CHANGE_MANIFEST.md` §2. Tóm tắt:

1. **XNT gộp hai chiều reversal vào một cột** ⇒ một kỳ "đảo hết nhập và đảo hết xuất" hiển
   thị y hệt một kỳ không có reversal. Đã tách gross / reversal / net theo `movement_type`
   của giao dịch **gốc**, không theo dấu `qty_delta`.
2. **Trace chỉ trả một reversal** (`->first()`) ⇒ đảo từng phần lần hai trở đi biến mất.
   Đã trả correction chain đầy đủ + `net_effect` tính từ ledger.
3. **OpenAPI trace chưa từng được publish** vì `StockMovementController.php` không nằm
   trong danh sách scan của `config/l5-swagger.php` — cùng lớp lỗi với WWHN-184. Đã thêm
   vào scan và khoá schema + 403.

## 3. Cam kết tương thích ngược

| Khoá | Trạng thái |
|---|---|
| `data.formula` (XNT) | giữ **nguyên chuỗi** cũ |
| `receipt` / `issue` / `component_issue` | giữ nguyên nghĩa GROSS, thành bí danh của `*_gross` |
| `reversal_net` | giữ nguyên nghĩa (tổng có dấu), nay tái lập được từ 4 cột con |
| `original_movement` / `reversal_movement` (trace) | giữ nguyên hình dạng, đánh dấu DEPRECATED trong OpenAPI |

## 4. Giới hạn đã khai báo

- `MAX_CORRECTION_CHAIN_DEPTH = 32`. Chain sâu hơn trả `correction_chain.truncated = true`
  thay vì âm thầm cắt bớt. FE hiển thị cảnh báo.
- `net_effect.remaining_reversible_qty` là **con số hiển thị** tính từ ledger. Guard khi
  Post Reversal vẫn revalidate trong transaction theo BR-REV-05/BR-REV-09 — hai con số có
  thể lệch trong khoảnh khắc có request cạnh tranh, và guard mới là nơi quyết định.
- `storage/api-docs/api-docs.json` không nằm trong commit này (xem manifest §2).

## 5. DEV240-P2-01 · Reconcile trace metadata

| Nội dung | Ghi nhận |
|---|---|
| Wording lịch sử "Duy Khánh / BE2" trong Description | Là **lịch sử phân công**, không phải ownership hiện hành |
| Ownership hiện hành | Thực thi trên nhánh `m1`, phát hành tại `9640091` (BE) / `79474b8` (FE) |
| Business trace key | **Không đổi**: `WWHN-26 → DEC-REV-001 → WWHN-239 → WWHN-240 → WWHN-241` |
| Cách xử lý | Ghi chú bằng comment trên Jira, **không** sửa Description gốc, **không** đổi trace key |

Lý do không sửa Description: Description là dấu vết lịch sử của quyết định phân công; sửa
đè sẽ làm mất khả năng đối chiếu ngược với các task cùng đợt. Comment là nơi đúng để đính
chính ownership.
