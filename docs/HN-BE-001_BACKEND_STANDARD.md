# HN-BE-001 - Backend Standard

## 1. Mục tiêu

Tài liệu này chuẩn hóa định hướng Backend cho dự án Hoa Nam WMS.

Backend của dự án dùng PHP với Laravel 13 theo kiến trúc Modular Monolith. Người phụ trách Backend không cần học React hoặc TypeScript nếu chỉ làm phần API, nghiệp vụ, database, bảo mật, phân quyền và tích hợp hệ thống.

## 2. Ngôn ngữ và nền tảng bắt buộc

### 2.1. PHP

PHP là ngôn ngữ lập trình chính của Backend.

Backend được viết bằng:

```text
PHP
└── Laravel 13
```

Laravel được dùng để triển khai:

- REST API.
- Authentication và authorization.
- Role, permission và data scope.
- Validation.
- Business rule.
- Database transaction.
- Queue.
- Cache.
- File upload.
- Audit log.
- Swagger/OpenAPI.
- Unit test và API test.

### 2.2. SQL và PostgreSQL

Backend cần hiểu SQL để làm việc đúng với PostgreSQL.

Các nội dung cần nắm:

- Tạo bảng.
- Foreign key.
- Unique constraint.
- Index.
- Transaction.
- Row-level lock.
- Query báo cáo.
- Migration.

Không bắt buộc viết toàn bộ SQL thủ công vì Laravel có Eloquent ORM và Migration, nhưng vẫn phải hiểu SQL để thiết kế database, transaction và báo cáo chính xác.

### 2.3. Cú pháp hỗ trợ

Ngoài PHP và SQL, Backend sẽ thường gặp:

- JSON cho request và response API.
- YAML cho Docker Compose và OpenAPI.
- Bash hoặc PowerShell cho Laravel, Docker và Git.
- Markdown cho tài liệu kỹ thuật.

PHP vẫn là ngôn ngữ lập trình chính của Backend.

## 3. Backend Tech Stack

| Mục đích | Công nghệ chuẩn |
| --- | --- |
| Backend framework | Laravel 13 |
| Ngôn ngữ | PHP 8.3+ |
| Database | PostgreSQL |
| ORM | Laravel Eloquent |
| Cache | Valkey |
| Queue | Laravel Queue + Valkey |
| Lock | PostgreSQL row lock hoặc Valkey distributed lock |
| Authentication | JWT hiện tại; Sanctum chỉ dùng nếu team đổi quyết định |
| Permission | Laravel Policy/Gate + Data Scope |
| Validation | Laravel Form Request |
| API | REST API |
| API documentation | Swagger/OpenAPI |
| API testing | Postman/Newman |
| Unit/Feature test | PHPUnit hiện tại; Pest chỉ dùng nếu team thống nhất bổ sung |
| File storage | MinIO local, S3-compatible production |
| Local environment | Docker Compose |
| Web server | Nginx |
| Email local | Mailpit |
| Monitoring | Sentry, Horizon/Pulse, Grafana tùy giai đoạn triển khai |

Stack tổng thể của Backend là Laravel, PostgreSQL, Valkey, Docker Compose, Swagger/OpenAPI, Postman và Git.

> **Ghi chú driver DB (DEV195-P2-02R):** PostgreSQL là database canonical/production duy nhất
> theo bảng trên. Một số migration và service trong `Modules/Warehouse` có rẽ nhánh theo
> `DB::getDriverName() === 'sqlite'` — đây CHỈ là workaround để test/dev suite (PHPUnit mặc định)
> chạy được trên SQLite in-memory/file (không hỗ trợ trigger/view PL/pgSQL, không enforce
> `varchar(N)` length, số ít/số nhiều bảng có thể đảo chiều — xem
> `docs/docs_task_jira/WWHN-219.md` và `phpunit.pgsql.xml`). Hành vi/PASS trên SQLite không phải
> căn cứ nghiệm thu cuối; mọi migration và business logic phải được xác nhận đúng trên PostgreSQL
> trước khi coi là hoàn tất.

