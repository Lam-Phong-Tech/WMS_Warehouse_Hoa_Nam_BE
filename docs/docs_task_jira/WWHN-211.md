# WWHN-211 — FE1 Triển khai danh sách/chi tiết/trace tồn kho

## 1. Trạng thái tổng quan

| Trường | Giá trị |
| --- | --- |
| Task | `WWHN-211` |
| Parent Temp Key | `HN-FE-005` (task cha `WWHN-51`) |
| Epic Temp Key | `HN-EPIC-07` |
| Dependency | `HN-INV-001` |
| Role | FE1 |
| Branch | `m1` |
| Trạng thái code | **Đã triển khai đầy đủ UI + tích hợp API**, chưa có evidence QA/test riêng cho luồng này |
| Trạng thái test | typecheck PASS, lint PASS (`src/modules/inventory`); **chưa có unit/component test và chưa có e2e riêng cho tồn kho** |
| Đánh giá còn thiếu | Test coverage, evidence responsive/RBAC UI, xác nhận field-mapping với BE cho `subledger`/`trace-by-code` |

## 2. Đã hoàn thành

### 2.1 Danh sách tồn kho (INV-01)

`src/modules/inventory/pages/InventoryPage.tsx`:

- Tab "Tồn kho hiện tại": bảng balance với SKU, tên hàng, kho, lô, Available/On-hand/Reserved/In-transit, Item/Box subledger, vị trí gợi ý, trạng thái đối soát (MATCH/MISMATCH), cập nhật gần nhất.
- Search debounce 250ms, filter theo trạng thái tồn (normal/low_stock/overflow), phân trang qua `resolveApiPagination` + `MvpPageSizeSelector`.
- Tab "Lịch sử dịch chuyển": bảng movement với mã dịch chuyển, thời gian, loại (RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL), SKU, kho, số lượng thay đổi (màu theo dấu), nguồn chứng từ, người thao tác, nút Trace.
- Xử lý loading (`isLoading || isFetching`), error (`ApiErrorAlert` + retry), empty state riêng cho từng bảng.
- Đồng bộ qua query param `?screen=TRACE-01&id=...` để mở modal trace trực tiếp từ nơi khác (điều hướng liên module).
- RBAC: nút Trace và "Truy vết mã" chỉ hiện/enable khi có quyền `inventory.trace` (`useCanAny`), tooltip giải thích lý do disable khi thiếu quyền.

### 2.2 Chi tiết tồn kho — `BalanceDetailModal`

`src/modules/inventory/components/BalanceDetailModal.tsx`:

- Descriptions: SKU, tên hàng, kho, lô, đối soát (kèm ghi chú mismatch), cập nhật, vị trí gợi ý, movement gần nhất.
- Statistic: Available/On-hand/Reserved/In-transit/Blocked-Hold/Serial qty/Box qty.
- Item Subledger + Container Subledger (bảng riêng, gọi `GET /inventory/balances/:id/subledger`), có loading/error/empty riêng.
- Movement liên quan (lấy theo SKU, `per_page=10`), có nút Trace mở thẳng `TraceModal`.

### 2.3 Truy vết — `TraceModal` (TRACE-01)

`src/modules/inventory/components/TraceModal.tsx`:

- Truy vết theo `movementId`: gọi song song `GET /stock-movements/:id`, `GET /stock-movements/:id/trace-source`; hiển thị chi tiết movement, chứng từ nguồn (loại, mã, trạng thái, actor, request_id), dòng chứng từ, Timeline chuỗi trạng thái.
- Tra cứu độc lập theo mã vật lý (barcode/QR/RFID) qua `GET /stock-movements/trace-by-code`: resolve ITEM/CONTAINER/SKU, hiển thị container items và lịch sử movement liên quan.
- Loading/error/empty đầy đủ cho từng phần (`ApiErrorAlert` + retry, "Backend chưa trả timeline/chain" khi timeline rỗng).

### 2.4 Service & hook layer

- `src/modules/inventory/services/inventoryService.ts`: normalize dữ liệu balance/movement/trace-source/trace-by-code từ nhiều biến thể field BE có thể trả (fallback chain theo từng field — ví dụ `available_qty ?? qty_available ?? available`), tránh hard-code một contract duy nhất.
- `src/modules/inventory/hooks/use-inventory.ts`: React Query hooks cho balances, movements, movement detail, trace-source, subledger, trace-by-code, key theo params để cache đúng.
- Endpoint đã khai báo trong `src/shared/api/endpoints.ts`: `INVENTORY_BALANCES`, `INVENTORY_BALANCE_SUBLEDGER`, `STOCK_MOVEMENTS`, `STOCK_MOVEMENT`, `STOCK_MOVEMENT_TRACE_SOURCE`, `STOCK_MOVEMENT_TRACE_BY_CODE`.

### 2.5 Routing & permission

