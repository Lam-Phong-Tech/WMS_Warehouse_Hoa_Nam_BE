# HN-BE-API-001 — Bàn giao contract API v1 cho FE/QA

| Trường | Giá trị |
| --- | --- |
| Tài liệu | HN-BE-API-001 |
| Task | WWHN-309 — [BE] Hoàn thiện tài liệu kiến trúc/API/vận hành |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`, nhánh `m1` |
| Người nhận | FE1 (WWHN-311), QA1 (WWHN-312), BA/BAS (WWHN-308) |
| Cập nhật | 2026-08-18 |

Tài liệu này là **điểm vào duy nhất** cho ai cần tích hợp hoặc kiểm thử API backend. Nó không chép lại từng endpoint bằng tay — mọi bảng chi tiết đều sinh tự động từ code để không bao giờ lệch.

---

## 1. Artifact bàn giao và cách lấy

| Artifact | Đường dẫn trong repo | Sinh bởi |
| --- | --- | --- |
| OpenAPI 3.0 (JSON) | `storage/api-docs/api-docs.json` | `php artisan l5-swagger:generate` |
| OpenAPI 3.0 (YAML) | `storage/api-docs/api-docs.yaml` | như trên |
| Swagger UI | `GET /api/documentation` | l5-swagger |
| Catalog error code | `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md` \| `.json` | `node scripts/generate-error-code-catalog.mjs` |
| Ma trận endpoint × permission | `docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md` \| `.json` | `node scripts/generate-endpoint-permission-matrix.mjs` |
| Postman collection | `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | sinh từ OpenAPI |
| Postman environment | `docs/postman/WMS_HoaNam - Local.postman_environment.json`, `... - CI...` | thủ công |
| Danh mục permission | `docs/permissions/WMS_PERMISSION_CATALOG_BE1.md` \| `.json` | `php artisan permission:export-catalog` |

**Cả bốn artifact sinh tự động đều được commit vào git.** Nghĩa là FE/QA lấy contract theo **exact SHA** chứ không phải theo "bản mới nhất trên máy ai đó":

```bash
curl -fsSL -o swagger-api.json "https://raw.githubusercontent.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/<BE_SHA>/storage/api-docs/api-docs.json"
```

Repo FE đã có sẵn cổng kiểm tra dùng file này: đặt `swagger-api.json` ở gốc repo FE rồi chạy `npm run check:api-mapping`. Không có file đó, script chỉ kiểm tra nội bộ FE và **tự khai báo là không đủ để coi là sign-off contract**.

---

## 2. Envelope

### 2.1. Thành công

```json
{
  "success": true,
  "message": "Operation successful",
  "data": {},
  "meta": { "request_id": "uuid", "timestamp": "2026-08-18T10:00:00+07:00" }
}
```

Danh sách có phân trang bổ sung vào `meta`: `current_page`, `per_page`, `last_page`, `total`, `from`, `to`; và thêm khối `links`: `first`, `last`, `prev`, `next`.

### 2.2. Lỗi

```json
{
  "success": false,
  "message": "Thông điệp tiếng Việt hiển thị được cho người dùng",
  "error_code": "STATE_CONFLICT",
  "errors": { "field": ["chi tiết"] },
  "error": { "code": "STATE_CONFLICT", "details": { "field": ["chi tiết"] } },
  "meta": { "request_id": "uuid", "timestamp": "2026-08-18T10:00:00+07:00" }
}
```

**`error_code` và `error.code` luôn bằng nhau.** `error_code` là khoá FE hiện dùng; `error.code` là dạng lồng theo Annex C. Không client nào được coi một trong hai là optional.

Từ WWHN-309, OpenAPI khai envelope bằng schema thật thay vì mô tả bằng lời:

- `components.schemas.ApiSuccessEnvelope`
- `components.schemas.ApiErrorEnvelope`
- `components.schemas.ApiErrorDetail`, `ApiMeta`, `ApiLinks`

**100% response trong spec đều có `content` trỏ tới một trong hai envelope** (số đếm chính xác: `HN-BE-API-004_CONTRACT_METRICS.md`), nên FE/QA sinh type từ spec được ngay.

### 2.3. `request_id`

Mọi response đều có `meta.request_id`. Khi báo lỗi cho BE, **luôn kèm `request_id`** — đây là khoá đối chiếu log và audit.

---

## 3. Xác thực và phân quyền

