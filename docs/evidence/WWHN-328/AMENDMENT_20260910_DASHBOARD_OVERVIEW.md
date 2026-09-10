# WWHN-328 — Amendment AMD-001: `GET /api/v1/reports/dashboard-overview` (ghi bù 10/09/2026)

Bản ghi máy đọc: [`AGGREGATE_ROUTE_AMENDMENTS.json`](AGGREGATE_ROUTE_AMENDMENTS.json).

## 1. Vì sao có amendment này

AC của WWHN-328 chốt dashboard **tái sử dụng** endpoint aggregate sẵn có — `GET /api/v1/reports/dashboard-quick`
— và không tạo route mới. Hai cổng khoá mệnh đề đó:

- `tests/Feature/Wwhn328R4ClosureTest.php::test_dashboard_aggregate_is_served_by_exactly_one_reused_route`
- `scripts/verify-wwhn-328-closure.mjs` mục 5 (workflow `wwhn-328-dashboard-closure.yml`)

`GET /api/v1/reports/dashboard-overview` được thêm ở `b6d9a34` (30/08/2026, "feat(api): public storefront API
(catalog, quote requests) + fixes [skip ci]") mà không có amendment. Commit `[skip ci]` nên cả hai cổng đỏ từ đó
mà không ai thấy. Route này đang có consumer thật: màn Tổng quan của FE
(`src/modules/dashboard/overview/overview-data.ts`) và kịch bản UAT TC_UI_01.

## 2. Nội dung amendment

| Trường | Giá trị |
| --- | --- |
| Mã | `WWHN-328-AMD-001` |
| Route | `GET|HEAD api/v1/reports/dashboard-overview` |
| Handler | `OperationalReportController@dashboardOverview` |
| Permission | `permission:report.view` (không đổi trong lần ghi bù) |
| Commit đưa vào | `b6d9a34` (30/08/2026) |
| Quan hệ với `dashboard-quick` | Bổ sung, không thay thế. `dashboard-quick` giữ nguyên route, permission, field set và fingerprint trong `CONTRACT_PIN.json` |
| Chủ sở hữu | BA + Tech Lead |
| Sign-off | **PENDING** |

## 3. Cổng vẫn khoá "không thêm route dashboard ngoài tập đã pin"

Hai cổng nay so tập route dashboard ở runtime với **đúng** tập `originalRoute` + `amendments[].route` của
`AGGREGATE_ROUTE_AMENDMENTS.json`, thay vì con số 1 cứng. Một route dashboard thứ ba không có amendment vẫn đỏ ở
cả hai. Tên test PHPUnit giữ nguyên vì `R4_CLOSURE.md` và mục 3 của `verify-wwhn-328-closure.mjs` viện dẫn đúng
tên đó.

## 4. AppSec — cần BA quyết định (cổng KHÔNG đổi ở đây)

- **Cổng hiện tại:** chỉ `permission:report.view`. Từ 07/09/2026 báo cáo đã tách 9 mã `report.<màn>.view`
  (DELTA-07 trong `docs/evidence/WWHN-267/ROLE_BASELINE_PIN.json`), nhưng hai endpoint dashboard cố ý giữ
  `report.view` để dashboard của các vai không bị trắng.
- **Dữ liệu tổng hợp:** KPI/chuỗi/xu hướng/cơ cấu tồn và top SKU theo data-scope kho (tầm RPT-01/01B/02); số ca
  bảo hành theo trạng thái, số ca quá hạn và mẫu tối đa 50 ca quá hạn (tên khách luôn mask) — **GLOBAL**, không
  lọc kho (tầm RPT-06); bộ đếm vấn đề (lệch tồn, lỗi import, in nhãn lỗi, SKU chờ bổ sung thông tin).
- **Phơi bày theo vai:** `WAREHOUSE_STAFF` và `VIEWER` không có `warranty.view` (bảng đo §4.1 tài liệu bàn giao
  BA: màn Bảo hành 403) nhưng thấy mã ca, trạng thái, hạn và số ca quá hạn; `WARRANTY_STAFF` không có màn
  RPT-01/01B nhưng thấy KPI luồng nhập–xuất và top SKU. Không lộ PII dạng rõ.
- **Câu hỏi cho BA:** (a) chấp nhận Dashboard tổng quan là cross-domain cho mọi vai có `report.view` và ghi vào
  DELTA-07; hoặc (b) lọc từng khối theo quyền trong builder (khối bảo hành chỉ khi có `warranty.view` hoặc
  `report.warranty.view`; khối luồng chỉ khi có `report.inventory_flow.view`/`report.outbound_flow.view`), kèm
  biến thể quyền trong khoá cache.
- Chi tiết rà soát: `docs/security/ROUTE_CONTRACT_DELTA_20260910.md`, mục E7.

## 5. Sign-off

| Người duyệt | Mục | Trạng thái |
| --- | --- | --- |
| BA | Amendment AMD-001 (route bổ sung cho Dashboard tổng quan) và câu hỏi quyền ở §4 | PENDING |
| Tech Lead | Amendment AMD-001, việc hai cổng chuyển sang so với tập đã pin | PENDING |
