# WMS_Hoa_Nam_Techstack_Dev_Guide

|  |  |
|---|---|
| Được tạo bởi | QuangDuc_LPT |
| Thời gian tạo | 26 tháng 7, 2026 |
| Danh mục | Lập kế hoạch Tài liệu chiến lược |
| Chỉnh sửa gần nhất bởi | QuangDuc_LPT |
| Lần cập nhật gần nhất | 26 tháng 7, 2026 |

# WMS Technical Stack & Implementation Guide

> Tên file đề xuất: WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md
>
> Phạm vi: Tài liệu định hướng kỹ thuật cho đội Dev, BA, Tester và các bạn liên quan đến hệ thống WMS Hoa Nam.
>
> Techstack đề xuất: Laravel API + PostgreSQL + Valkey + React/Vite/TypeScript + Docker Compose + Postman/Jira
>
> Định hướng kiến trúc: Modular Monolith trước, tách service sau khi nghiệp vụ đủ lớn.

## * Mục tiêu tài liệu

Tài liệu này dùng để thống nhất cách triển khai kỹ thuật cho hệ thống WMS Hoa Nam, tránh tình trạng mỗi người code một kiểu, chia module không rõ ràng hoặc API không thống nhất giữa Dev/Test/BA.

Tài liệu phục vụ cho:

| Nhóm sử dụng | Mục đích sử dụng |
|---|---|
| Backend Dev | Nắm kiến trúc Laravel, module, database, transaction, cache, queue, lock |
| Frontend Dev | Nắm stack React/TypeScript, API contract, UI/data fetching/form/table/scan/dashboard |
| Tester | Nắm flow kiểm thử API, Postman collection, scenario nghiệp vụ, regression test |
| BA/PM | Nắm module hệ thống, phạm vi MVP, thứ tự ưu tiên và ranh giới nghiệp vụ |
| DevOps/Tech Lead | Nắm môi trường local, Docker Compose, logging, monitoring, storage |

# 1. Kiến trúc tổng thể nên dùng

![](WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide_assets/architecture-000.png)

## 1.1. Mô hình tổng quan

Mô hình tổng quan WMS Hoa Nam giữ một API Core Laravel theo hướng Modular Monolith, Web Admin React/Vite/TypeScript ở phía frontend, App PV read-only tách luồng xem tồn/sản phẩm, và toàn bộ môi trường local được chuẩn hóa bằng Docker Compose.

## 1.2. Định hướng kiến trúc

| Thành phần | Công nghệ | Vai trò |
|---|---|---|
| Web Admin WMS | React + Vite + TypeScript | Giao diện quản trị WMS cho nội bộ |
| App PV | React SPA read-only | Xem sản phẩm published và available_qty, không đặt hàng, không giữ hàng, không đổi tồn |
| API Core WMS | Laravel | Backend nghiệp vụ chính |
| Kiến trúc backend | Modular Monolith | Chia module rõ nhưng vẫn giữ chung codebase giai đoạn đầu |
| Database chính | PostgreSQL | Lưu dữ liệu nghiệp vụ, transaction, audit, báo cáo |
| Cache/Queue/Lock | Valkey | Cache permission/report nhẹ, queue, distributed lock, rate limit |
| File storage | S3-compatible / MinIO / local | Lưu ảnh sản phẩm, chứng từ, file import/export, báo cáo |
| Local environment | Docker Compose | Đồng bộ môi trường local cho Dev/Test |
| API testing | Postman / Newman | Kiểm thử API contract và scenario nghiệp vụ |
| Quản lý công việc | Jira | Quản lý Epic, Story, Task, Bug, Sprint |

## 1.3. ReactJS hay NextJS cho WMS Hoa Nam?

| Tiêu chí | React + Vite | NextJS |
|---|---|---|
| SEO | Không tối ưu SEO mặc định nhưng không cần cho Web Admin nội bộ | Mạnh nếu có public site/catalog cần SEO |
| Maintain nội bộ | Nhẹ, rõ, ít ràng buộc framework, dễ onboard intern/dev | Nhiều convention hơn, dễ dư SSR/server layer nếu không dùng |
| Routing/admin CRUD | Phù hợp với React Router | Dùng được nhưng không tạo lợi thế lớn |
| Scan/operation UI | Tốt cho SPA realtime-ish, form/table/scan flow | Không cần SSR cho scan flow |
| Kết luận | Nên chọn cho Web Admin WMS | Chỉ cân nhắc cho public PV/catalog nếu phát sinh SEO |

