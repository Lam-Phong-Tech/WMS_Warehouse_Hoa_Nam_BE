# WWHN-267 — Bằng chứng đo thực tế (trước / sau)

- Task: `WWHN-267` · Parent: `WWHN-70` · Nguồn QA: `HN-QA-005-ST01`
- Nhánh: `m1` · BE SHA đo: `301899c48af13ea01171b30b280cc6f5d39d3404` · FE SHA đo: `42e6957`
- Driver: sqlite in-memory (`phpunit.xml`) · PHP 8.3.30 · Windows

> Ranh giới sự thật: mọi số liệu dưới đây là lượt chạy **in-process trên DB test tại
> máy dev**. Nó dùng để chứng minh defect có thật và fix có tác dụng, **không** thay
> lượt regression của QA1 (`WWHN-264`) / QA2 (`WWHN-265`) trên build đã deploy. Bằng
> chứng trích dẫn ra ngoài phải lấy từ lượt chạy CI trên `GITHUB_SHA`
> (`.github/workflows/wwhn-267-be-contract-closure.yml`).

## 1. Mã lỗi 401 trên sáu bề mặt bảo vệ (DIV-401-01)

Gọi khi chưa xác thực:

```json
{"stock.inbound":[401,"UNAUTHENTICATED","rid"],
 "stock.outbound":[401,"UNAUTHENTICATED","rid"],
 "stock.balance":[401,"UNAUTHENTICATED","rid"],
 "reversal":[401,"UNAUTHENTICATED","rid"],
 "warranty":[401,"UNAUTHENTICATED","rid"],
 "report":[401,"UNAUTHENTICATED","rid"]}
```

SRS §8.2 nêu `AUTH_REQUIRED` / `TOKEN_EXPIRED` ở cột *ví dụ*; cài đặt trả
`UNAUTHENTICATED`. Envelope đủ `success=false` + `error_code` + `meta.request_id`.
Khác biệt tên gọi đã ghi thành `DIV-401-01`, chờ BA + Tech Lead — WWHN-267 **không**
tự đổi. BE268-D02 của vòng trước chỉ ghi nhận khác biệt ở 403; đây là bằng chứng cho
thấy phải disposition cả nhóm.

## 2. Ma trận RBAC đo được — cơ sở của BE267-D05 và DELTA-02

| role → bề mặt | inbound | outbound | balances | reversal-documents | warranty-cases | reports/hub |
|---|---|---|---|---|---|---|
| `LEADERSHIP` | 403 ACCESS_DENIED | 403 ACCESS_DENIED | 200 | 200 | **200** | 200 |
| `WAREHOUSE_STAFF` | 200 | 200 | 200 | 200 | 403 ACCESS_DENIED | 200 |
| `WARRANTY_STAFF` | 403 ACCESS_DENIED | 403 ACCESS_DENIED | 200 | 200 | 200 | 403 ACCESS_DENIED |

Hai điều rút ra:

1. **`LEADERSHIP` chỉ bị chặn ở 2/6 bề mặt.** Test hợp đồng 403 của WWHN-268
   (`Wwhn268RegressionContractTest`) duyệt sáu bề mặt nhưng `continue` khi status
   khác 403 rồi chỉ khẳng định `assertGreaterThan(0, $denied)`. Nghĩa là cổng đó vẫn
   xanh kể cả khi permission middleware rụng khỏi năm route — chỉ cần một route còn
   chặn. Đây là BE267-D05; fix là ma trận 18 ô trong `Wwhn267ContractClosureTest`.
2. **`LEADERSHIP` đọc được `GET /api/v1/warranty-cases` và `GET /api/v1/putaway-tasks`
   (đều 200).** SRS §3 đánh `D` cho Lãnh đạo ở cả hai row, nhưng cột "Permission key"
   của hai row đó chỉ liệt kê mức *manage*, còn seed cấp mức *view*. Đây là khoảng
   trống nguồn → `DELTA-02` trong `ROLE_BASELINE_PIN.json`, chờ BA. Kiểm soát bù đã
   kiểm chứng: `LEADERSHIP` không có `warranty.pii.view` nên PII luôn mask
   (test `test_leadership_cannot_read_warranty_pii_even_though_it_can_list_cases`).

Ô `reversal-documents = 200` cho cả ba vai trò là **đúng** baseline: route `GET
reversal-documents` yêu cầu `inventory.view` (không phải `inventory.reversal.post`),
và §3 row "Inventory query/trace/reversal" cho `V` với cả ba actor tương ứng.

