# WWHN-180 — FE1 API/Permission Mapping Sign-off

> Trạng thái tài liệu: **PROPOSED**. Mẫu này không phải chữ ký phê duyệt. FE1 phải tự kiểm tra artifact từ workflow PASS và điền thông tin thực tế trên Jira.

## 1. Build bắt buộc

- Branch: `m1`
- Exact commit SHA: `<build-metadata.json.commit_sha>`
- CI run URL: `<build-metadata.json.workflow_run_url>`
- Artifact: `api-data-evidence-<exact_sha>`
- `SHA256SUMS.sha256`: `<digest>`
- FE mapping version/commit: `<FE repository + exact SHA>`

Không dùng branch name động, ảnh chụp, hash viết tay hoặc artifact từ run FAIL để ký.

## 2. Ma trận FE route → API → permission

| FE action | API | Method | Permission bắt buộc | FE guard/action visibility | Result |
|---|---|---:|---|---|---|
| Scan dòng phiếu nhập | `/api/v1/inbound-documents/{id}/scan` | POST | `stock_document.scan` | `<route/component/action>` | PENDING |
| Scan dòng phiếu xuất | `/api/v1/outbound-documents/{id}/scan` | POST | `stock_document.scan` | `<route/component/action>` | PENDING |
| Hạch toán nhập | `/api/v1/inbound-documents/{id}/post-receipt` | POST | `stock_document.post` | `<route/component/action>` | PENDING |
| Hạch toán xuất | `/api/v1/outbound-documents/{id}/post-issue` | POST | `stock_document.post` | `<route/component/action>` | PENDING |
| Xem reconciliation | `/api/v1/reports/inventory-reconciliation` | GET | `report.view` | `<route/component/action>` | PENDING |
| Xem sản phẩm | `/api/v1/products` | GET | `product.view` | `<route/component/action>` | PENDING |
| Quản lý sản phẩm | `/api/v1/products*` | POST/PATCH | `product.manage` | `<route/component/action>` | PENDING |
| Quản lý SKU alias | `/api/v1/sku-aliases*` | write | `sku.manage` | `<route/component/action>` | PENDING |

## 3. Contract phải xác nhận

- Scan trả `stock_effect=NONE`, không tạo movement và không đổi trạng thái chứng từ.
- Post receipt/issue bắt buộc `Idempotency-Key`; thiếu key trả `428 PRECONDITION_REQUIRED`.
- Cùng key/cùng payload replay đúng response snapshot; FE không tự cộng tồn lần hai.
- Cùng key/khác payload trả `409 IDEMPOTENCY_ERROR`.
- Key khác khi chứng từ đã post trả `409 STATE_CONFLICT`.
- `403 ACCESS_DENIED` phải ẩn/chặn action tương ứng, nhưng API vẫn là enforcement cuối cùng.
- FE hiển thị lỗi từ `error.code`, không suy diễn từ message tự do.

## 4. Kết quả review

- Reviewer FE1: `<họ tên/GitHub/Jira user>`
- Review date/time: `<ISO-8601 +07:00>`
- Exact BE SHA: `<sha>`
- Artifact digest: `<SHA256SUMS.sha256>`
- Exact FE SHA/version: `<sha/version>`
- Result: `PASS | FAIL | CONDITIONAL`
- Blockers/findings: `<danh sách hoặc NONE>`
- Jira comment/evidence URL: `<url>`

Chỉ comment do FE1 thực hiện trên cùng exact BE SHA/artifact digest mới được tính là independent sign-off.