**Kết luận kỹ thuật: WMS Hoa Nam nên chốt React + Vite + TypeScript cho Web Admin. NextJS không sai, nhưng hiện chưa tạo giá trị đủ lớn vì hệ thống không cần SEO nhiều và trọng tâm là vận hành kho nội bộ.**

## 1.4. Future services

Giai đoạn đầu chưa nên tách service quá sớm. Khi nghiệp vụ đã rõ và tải lớn hơn, có thể bổ sung:

| Service tương lai | Công nghệ gợi ý | Khi nào dùng |
|---|---|---|
| Notification / Realtime Service | Laravel Reverb/Echo hoặc NestJS WebSocket | Khi notification/chat/realtime phức tạp |
| MISA Sync Worker | Laravel Queue worker hoặc service riêng | Khi có API/file đối soát chính thức |
| RFID Gateway Service | NestJS/FastAPI tùy thiết bị | Khi triển khai RFID thật |
| BI/Analytics Warehouse | PostgreSQL replica / ClickHouse / BigQuery tùy scale | Khi dashboard/report nặng ảnh hưởng DB chính |

# 2. Nguyên tắc kiến trúc bắt buộc

## 2.1. Không code lẫn lộn controller/service/model

Backend không nên để toàn bộ logic nằm trong controller. Controller chỉ nhận request, validate, gọi service/use case và trả response.

Luồng xử lý khuyến nghị:

```text
Route → Controller → Request Validation → Service/UseCase → Repository/Model → Database
                        ↓
                    Policy/Data Scope
                        ↓
                Event/Job/Audit/Movement Log
```

## 2.2. Backend phải enforce phân quyền và data scope

Frontend chỉ dùng để hiển thị. Tất cả logic bảo vệ dữ liệu phải nằm ở backend:

- User có role nào thì chỉ được thao tác trong quyền đó.
- App PV chỉ đọc dữ liệu published và available_qty.
- API phải tự check warehouse_id, role, action, scope nếu nghiệp vụ yêu cầu.
- Không tin dữ liệu filter từ frontend gửi lên nếu chưa kiểm tra scope.

## 2.3. Transaction, lock và idempotency là bắt buộc

Các action làm đổi tồn như Post Receipt, Post Issue, Apply Adjustment, Reverse phải chạy trong DB transaction, lock bản ghi liên quan và có idempotency_key để chống double submit.

```text
Begin transaction
→ Lock inventory_balance theo SKU/kho tổng
→ Validate lại trạng thái phiếu và tồn hiện tại
→ Ghi dữ liệu chính
→ Ghi inventory_movement append-only
→ Commit
```

Nếu lỗi ở bất kỳ bước nào thì rollback.

## 2.4. Inventory movement là nguồn audit chính

- Không sửa/xóa trực tiếp movement đã phát sinh.
- Sai thì tạo movement điều chỉnh hoặc reverse có reference.
- Báo cáo XNT phải đối soát được với movement.
- Scan/lưu nháp không làm đổi tồn; chỉ Post/Apply mới đổi tồn.

# 3. Cách chia backend theo module

Cấu trúc backend nên chia theo nghiệp vụ, không chia đơn thuần theo loại file.

```text
app/
├── Modules/
│ ├── MasterData/
│ │ ├── SKU/
│ │ ├── Item/
│ │ ├── Container/
│ │ └── Agency/
│ ├── Warehouse/
│ │ ├── Inbound/
│ │ ├── Outbound/
│ │ ├── InventoryBalance/
│ │ ├── InventoryMovement/
│ │ └── Reverse/
│ ├── Scan/
│ ├── Stocktake/
│ ├── Warranty/
│ ├── PV/
│ ├── Report/
│ └── Shared/
```

## 3.1. Mapping module WMS sang bounded context

