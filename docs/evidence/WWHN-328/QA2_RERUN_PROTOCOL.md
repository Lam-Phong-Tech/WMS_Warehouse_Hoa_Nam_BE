# WWHN-328 → WWHN-329 — Giao thức chạy lại cho QA2 (DEV328-P1-06R4)

Đây là **hard gate** của register R4. Tài liệu này không thay QA2 chạy; nó bỏ đi mọi lý do
khiến lượt chạy trước không dùng được: không rõ chạy trên build nào, không rõ "đúng" là số
nào, và verdict để dạng lựa chọn kép.

## 1. Cặp build phải chạy (không chạy trên `HEAD` của `m1`)

| Thành phần | Repo | Branch | Exact SHA |
|---|---|---|---|
| Backend | `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `b610b1ff191f8c1f2cce3c24571de850f946d690` |
| Frontend | `WMS_Warehouse_Hoa_Nam_FE` | `m1` | `16a3eea9f5241ba3ca24e36c53c811a45f9ae45d` |

```bash
git -C HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE checkout b610b1ff191f8c1f2cce3c24571de850f946d690
git -C HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE checkout 16a3eea9f5241ba3ca24e36c53c811a45f9ae45d
```

Trước khi kiểm thử, xác nhận đúng build (đỏ = dừng, không kiểm thử tiếp):

```bash
node scripts/verify-wwhn-328-closure.mjs
```

## 2. Dữ liệu và tài khoản

| Vai | Yêu cầu | Kỳ vọng `data_scope` |
|---|---|---|
| U-GLOBAL | role `scope_type=SYSTEM`, có `report.view` | `GLOBAL`, `scope_warehouse_ids=null` |
| U-SCOPED | role `scope_type=WAREHOUSE`, `scope_ref` = **1 kho**, có `report.view` | `WAREHOUSE_SCOPED`, list đúng 1 kho |
| U-NOPERM | không có `report.view` | 403 `ACCESS_DENIED` |

Dữ liệu tối thiểu: **hai kho** đều có phát sinh nhập/xuất trong kỳ, và **ít nhất một dòng
MISMATCH** ở mỗi kho (tạo lệch bằng một `inventory_balances` không có movement đối ứng).
Không có kho thứ hai thì mọi khẳng định data-scope đều vô nghĩa.

## 3. Danh mục phải chạy — mỗi mục có một số đúng, không có "quan sát chung"

| # | Hạng mục R4 | Bước | PASS khi |
|---|---|---|---|
| T1 | totals | U-GLOBAL gọi `GET /api/v1/reports/dashboard-quick` | mỗi KPI khớp công thức KPI matrix v1.2 §2 trên dữ liệu test |
| T2 | scope | U-SCOPED gọi cùng endpoint cùng kỳ | `data_scope=WAREHOUSE_SCOPED`; `receipt/issue/available` **nhỏ hơn** T1; không lộ `warehouse_code` ngoài scope trong `reconciliation_warnings` |
| T3 | scope 403 | U-SCOPED gọi `?warehouse_id=<kho ngoài scope>` | `403`, `error.code=ACCESS_DENIED` |
| T4 | cache isolation | T1 rồi T2 **liền nhau trong TTL**, sau đó lặp lại theo thứ tự ngược | hai payload luôn khác nhau ở cả hai thứ tự; không lần nào U-SCOPED nhận số của U-GLOBAL |
| T5 | cache không nuốt 403 | chạy T1 (làm nóng cache) rồi ngay T3 | vẫn `403` — không phải `200` từ cache |
| T6 | timezone/boundaries | tạo movement đúng `from_date 00:00:00` và `to_date 23:59:59`; gọi với kỳ đó | cả hai bản ghi **được tính**; kỳ mặc định (bỏ trống) = 31 ngày, `Asia/Ho_Chi_Minh` |
| T7 | 401 | gọi không kèm token | `401` `UNAUTHENTICATED` |
| T8 | 403 permission | U-NOPERM gọi | `403` `ACCESS_DENIED` |
| T9 | 422 | `to_date` < `from_date`; và `from_date=2026-08-01T10:00:00` | `422` `BUSINESS_RULE_ERROR`; case thứ hai **không** được trả KPI = 0 |
| T10 | reconciliation | so `reconciliation_warning_count` với `summary.mismatch_count` của `GET /reports/inventory-balance` **cùng tài khoản** | bằng nhau, với cả U-GLOBAL và U-SCOPED (bất biến I1–I3, KPI matrix §7) |
| T11 | warranty | so `warranty_cases_by_status` giữa U-GLOBAL và U-SCOPED | **bằng nhau** — ngoại lệ GLOBAL đã ký; khác nhau là **FAIL** |
| T12 | FE integration | mở Dashboard bằng U-SCOPED trên 1440x900 / 768x1024 / 375x812 | hiện nhãn phạm vi "giới hạn N kho" **và** khối `role="note"` khai ngoại lệ bảo hành; số trên UI khớp payload |

T11 dễ bị chấm ngược: người kiểm thử thấy "user giới hạn kho mà số bảo hành vẫn toàn hệ
thống" và ghi defect. Đó là **hành vi đúng đã ký** (ERD `warranty_case` không có
`warehouse_id`) và FE có khai ngoại lệ trên màn hình — xem KPI matrix §1.

## 4. Hình thức báo cáo trên WWHN-329

- Đăng trên **WWHN-329**, không đăng trên WWHN-328/327.
- Ghi: cặp SHA ở §1, môi trường (URL BE/FE, DB), trình duyệt + phiên bản, bộ dữ liệu dùng.
- Bảng kết quả T1–T12, mỗi dòng PASS hoặc FAIL kèm số đo thật.
- **Một** verdict duy nhất ở cuối. Comment còn dạng `PASS / FAIL` là chưa ký.