| Mục | Giá trị |
| --- | --- |
| Cơ chế | JWT Bearer — header `Authorization: Bearer <access_token>` |
| Lấy token | `POST /api/v1/auth/login` |
| Gia hạn | `POST /api/v1/auth/refresh` (dùng cookie HttpOnly `wms_refresh_token`) |
| Ngữ cảnh người dùng | `GET /api/v1/auth/me` |
| TTL access token | `JWT_TTL` phút (mặc định 60) |
| TTL refresh token | `REFRESH_TOKEN_TTL_MINUTES` (mặc định 20160 = 14 ngày) |

Phân quyền theo **permission code**, không theo tên role. Middleware `permission:A|B` nghĩa là **OR** — có A *hoặc* B đều qua.

Endpoint public (không cần JWT) — allowlist tường minh, số lượng chính xác xem `HN-BE-API-004`:

- `GET /api/v1/health`
- `POST /api/v1/auth/login`, `POST /api/v1/auth/refresh`
- `GET /api/v1/pv/catalog`, `/pv/industries`, `/pv/tags`, `/pv/recommendations` — App PV ẩn danh theo SRS v1.12 (SRC-008, DR-PLAT-001)

Bảng đầy đủ endpoint → permission: `HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md`.

**Route guard của FE chỉ là UX guard.** Biên phân quyền thật luôn nằm ở BE; FE không được coi việc ẩn nút là biện pháp bảo mật.

---

## 4. Header contract

| Header | Khi nào | Hành vi |
| --- | --- | --- |
| `Authorization: Bearer <jwt>` | Mọi endpoint trừ 9 endpoint public | Thiếu/hết hạn → 401 `UNAUTHENTICATED` |
| `Idempotency-Key` | 33 operation (23 bắt buộc, 10 tuỳ chọn) | Chuỗi 8–100 ký tự. Thiếu ở endpoint bắt buộc → 428. Cùng key + cùng payload → trả lại kết quả lần đầu. Cùng key + payload khác → 409 |
| `If-Match` | xem HN-BE-API-004 | Số nguyên = `version` hiện tại. Sai → 412 `STALE_VERSION`. Thiếu ở endpoint bắt buộc → 428 `PRECONDITION_REQUIRED` |
| `Accept: application/json` | Nên luôn gửi | Bảo đảm handler lỗi trả JSON |

Danh sách chính xác operation nào cần header nào nằm trong OpenAPI (`parameters[].in == "header"`) — được sinh tự động từ chính source của controller, không khai tay.

### 4.1. Mẫu dùng đúng

```http
POST /api/v1/outbound-documents/{id}/post-issue
Authorization: Bearer <jwt>
Idempotency-Key: 7f2a9c1e-outbound-post-0001
If-Match: 4
Content-Type: application/json

{ "note": "Xuất toa 2026-08-18" }
```

Client **retry cùng `Idempotency-Key`** khi mất mạng là an toàn: lần hai trả về kết quả lần đầu, không xuất kho hai lần.

---

## 5. Mã lỗi

Toàn bộ error code nằm ở `HN-BE-API-002_ERROR_CODE_CATALOG.md` (sinh tự động từ source). Quy tắc dùng:

| HTTP | Nghĩa | FE nên làm gì |
| --- | --- | --- |
| 400 | Request sai định dạng | Sửa payload, không retry |
| 401 | Chưa xác thực / token hết hạn | Auto refresh rồi retry một lần; thất bại → về màn hình đăng nhập |
| 403 | Thiếu permission hoặc ngoài data scope | Hiện trạng thái không có quyền, không retry |
| 404 | Không tồn tại hoặc ngoài phạm vi dữ liệu | Làm mới danh sách |
| 409 | Xung đột trạng thái/đồng thời/idempotency | Hiển thị thông điệp, để người dùng làm mới. **Không tự động retry** |
| 412 | `STALE_VERSION` | Tải lại bản ghi, lấy `version` mới, thử lại |
| 422 | Validation/quy tắc nghiệp vụ | Map `errors` vào từng field của form |
| 428 | Thiếu `If-Match` hoặc `Idempotency-Key` | Bổ sung header rồi gửi lại |
| 500 | Lỗi ngoài dự kiến | Giao dịch đã rollback. Báo BE kèm `request_id` |

**Bắt lỗi theo `error_code`, không theo `message`.** Message có thể đổi từ ngữ mà không đổi contract.

---

## 6. Quy ước phân trang, lọc, sắp xếp

- Query chuẩn cho danh sách: `page`, `per_page`, `search`, `status`, `sort`, `direction` (tuỳ resource; xem `parameters` trong OpenAPI của từng operation).
- Kết quả phân trang trả `meta` + `links` như §2.1.
- Báo cáo hỗ trợ export dùng cặp endpoint `GET /api/v1/reports/<code>` và `GET /api/v1/reports/<code>/export-excel`.
- Export có giới hạn số dòng: vượt ngưỡng → 422 `REPORT_EXPORT_ROW_LIMIT_EXCEEDED`. Đây là chủ ý bảo vệ hệ thống, không phải lỗi.