| WMS Module | Backend bounded context | Ghi chú kỹ thuật |
|---|---|---|
| Dữ liệu nền & mã hóa | MasterData | SKU, item, carton, box, tray, physical_code, agency |
| Scan<br>QR/Barcode/RFID-ready | Scan | Classify code, duplicate flag, unknown code, scan batch |
| Nhập kho | Warehouse.Inbound | DRAFT/SCANNING/POSTED/CANCELLED, Post Receipt tăng tồn |
| Xuất kho | Warehouse.Outbound | Xuất thùng/lẻ, chọn agency nhận hàng, Post Issue giảm tồn |
| Tồn kho & Movement | InventoryBalance/Movement | available_qty, movement append-only, idempotency |
| Kiểm kê | Stocktake | Snapshot, count, variance, apply adjustment |
| Bảo hành | Warranty | Warranty case, TEMP_ONLY, trạng thái bảo hành |
| App PV | PV | Product published, filter ngành nghề, available_qty read-only |
| Báo cáo | Report | XNT, variance, warranty defect report, export |

# 4. Backend stack

## 4.1. Laravel

Laravel được dùng làm API Core cho WMS.

| Hạng mục | Đề xuất triển khai |
|---|---|
| API style | REST API trước, GraphQL chưa cần trong MVP |
| Auth | Laravel Sanctum hoặc JWT tùy quyết định team |
| Permission | Policy/Gate + custom data scope |
| Validation | Form Request |
| Business logic | Service/UseCase |
| DTO | Dùng cho input/output phức tạp giữa các layer |
| Queue | Laravel Queue + Valkey |
| Scheduler | Laravel Scheduler |
| Event | Dùng cho audit, notification, sync nhẹ |
| Testing | Pest/PHPUnit + Laravel HTTP tests |

## 4.2. API response format nên thống nhất

```json
{
    "success": true,
    "message": "Created successfully",
    "data": {},
    "meta": {
      "request_id": "uuid",
      "timestamp": "2026-07-26T00:00:00+07:00"
    }
}
```

Lỗi nghiệp vụ nên có format riêng:

```json
{
    "success": false,
    "message": "Insufficient inventory balance",
    "error_code": "WMS_INSUFFICIENT_STOCK",
    "errors": {
      "sku_id": ["Current available_qty is lower than requested quantity"]
    }
}
```

# 5. Frontend stack

Đề xuất dùng React + Vite + TypeScript trước để triển khai nhanh, rõ cấu trúc, phù hợp Web Admin WMS.

| Hạng mục | Đề xuất |
|---|---|
| Framework UI | React + TypeScript |
| Build tool | Vite |
| UI library | Ant Design kết hợp Tailwind nếu cần custom layout |
| Form | React Hook Form + Zod |
| Data fetching | TanStack Query |
| Table/Data grid | Ant Design Table hoặc TanStack Table |
| Chart | Recharts hoặc ECharts |
| State global | Zustand hoặc Redux Toolkit |
| Router | React Router |
| Unit test | Vitest |
| E2E test | Playwright |

## 5.1. Cấu trúc frontend đề xuất

```text
src/
├── app/
│ ├── router/
│ ├── providers/
│ └── layouts/
├── modules/
│ ├── master-data/
│ ├── inbound/
│ ├── outbound/
│ ├── inventory/
│ ├── stocktake/
│ ├── warranty/
│ ├── pv/
│ └── reports/
├── shared/
│ ├── api/
│ ├── components/
│ ├── constants/
│ ├── hooks/
│ ├── utils/
│ └── types/
└── assets/
```

## 5.2. Nguyên tắc frontend

- Không tự xử lý phân quyền nghiệp vụ quan trọng ở frontend.
- Mọi màn CRUD phải bám API contract từ Postman/Jira.
- Form validate frontend để tăng UX, nhưng backend vẫn validate lại.
- Table lớn phải có pagination/filter/sort từ backend.
- Màn scan phải có state rõ: waiting, scanned, duplicate, unknown, invalid, success, error.
- Các trạng thái loading/error/empty/success/permission denied phải được xử lý nhất quán.

# 6. Database stack

## 6.1. PostgreSQL

PostgreSQL là database chính vì WMS cần quan hệ dữ liệu chặt, transaction, lock, audit, báo cáo xuất nhập tồn và đối soát movement.