## 4. Phạm vi công việc Backend

Backend không chỉ tạo CRUD. Mỗi chức năng phải bao gồm API contract, validation, business rule, permission, data scope, transaction khi cần, audit và test.

### 4.1. Xây dựng API

Ví dụ API:

```text
POST /api/auth/login

GET  /api/products
POST /api/products
GET  /api/products/{id}
PUT  /api/products/{id}

POST /api/stock-ins
POST /api/stock-ins/{id}/confirm

POST /api/stock-outs
POST /api/stock-outs/{id}/confirm
```

Mỗi API cần có:

- Request schema.
- Validation.
- Business rule.
- Permission.
- Data scope.
- Response schema.
- Error code.
- Swagger/OpenAPI.
- Test.

### 4.2. Xử lý nghiệp vụ

Ví dụ khi người dùng xác nhận xuất kho, Backend phải:

1. Kiểm tra đăng nhập.
2. Kiểm tra quyền xuất kho.
3. Kiểm tra user có quyền với kho được thao tác.
4. Kiểm tra phiếu đang ở trạng thái cho phép xác nhận.
5. Mở database transaction.
6. Lock bản ghi tồn kho.
7. Kiểm tra lại số lượng tồn.
8. Trừ tồn kho.
9. Ghi stock movement.
10. Ghi audit log.
11. Chuyển trạng thái phiếu.
12. Commit.
13. Trả response cho Frontend.

Nếu một bước lỗi, toàn bộ nghiệp vụ phải rollback. Transaction là yêu cầu bắt buộc với nghiệp vụ kho, tồn kho, tài chính và các thao tác có đối soát.

### 4.3. Quản lý database

Backend phải thiết kế và quản lý các nhóm bảng chính như:

- `users`
- `roles`
- `permissions`
- `companies`
- `branches`
- `departments`
- `employees`
- `products`
- `warehouses`
- `inventory_balances`
- `stock_movements`
- `stock_receipts`
- `stock_issues`
- `stock_transfers`
- `sales_orders`
- `receipts`
- `payments`
- `audit_logs`

Khi thiết kế bảng cần xác định rõ:

- Bảng nào liên kết với bảng nào.
- Trường nào unique.
- Trường nào nullable.
- Trường nào cần index.
- Khi nào được soft delete.
- Khi nào tuyệt đối không được xóa.
- Dữ liệu nào bắt buộc audit.

PostgreSQL được chọn vì hệ thống ERP/WMS cần transaction, quan hệ dữ liệu, đối soát, audit và báo cáo chặt chẽ.

### 4.4. Bảo mật và phân quyền

Backend phải tự kiểm tra quyền và phạm vi dữ liệu. Không được phụ thuộc vào việc Frontend ẩn nút hoặc ẩn màn hình.

Các scope cần kiểm tra tùy nghiệp vụ:

- `tenant_id`
- `company_id`
- `branch_id`
- `department_id`
- `warehouse_id`
- `role`
- `permission`

Ví dụ user thuộc chi nhánh Hà Nội không được tự gửi:

```json
{
  "branch_id": "chi-nhanh-ho-chi-minh"
}
```

rồi xem hoặc thao tác dữ liệu của chi nhánh khác.

## 5. Chuẩn cấu trúc code

Không viết toàn bộ logic trong Controller.

Luồng xử lý chuẩn:

```text
Route
→ Controller
→ Form Request
→ Service/UseCase
→ Model/Repository
→ PostgreSQL
```

Ví dụ module Warehouse:

```text
Modules/
└── Warehouse/
    ├── app/
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   └── Requests/
    │   ├── Models/
    │   ├── Policies/
    │   ├── Services/
    │   ├── DTOs/
    │   ├── Events/
    │   └── Jobs/
    ├── database/
    │   ├── migrations/
    │   └── seeders/
    └── routes/
        └── api.php
```