---

## 7. Bề mặt API hiện tại

> **Số liệu contract không được chép vào đây.** Bảng đếm chính thức nằm ở
> `docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.md` (sinh tự động từ OpenAPI +
> catalog + ma trận, có `--check` trong CI).
>
> Lý do: trước đây bảng này được chép tay, nên cùng một gói bàn giao ghi hai giá trị
> khác nhau cho cùng một phép đếm trong khi spec thật đã sang giá trị thứ ba. Một
> nguồn số liệu duy nhất là cách duy nhất chấm dứt lớp lỗi đó.

| Chỉ số | Giá trị |
| --- | --- |
| Base path | `/api/v1` |
| Path / operation / response / schema | HN-BE-API-004 |
| Permission code gác route | HN-BE-API-004 |
| Endpoint public | HN-BE-API-004 |
| Operation có data scope theo kho | HN-BE-API-004 |

Độ phủ 100% không phải may mắn: `App\Swagger\Processors\RouteSyncProcessor` đọc route table thật khi generate, nên endpoint mới **luôn** xuất hiện trong spec.

---

## 8. Những gì API **không** làm (contract âm)

Ghi rõ để FE/QA không chờ một endpoint không tồn tại:

1. **Không có endpoint Stocktake / kiểm kê / sửa tồn trực tiếp.** MVP đã gỡ khỏi phạm vi.
2. **Không có partial posting.** Không có API post một phần phiếu.
3. **Không có endpoint sửa/xoá movement đã post.** Sửa sai đi qua `reversal-documents`.
4. **Không có public/admin migration import endpoint.** Nạp dữ liệu đầu kỳ là job vận hành có kiểm soát (DEC-MIG-API-001).
5. **RFID không phải cổng chặn Post Issue.** Gắn RFID là hành động riêng, tuỳ chọn.
6. **Scan không đổi tồn.** Scan chỉ cập nhật `scanned_qty` của dòng chứng từ.

Tài liệu cũ `docs/api-contract/WMS_HoaNam_OpenAPI_Changelog_BE1.md` (bản 1.0.1, tháng 8/2026) còn liệt kê `POST /api/v1/stocktake-sessions/{id}/apply-adjustment` trong "Baseline Rule". Route đó **không còn tồn tại** trong route table hiện tại. Changelog được giữ nguyên vì là bản ghi lịch sử; khi có mâu thuẫn, **tài liệu này và route table thắng**.

---

## 9. Quy trình đổi contract

Bắt buộc theo đúng thứ tự, không được đảo:

1. Đổi route/controller trong code.
2. `php artisan l5-swagger:generate` — cập nhật `storage/api-docs/*`.
3. `node scripts/generate-error-code-catalog.mjs` nếu có error code mới.
4. `node scripts/generate-endpoint-permission-matrix.mjs` nếu có route/permission mới.
5. `node scripts/verify-wwhn-309-handover-contract.mjs` — cổng tổng, phải PASS.
6. Cập nhật Postman collection nếu contract dùng cho kiểm thử đổi.
7. Nêu thay đổi trong PR/evidence và báo FE1/QA1 kèm exact SHA.

CI chạy bước 3–5 ở chế độ `--check`: tài liệu lệch code sẽ **fail build**, không im lặng trôi qua.

---

## 10. Điều kiện nghiệm thu phía BE cho bàn giao này

| Hạng mục | Trạng thái |
| --- | --- |
| Mọi route `/api/v1` có mặt trong OpenAPI | Đạt — độ phủ 100%, cổng tổng kiểm lại mỗi lần chạy |
| Mọi response mô tả được hình dạng payload | Đạt — 100%, xem HN-BE-API-004 |
| Header `Idempotency-Key` / `If-Match` khai đúng runtime | Đạt — sinh tự động từ source |
| Catalog error code đầy đủ, tự kiểm tra được | Đạt — có `--check` |
| Ma trận endpoint × permission | Đạt — xem HN-BE-API-004 |
| Endpoint public được allowlist tường minh | Đạt — xem HN-BE-API-004 |
| Cổng chống trôi tài liệu | Đạt — `scripts/verify-wwhn-309-handover-contract.mjs` |
| Kiểm chứng chéo với FE | Đạt — `npm run check:api-mapping` PASS trên spec BE |

Những hạng mục **không** thuộc BE1 và không được tự ký ở đây: QA test evidence (WWHN-312), DevOps environment/backup/monitor (WWHN-313), đóng gói handover index (WWHN-308), đóng parent WWHN-83.