| Hạng mục | Đề xuất |
|---|---|
| Database chính | PostgreSQL |
| Kiểu dữ liệu ID | UUIDv7 hoặc BIGINT, team cần chốt sớm |
| Transaction | Bắt buộc cho Post Receipt, Post Issue, Apply Adjustment, Reverse |
| Index | warehouse_id, sku_id, item_id, status, code_value, created_at |
| Audit | Audit log bảng riêng + inventory_movement append-only |
| Soft delete | Có, nhưng không xóa dữ liệu đã phát sinh giao dịch |
| Report | Materialized view hoặc snapshot nếu dữ liệu lớn |

## 6.2. Core tables gợi ý

| Nhóm | Bảng chính |
|---|---|
| Master data | skus, items, containers, physical_codes, agencies |
| Inbound | inbound_documents, inbound_lines, inbound_scan_batches |
| Outbound | outbound_documents, outbound_lines, outbound_scan_batches |
| Inventory | inventory_balances, inventory_movements |
| Stocktake | stocktake_sessions, stocktake_snapshots, stocktake_counts, stocktake_variances |
| Warranty | warranty_cases, warranty_events, warranty_defects |
| PV | pv_product_profiles, pv_industries, pv_recommendations, pv_publish_logs |

## 6.3. Vì sao không chọn MongoDB làm database chính?

- WMS cần transaction chặt cho tồn kho.
- Inventory balance và movement cần đối soát chính xác.
- Stocktake cần snapshot và variance rõ ràng.
- Permission/data scope và báo cáo XNT phù hợp hơn với relational database.
- MongoDB có thể dùng phụ trợ cho log/event lớn sau này, nhưng không nên là DB lõi MVP.

# 7. Cache, Queue, Lock

## 7.1. Valkey

| Nhu cầu | Dùng để làm gì |
|---|---|
| Cache | Cache menu, permission, dashboard nhẹ, danh mục ít thay đổi |
| Queue | Xuất Excel/PDF, import dữ liệu, gửi email, sync MISA phase sau |
| Distributed lock | Chống double submit Post Receipt/Post Issue/Apply Adjustment |
| Rate limit | Chống spam API scan/import/export |
| Session/token cache | Lưu session/token blacklist nếu cần |
| Pub/Sub | Notification realtime sau này |

## 7.2. Quy tắc xử lý tồn kho khi có nhiều người thao tác

Ví dụ: kho còn 10 máy, hai nhân viên cùng Post Issue 8 máy. Backend bắt buộc lock inventory_balance trong transaction, check lại tồn hiện tại và chỉ một transaction được commit nếu tổng xuất vượt tồn.

```text
DB Transaction + Row-level lock inventory_balances + Re-check available_qty + Insert movement
+ Commit
```

# 8. Realtime / Notification

## 8.1. Phase đầu

- Chưa cần realtime phức tạp.
- Có notification trong app cho phiếu lỗi, scan unknown, tồn thấp, kiểm kê có variance.
- Dashboard refresh theo chu kỳ hoặc bấm reload.
- Email/Telegram nội bộ chỉ dùng nếu team vận hành cần.

## 8.2. Phase sau

| Hướng | Khi nào dùng |
|---|---|
| Laravel Reverb/Echo | Nếu vẫn muốn giữ tất cả trong Laravel |
| NestJS WebSocket service | Nếu notification/chat/realtime phức tạp hơn |
| BI/Analytics Warehouse | Nếu dashboard/report nặng ảnh hưởng DB chính |
| MISA sync worker | Khi có API/file đối soát chính thức |
| RFID gateway service | Khi triển khai thiết bị RFID thật |

# 9. DevOps / Local development

## 9.1. Docker Compose

| Thành phần | Công nghệ |
|---|---|
| Container | Docker Compose |
| Web server | Nginx |
| Backend | PHP-FPM / Laravel |
| Frontend | Node.js LTS |
| Database | PostgreSQL |
| Cache/Queue | Valkey |
| Mail dev | Mailpit hoặc Mailhog |
| Storage dev | MinIO |
| API testing | Postman |

## 9.2. Services gợi ý trong docker-compose.yml

```yaml
services:
 nginx:
  image: nginx:alpine

 backend:
  build: ./backend

 frontend:
   build: ./frontend

 postgres:
  image: postgres:latest

 valkey:
  image: valkey/valkey:latest

 mailpit:
  image: axllent/mailpit:latest

 minio:
  image: minio/minio:latest
```

File trên chỉ là skeleton định hướng. Khi triển khai thật cần bổ sung port, volume, network, healthcheck và biến môi trường.