Ví dụ luồng xác nhận xuất kho:

```text
StockIssueController
→ ConfirmStockIssueRequest
→ ConfirmStockIssueService
→ InventoryBalance / StockMovement
→ PostgreSQL
```

Controller chỉ nên:

- Nhận request.
- Gọi validation thông qua Form Request.
- Gọi Service hoặc UseCase.
- Trả response.

Business logic nằm trong Service hoặc UseCase.

## 6. Thứ tự triển khai và học tập

### Bước 1. PHP nền tảng

Cần nắm:

- Biến, array.
- Function.
- Class và object.
- Interface.
- Inheritance.
- Exception.
- Namespace.
- Composer.
- OOP.
- Dependency injection.

### Bước 2. Laravel căn bản

Cần làm được:

- Route.
- Controller.
- Form Request.
- Model.
- Migration.
- Seeder.
- Eloquent relationship.
- Middleware.
- Resource/response.
- Exception handler.

### Bước 3. PostgreSQL

Cần nắm:

- `SELECT`, `INSERT`, `UPDATE`.
- `JOIN`.
- Foreign key.
- Unique constraint.
- Index.
- Transaction.
- Lock.
- Aggregate/report query.

### Bước 4. Authentication và Permission

Cần triển khai:

- Login/logout.
- Current user.
- Role.
- Permission.
- Policy/Gate.
- Data scope theo công ty, chi nhánh, phòng ban và kho.

### Bước 5. Organization Module

Triển khai theo thứ tự:

```text
Company
→ Branch
→ Department
→ Employee
→ User
→ Role
→ Permission
```

Đây là module nền cho các module nghiệp vụ còn lại.

### Bước 6. Product và Warehouse

Triển khai theo thứ tự:

```text
Product
→ Warehouse
→ Inventory Balance
→ Stock Movement
→ Stock In
→ Stock Out
→ Stock Transfer
```

Ở bước này phải xử lý kỹ transaction, row-level lock, idempotency và concurrency.

### Bước 7. Swagger/OpenAPI và Postman

Mỗi API phải có:

- Summary.
- Description.
- Request schema.
- Response schema.
- Error response.
- Bearer Authentication.
- Request/response example.

Swagger phải đủ rõ để Frontend tích hợp và Tester kiểm thử.

### Bước 8. Test

Cần viết:

- Unit test.
- API test.
- Permission test.
- Data scope test.
- Validation test.
- Transaction rollback test.
- Double confirm test.
- Race-condition test.
- Xuất vượt tồn test.

## 7. Công nghệ chưa ưu tiên giai đoạn đầu

Giai đoạn đầu chưa cần ưu tiên:

- Microservice.
- Kubernetes.
- Kafka.
- NestJS.
- GraphQL.
- MongoDB.
- Python/FastAPI.
- ClickHouse.
- Elasticsearch.

Các công nghệ này chỉ cân nhắc khi hệ thống đủ lớn, có tải thực tế, hoặc có nhu cầu tách service rõ ràng. Dự án hiện ưu tiên Modular Monolith trước.

## 8. Kết luận

Backend của dự án tập trung vào:

```text
PHP
+ Laravel 13
+ PostgreSQL
+ Valkey
+ REST API
+ Swagger/OpenAPI
+ Postman
+ Docker Compose
+ Git
```

Lộ trình thực tế:

```text
Học PHP
→ Học Laravel
→ Làm Authentication
→ Làm Role/Permission/Data Scope
→ Làm database PostgreSQL
→ Làm Organization
→ Làm Product/Warehouse
→ Thêm transaction và lock
→ Viết Swagger/OpenAPI
→ Viết test
```

Backend của dự án này là PHP Laravel, không phải Java, Node.js hoặc Python.