- Route `ROUTES.INVENTORY` lazy-load `InventoryPage`, gate bằng `P.INVENTORY_VIEW` (`inventory.view`) ở `src/app/router/access.ts`.
- Menu điều hướng (`SideNav`/`menu.tsx`) đã có mục tồn kho, mapping `screen = INV-01`.
- Quyền `inventory.trace` dùng để gate hành động Trace ở tầng UI; đúng pattern permission hiện có trong `wms-permissions.ts`.

## 3. Còn thiếu / cần làm thêm

| Hạng mục | Mô tả | Mức độ |
| --- | --- | --- |
| Unit/component test | Không có file test nào cho `src/modules/inventory/**` (`*.test.*` = 0 kết quả). Các module khác (auth, router, stocktake...) đều có test đi kèm; module inventory hiện là ngoại lệ. | **P1 — chặn AC "lint, build và test phù hợp phải pass"** |
| E2E | Không tìm thấy kịch bản e2e nào cho luồng tồn kho (glob `e2e/**/*inventory*` rỗng). BA/QA sẽ khó có evidence tự động cho responsive/RBAC theo `[[hoanam-fe-test-harness]]`. | **P1** |
| Xác minh field-mapping với BE thật | `normalizeBalance`/`normalizeMovement`/`normalizeTraceSource` dùng fallback nhiều tên field cùng lúc (dấu hiệu contract chưa chốt 100% hoặc đang phòng thủ cho nhiều phiên bản BE). Cần đối chiếu lại với `HN-INV-001`/Swagger hiện hành để xác nhận field nào là canonical, field nào là dự phòng thừa. | P2 — theo nguyên tắc `[[hoanam-wms-project-layout]]` (ERD parity + Swagger summary) |
| `check:api-mapping` | Chưa chạy để xác nhận 4 endpoint tồn kho khớp `swagger-api.json` ở chế độ strict, theo `[[hoanam-fe-test-harness]]`. | P2 |
| Evidence responsive/RBAC | Chưa có screenshot/RTM cho 5 viewport hoặc log RBAC (ẩn/disable nút Trace) để bàn giao BA/QA theo AC "gắn evidence vào HN-FE-005". | P1 |
| Safe-stock alerts UI | Service đã có `fetchSafeStockAlerts`/`fetchSafeStockConfigs`/`createSafeStockConfig` nhưng **không có UI nào sử dụng** trong `InventoryPage`/modal hiện tại — cần xác nhận đây có thuộc phạm vi WWHN-211 hay để task khác, tránh dead code hoặc scope-gap. | Cần làm rõ với BA (không tự suy diễn) |
| Scan batch/event | Tương tự, `fetchScanBatches`/`createScanBatch`/`fetchScanEvents` tồn tại trong service nhưng không thấy dùng trong module inventory — khả năng thuộc luồng Scan (`ROUTES.SCAN`) chứ không phải INV-01/TRACE-01; cần xác nhận không bị trùng lặp trách nhiệm. | Cần làm rõ với BA |

## 4. Đã kiểm tra ở lần rà soát này

```bash
npx tsc -p tsconfig.json --noEmit        # PASS, không lỗi
npx eslint src/modules/inventory --max-warnings=0   # PASS, không lỗi/cảnh báo
```

Chưa chạy: `npm run test` (unit), `npm run build`, `npm run check:api-mapping`, e2e — vì không có test file riêng cho module này để verify, và build/e2e cần xác nhận phạm vi trước khi tốn thời gian CI.

## 5. Đề xuất bước tiếp theo

1. Bổ sung unit/component test cho `InventoryPage`, `BalanceDetailModal`, `TraceModal` và `inventoryService` (normalize functions) — ưu tiên vì đây là khoảng trống rõ nhất so với AC.
2. Chạy `npm run check:api-mapping` với `swagger-api.json` hiện hành để xác nhận 6 endpoint tồn kho/trace không lệch contract.
3. Làm rõ với BA/BE1 phạm vi Safe-stock alerts UI và Scan batch UI có thuộc WWHN-211 hay không; nếu không, cân nhắc tách các hàm service chưa dùng sang module tương ứng để tránh nhầm lẫn traceability.
4. Thu thập evidence responsive (5 viewport) + RBAC (ẩn/disable Trace khi thiếu quyền) để đính kèm HN-FE-005 trước khi xin BA/QA sign-off.
5. Sau khi có test, chạy đủ `format:check`, `lint`, `typecheck`, `test`, `build` và cập nhật bảng trạng thái ở mục 1.

## 6. Ghi chú

File task này trước đó trống (không có nội dung mô tả nào được lưu); nội dung trên được viết lại từ bản mô tả task gốc (mục tiêu, input, AC, DoD, phạm vi) do người dùng cung cấp trong phiên làm việc, kết hợp với kết quả rà soát code thực tế tại thời điểm `2026-08-14`.