# 10. Tech stack theo từng module

| Module | Tech/Design | Điểm kỹ thuật quan trọng |
|---|---|---|
| Master Data & Code | Laravel Module MasterData | SKU khác item/container; physical_code dùng cho QR/Barcode/RFID-ready |
| Inbound | Laravel Module Warehouse.Inbound | Post Receipt mới tăng tồn;<br>DRAFT/SCANNING không đổi tồn;<br>idempotent |
| Outbound | Laravel Module Warehouse.Outbound | Post Issue giảm tồn; lock chống âm kho;<br>agency không phải kho |
| Inventory | InventoryBalance + InventoryMovement | Balance là tồn hiện tại; movement là audit append-only |
| Stocktake | Stocktake snapshot + count + variance | Variance tính theo snapshot; Apply Adjustment mới đổi tồn |
| Warranty | WarrantyCase + WarrantyEvent | TEMP_ONLY khi mất mã; không tự truy máy đã bán nếu thiếu bằng chứng |
| App PV | React SPA + PV API read-only | Chỉ xem published product và available_qty, không order/reserve/payment |
| Report | Report endpoint/materialized view | Báo cáo XNT khớp movement; export chạy queue |

## 10.1. P0 kỹ thuật bắt buộc trước khi Done

- Có API contract cho create/scan/post/cancel của inbound/outbound.
- Có test double post/idempotency.
- Có test xuất vượt tồn/race condition.
- Có audit/movement cho mọi action đổi tồn.
- Có evidence Postman/Jira cho luồng chính và lỗi nghiệp vụ.
- Không để frontend tự cộng/trừ tồn thay backend.

# 11. API, Postman, Jira và handoff

## 11.1. API contract phải đủ cho Dev/FE/Test

| Nhóm API | Endpoint gợi ý | Ghi chú |
|---|---|---|
| SKU/Item/Code | GET/POST /skus, /items, /physical-codes | Validate mã, duplicate, active/inactive |
| Scan | POST /scan-batches/classify | Trả code_type, duplicate, unknown, scan_source |
| Inbound | POST /inbound-documents, /scan, /post-receipt | Post Receipt idempotent |
| Outbound | POST /outbound-documents, /scan, /post-issue | Chặn âm kho, idempotent |
| Inventory | GET /inventory-balances, /inventory-movements | Filter SKU/status/time |
| Stocktake | POST /stocktake-sessions, /count, /apply-adjustment | Snapshot + variance |
| Warranty | POST /warranty-cases, PATCH /status | TEMP_ONLY support |
| PV | GET /pv/products, /pv/products/{sku_code} | Read-only, published only |
| Report | GET /reports/xnt, /reports/warranty-defects | Export queue nếu nặng |

## 11.2. Evidence Jira tối thiểu

- Screenshot UI hoặc API response cho luồng happy path.
- Screenshot/error response cho validation quan trọng.
- Postman collection hoặc link API contract.
- Ghi rõ branch/commit/tag nếu là task Dev/FE.
- Không attach lan man quá nhiều file; ưu tiên một evidence chính thức gọn và có cấu trúc.

# 12. Kết luận stack đề xuất cho WMS Hoa Nam

| Lớp | Chốt đề xuất |
|---|---|
| Frontend Web Admin | React + Vite + TypeScript |
| Frontend App PV | React SPA read-only; NextJS chỉ cân nhắc nếu public catalog cần SEO |
| Backend | Laravel API Core theo Modular Monolith |
| Database | PostgreSQL |
| Cache/Queue/Lock | Valkey |
| Storage | S3-compatible/MinIO/local theo môi trường |
| DevOps local | Docker Compose |
| API/Test | Postman/Newman + Jira evidence |
| Future | BI warehouse, MISA sync worker, RFID gateway, notification realtime khi có nhu cầu thật |

**Khuyến nghị cuối: không nên chọn NextJS chỉ vì xu hướng. Với WMS Hoa Nam, ReactJS/Vite/TypeScript là lựa chọn thực dụng hơn cho Web Admin nội bộ: nhẹ, dễ maintain, phù hợp các màn kho Admin nội bộ, dễ chia module, dễ cho FE intern/dev triển khai theo API contract và không tạo thêm tầng SSR không cần thiết.**
