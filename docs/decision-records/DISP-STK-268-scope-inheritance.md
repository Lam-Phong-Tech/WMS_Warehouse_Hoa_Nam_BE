# DISP-STK-268 — WWHN-70/268 kế thừa disposition Stocktake đã có, không mở lại tranh chấp mới

| Trường | Giá trị |
|---|---|
| Disposition ID | `DISP-STK-268` |
| Version | `1.0.0` |
| Jira chính | `WWHN-268` — FE closure của vòng sửa lỗi/retest `WWHN-70` / `HN-QA-005-ST01` |
| Jira liên đới | `WWHN-70` (parent), `WWHN-264` (QA1), `WWHN-265` (QA2), `WWHN-266`/`WWHN-267` (BE1/BE2) |
| Review nguồn | WWHN-268 round 2, finding `FE268-R2-P1-01` |
| Status | **PROPOSED — chưa có effective date; cần sign-off ghi trên Jira** |
| Repository | `WMS_Warehouse_Hoa_Nam_BE` + `WMS_Warehouse_Hoa_Nam_FE`, nhánh `m1` |

> Tài liệu này **không** tạo ra một tranh chấp phạm vi mới, và **không** phát
> minh lại lập luận Stocktake. Nó chỉ làm việc mà review round 2 chỉ ra là còn
> thiếu: **nối tường minh** `WWHN-70`/`WWHN-268` vào họ disposition Stocktake
> đã tồn tại, để reviewer không còn đọc việc "FE closure loại Stocktake khỏi
> phạm vi" như một khoảng trống chưa xử lý.

## 1. Vấn đề (nguyên văn review)

> "Parent có Stocktake/kiểm kê nhưng current FE closure loại Stocktake khỏi
> MVP" — yêu cầu "BA/PM/QA formal disposition exact Beta scope; update
> parent/source hoặc pin Decision/CR".

`WWHN-70` là parent epic của vòng sửa lỗi/retest QA (`HN-QA-005-ST01`), kế
thừa toàn bộ finding gốc của đợt UAT, trong đó có ít nhất một mục về kiểm kê.
`WWHN-268` (đóng phần FE) không tạo bất kỳ màn/route/permission kiểm kê nào —
điều này đúng theo baseline, nhưng trước round 2 chưa có tài liệu nào nói rõ
**tại sao đúng**, nên review đọc đó như một khoảng trống.

## 2. Không phải tranh chấp mới — đã có 4 disposition anh em phủ toàn bộ "kiểm kê"

| Disposition | Nhánh ticket | Phạm vi | Status |
|---|---|---|---|
| `DISP-STK-230` | `WWHN-230 / 58 / 232` | Core: session/snapshot/cutoff/variance | PROPOSED |
| `DISP-STK-233` | `WWHN-59 / 233 / 235` | Count / Recount / Partial / Stale | DEFERRED (đã đóng) |
| `DISP-STK-236` | `WWHN-236` | Apply Adjustment | DEFERRED (đã đóng) |
| `DISP-STK-237` | `WWHN-237 / 238 / 60` | Variance report | DEFERRED / Option A no-code (đã đóng) |

Bốn nhánh này cộng lại phủ **toàn bộ bề mặt nghiệp vụ "kiểm kê"** mà một QA
sweep có thể tìm ra: session, snapshot, cutoff, count, recount, partial/stale,
apply-adjustment, variance report. `WWHN-70`/`WWHN-268` không giới thiệu một
bề mặt kiểm kê nào nằm ngoài bốn nhánh trên, nên không cần một Option/lập luận
Stocktake thứ năm — chỉ cần trỏ đúng vào authority đã có.

## 3. Authority không đổi

Cùng một authority chain với `DISP-STK-230` §2 (không lặp lại nguyên văn ở
đây): `REQ-SRS-037` (`Must`) trong SRS/FRD v1.12, adopted tại `WWHN-334`
(Done); corroborate bởi ERD §0.1, Wireframe MVP (0 occurrence kiểm kê),
Techstack/Dev Guide, và `forbidden_path_fragments`/`excluded_tables` trong
baseline contract JSON. Đường sửa sai hợp lệ vẫn là **Reversal append-only**
(`DEC-REV-001`, `BR-REV-01/02`), không phải adjustment trực tiếp.

## 4. Kết luận cho WWHN-70/268

1. `WWHN-268` (FE) và phần BE tương ứng (`WWHN-266`/`WWHN-267`) **đúng** khi
   không tạo bề mặt Stocktake — hành vi này là hệ quả bắt buộc của
   `REQ-SRS-037`, không phải một lựa chọn cục bộ của FE1/BE1.
2. Nếu retest của `WWHN-264`/`WWHN-265` tìm thấy một finding kiểm kê **không**
   nằm trong bốn nhánh ở §2 (nghĩa là một khả năng kiểm kê thật sự mới), finding
   đó phải được định tuyến vào đúng nhánh tương ứng hoặc tạo nhánh mới theo
   đúng khuôn `DISP-STK-*`, không được tự đóng trong `WWHN-268`.
3. Sign-off cho tài liệu này **không** thay sign-off riêng của `DISP-STK-230`
   (vẫn `PROPOSED`, xem `DISP-STK-230-APPROVAL.json`). BA Main/PM/QA có thể ký
   một lần cho cả họ disposition thay vì ký lặp lại bốn lần — xem §5.

## 5. Sign-off cần ghi trên Jira

```text
DISP-STK-268 version: 1.0.0
Kết luận: WWHN-70/268 kế thừa DISP-STK-230/233/236/237; không có bề mặt kiểm kê
  nào ngoài bốn nhánh đã dispositioned; REQ-SRS-037 là authority duy nhất.
BA Main: <APPROVE | ngày / comment URL>
PM/Approver: <APPROVE | ngày / comment URL>
QA (QA1 + QA2, đại diện WWHN-264/265): <APPROVE | ngày / comment URL>
Exact FE/BE commit SHA tại thời điểm sign-off: <sha>
```

## 6. Liên quan

`DISP-STK-230-scope-disposition.md`, `DISP-STK-230-APPROVAL.json`,
`docs/api-evidence/WWHN-268_SCOPE_DISPOSITION.json` (FE, bản đối xứng),
`docs/api-evidence/STOCKTAKE_GOVERNANCE_STATUS.json` (FE, authority dùng
chung).