## 3. Fallback error code của middleware — BE267-D02

Lượt đo **trước khi sửa** (`ApiConventionMiddleware` nguyên bản):

```
400 | Không đủ tồn kho để xuất.        => INSUFFICIENT_STOCK   <-- lệch §8.2
400 | Tham số sai.                     => BAD_REQUEST
422 | Không đủ tồn kho.                => INSUFFICIENT_STOCK
409 | Bản ghi đã tồn tại.              => DUPLICATE_RESOURCE
405 | Method không hỗ trợ.             => METHOD_NOT_ALLOWED
401 | Chưa đăng nhập.                  => UNAUTHENTICATED
403 | Không có quyền.                  => ACCESS_DENIED
```

§8.2 xếp `INSUFFICIENT_STOCK` ở hàng **422**. Nhánh 400 suy mã từ chuỗi tiếng Việt
trong `message`, nên bất kỳ response 400 nào có chữ "tồn kho" / "stock" / "không đủ"
đều trở thành `400 INSUFFICIENT_STOCK` — vi phạm đúng câu ràng buộc của dòng Envelope
("HTTP and business code must match Annex/API contract"). Không có call site nghiệp vụ
nào đang phát INSUFFICIENT_STOCK ở 400 (8/8 điểm phát thật đều dùng 422), nên đây là
đường rò chưa nổ, không phải sự cố đang xảy ra → **P2**.

**Sau khi sửa**: nhánh 400 giữ đúng một mã `BAD_REQUEST`.

**Kiểm chứng cổng có tác dụng thật (mutation check).** Tạm khôi phục nhánh suy đoán
rồi chạy lại đúng bộ test:

```
{"tool":"phpunit","result":"failed","tests":8,"passed":7,"failed":1,
 "failures":[{"test":"...::test_middleware_fallback_never_pairs_a_reserved_code_with_a_wrong_status",
 "message":"Fallback ghép mã dành riêng INSUFFICIENT_STOCK với HTTP 400, trong khi §8.2 xếp mã này ở HTTP 422 ..."}]}
```

Khôi phục lại bản đã sửa → `8 passed, 120 assertions`. Cổng bắt được đúng lỗi mà nó
tuyên bố canh, không phải test luôn xanh.

## 4. Bức tranh mã lỗi toàn hệ thống — cơ sở của BE267-D01

Từ `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.json` (sinh từ source):

- **247** mã lỗi, trải trên **12** HTTP status: 400, 401, 403, 404, 405, 409, 412,
  422, 428, 429, 500, 503. SRS §8.2 chỉ có row cho **9** status.
- **3** mã dùng hai status khác nhau:
  `PHYSICAL_CODE_NOT_FOUND` (404|422), `SKU_NOT_FOUND` (404|422),
  `PHYSICAL_CODE_NOT_ACTIVE` (409|422).
- Hạng 403 gồm 4 mã: `ACCESS_DENIED`, `DATA_SCOPE_DENIED`, `PERMISSION_DENIED`,
  `WAREHOUSE_SCOPE_DENIED`.

Ba mã dual-status có lý do tầng (path resource ⇒ 404/409, giá trị trong body ⇒ 422)
nên được chấp nhận **có điều kiện** và ghi vào `dualStatusCodes`; điều kiện là không
được mở sang status thứ ba, và không mã nào khác được âm thầm gia nhập nhóm này.

## 5. Kết quả bộ test đóng

```
php artisan test tests/Feature/Wwhn267ContractClosureTest.php
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":120,"duration_ms":10526}
```

```
npx vitest run src/shared/api/__tests__/wwhn-267-forbidden-code-contract.test.ts   # repo FE
Test Files  1 passed (1) · Tests  7 passed (7)
```

## 6. Ghi chú môi trường

`php artisan test --testsuite=Feature` trên cây làm việc hiện tại **không chạy được**:
`tests/Feature/Wwhn310Round2ClosureTest.php` (thuộc `WWHN-310`, đang dở của phiên làm
việc khác trên cùng `m1`) khai `json()` ở mức protected trong khi lớp cha
`Illuminate\Foundation\Testing\TestCase` yêu cầu public, gây fatal error lúc nạp lớp
và làm hỏng cả suite. WWHN-267 **không sửa file của ticket khác**; các lượt chạy ở
trên dùng đường dẫn file cụ thể để tránh nạp lớp đó. Baseline toàn suite phải chạy lại
sau khi `WWHN-310` đóng.
