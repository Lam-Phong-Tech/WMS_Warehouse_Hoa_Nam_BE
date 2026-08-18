# DEC-STK-002 — Canonical stock write path và disposition legacy stock classes

## Document control

| Field | Value |
|---|---|
| Decision ID | `DEC-STK-002` |
| Jira parent | `WWHN-218` |
| Backlog item | `DEV218-P2-01` |
| Version | `1.0.0` |
| Status | **PROPOSED** |
| Proposed by | BE1 — Quang Nguyễn Thanh |
| Proposed date | `2026-08-17` |
| Required approvers | Tech Lead |
| Effective date | `NULL — chỉ có sau formal sign-off` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` |
| Supersedes | `docs/evidence/WWHN-218/P2-01_LEGACY_RUNTIME_DISPOSITION.md` (DEV evidence) |

Tài liệu này là **bản nháp Decision Record**. Không được diễn giải là `APPROVED` trước khi Tech Lead ghi kết quả trên Jira với exact commit SHA.

## Context

Câu hỏi "giữ hay dọn `StockDocumentService` / `CurrentBaselineStockController`" đã được nêu lại ở **WWHN-190, 195, 205, 207 và 218** — năm lần rà soát, không lần nào đóng được. Mỗi lần đều kết thúc bằng một đoạn văn "đề xuất xác nhận với team", và lần rà soát sau lại phải chạy lại `route:list` để chứng minh lại cùng một mệnh đề phủ định.

Đây là lý do gốc rễ khiến lỗi lặp lại: **disposition bằng văn bản không để lại thứ gì cho CI kiểm tra.**

Rà soát lần này (DEV218-P2-01) xác định hai class bị gộp chung tên gọi "legacy" nhưng có bản chất **hoàn toàn khác nhau**, và việc gộp chung đó chính là nguồn nhầm lẫn kéo dài:

### `CurrentBaselineStockController` — dead code thật, có rủi ro tiềm ẩn

- **Zero call site** trên toàn repo (chỉ khớp chính dòng khai báo class).
- Không route nào bind tới — đã xác minh bằng `route:list` ở WWHN-188 và bằng phân tích nguồn ở task này.
- Là **bản sao gần đúng của write path**: có `postReceipt`, `postIssue`, `reverseInbound`, `reverseOutbound`, `inboundScan`, `outboundScan`.
- **Thiếu toàn bộ control WWHN-218.** Đối chiếu với `CurrentBaselineStockFlowController`:

| Control | StockFlowController (canonical) | StockController (đã xoá) |
|---|---|---|
| `If-Match` / optimistic version | có (`expectedVersion`, `assertVersion`) | **không** |
| `lockDocument` (`FOR UPDATE`) | có | **không** |
| Label-printed gate trước Post Issue | có | **không** |
| Duplicate-item guard | có | **không** |
| 401 khi actor null | có | **không** (trả `?string`) |
| Physical-return gate cho Reverse Issue | qua `CurrentBaselineReversalService` | có, nhưng là nhánh tự chế |

Tên class là nguồn nhầm lẫn chính: tiền tố `CurrentBaseline...` khiến nó *trông* như đường chạy chuẩn, nên mỗi vòng audit lại phải mở ra kiểm tra xem có phải endpoint sống với guard yếu hơn không.

**Rủi ro thật:** class này không nguy hiểm khi nằm yên, nhưng chỉ cần **một** dòng route trỏ nhầm vào nó là toàn bộ hàng rào concurrency/idempotency của WWHN-218 bị vô hiệu trên đúng những endpoint đổi tồn kho. Đó là rủi ro không có lợi ích bù lại, vì không ai đang dùng nó.

### `StockDocumentService` + `Inbound/OutboundDocumentController` — legacy còn sống, phải giữ

- `InboundDocumentController` / `OutboundDocumentController` **có route thật**, nhưng chỉ `GET index` và `GET show` (`routes/api.php:62,65,81,83`).
- `StockDocumentService` còn được tham chiếu bởi `StockRequestService::submit()` và `TransferController` (`create`/`update`).
- `InteractsWithStockDocuments` đã được thu hẹp chỉ còn `index`/`show` + helper, kèm docblock nói rõ mutation không nằm ở đây.
- `TransferController` và `StockRequestController` hiện **không có route nào** — nhưng chúng là code nghiệp vụ chuyển kho/yêu cầu kho chưa wire, không phải bản sao write path.

Nghĩa là: đây **không** phải dead code, và xoá đi sẽ làm hỏng read path đang phục vụ FE.

## Decision drivers

1. Không được tồn tại hai đường ghi tồn kho song song với mức guard khác nhau.
2. Không được xoá code đang phục vụ route sống chỉ để "cho sạch".
3. Quyết định phải tự bảo vệ được — không phụ thuộc vào việc người review sau có đọc doc hay không.
4. Không thay đổi API contract, permission, schema trong phạm vi task hardening.

## Proposed decision

### 1. XOÁ `CurrentBaselineStockController`

Xoá `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockController.php`.

Cơ sở: zero call site, zero route, và là bản sao write path thiếu toàn bộ control WWHN-218. Điều kiện "zero-call-site migration" mà disposition trước đặt ra **đã thoả mãn** — không cần migration vì không có call site nào để migrate.

### 2. GIỮ `StockDocumentService` + `Inbound/OutboundDocumentController` + `InteractsWithStockDocuments`

Giữ nguyên, phân loại chính thức là **read-side / compat**, không phải dead code. Ràng buộc kèm theo:

- Chỉ được bind vào route đọc (`index`, `show`).
- Không được mọc lại method đổi tồn kho.

### 3. Canonical write path (ràng buộc thường trực)

Mọi mutation inbound/outbound đi qua:

```text
CurrentBaselineStockFlowController
  -> CurrentBaselineStockService / CurrentBaselineReversalService
  -> IdempotencyStore
```

`AlignedOutboundFlowController` được phép giữ alias `outbound-documents/{id}/scans` vì trỏ thẳng handler thật (xem chú thích `routes/api.php:243`).

### 4. Guard thực thi — phần chặn lỗi lặp lại

Thêm `scripts/verify-canonical-stock-write-path.php`, chạy trong `.github/workflows/wwhn-218-hardening-evidence.yml`. Guard fail-closed khi:

| # | Điều kiện fail | Chặn kịch bản |
|---|---|---|
| 1 | `CurrentBaselineStockController.php` xuất hiện lại | Revert/cherry-pick vô tình mang lại |
| 2 | Còn tham chiếu tới class đã xoá | Xoá file nhưng sót import |
| 3 | Route mutation trỏ vào controller ngoài write path chuẩn | **Kịch bản nguy hiểm nhất** — endpoint sống với guard yếu |
| 4 | Controller read-side bị bind vào verb ghi | Legacy bị tái kích hoạt qua route |
| 5 | Method đổi tồn kho mọc trên class read-side | Guard bị bào mòn dần |

Cả 5 nhánh fail đã được kiểm chứng bằng negative test (cố tình tái tạo vi phạm, xác nhận exit code 1), không chỉ chạy happy path.

## API and data impact

- API contract: **NONE** — class bị xoá không phục vụ endpoint nào.
- Permission: **NONE**.
- Database/schema: **NONE**.
- FE mapping: **NONE**.
- Route surface: **NONE** — `route:list` trước/sau không đổi.

## Consequences

### Positive

- Loại bỏ vĩnh viễn khả năng route trỏ nhầm vào write path thiếu guard.
- Câu hỏi "giữ hay dọn" không còn phải trả lời lại ở audit sau: CI trả lời.
- Ranh giới read-side / write-path trở thành ràng buộc chạy được, không còn là quy ước truyền miệng.
- Giảm ~281 dòng code gây nhiễu cho mọi lần rà soát bảo mật sau này.

### Negative / cost

- Thêm một guard phải bảo trì; nếu sau này đổi tên controller chuẩn phải cập nhật allowlist trong script.
- Guard phân tích nguồn theo regex route, nên route đăng ký động (nếu có sau này) sẽ không được phủ. Hiện tại `routes/api.php` khai báo tĩnh 100% nên không phải hạn chế thực tế.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Class được revert vô tình | Guard check 1 + 2 fail-closed |
| Route mới trỏ sai controller | Guard check 3 chặn tại CI trước merge |
| Legacy read-side bị mở rộng thành write | Guard check 4 + 5 |
| Còn caller ẩn ngoài phạm vi quét | Đã quét `Modules/`, `app/`, `routes/`, `tests/`, `config/`, `database/`; kết quả zero |

## Impacted tickets

- `WWHN-218` / `DEV218-P2-01` — task này
- `WWHN-190`, `WWHN-195`, `WWHN-205`, `WWHN-207` — các lần nêu lại trước đó; mục dead-code kế thừa có thể đóng theo DR này
- `WWHN-188` — đã xác minh không phải rủi ro route thật; DR này loại bỏ luôn nguồn nghi vấn

## Approval and effective status

Current result: **PROPOSED / NO EFFECTIVE DATE**.

Tech Lead ghi một trong hai kết quả trên `WWHN-218`:

```text
DEC-STK-002 version: 1.0.0
Exact BE commit SHA: <sha>
Tech Lead decision: <GO|CONDITIONAL GO|NO-GO>
Effective date: <ISO-8601 or NULL>
Conditions/owner/deadline: <list or NONE>
```

- `GO` — xác nhận xoá `CurrentBaselineStockController`, giữ read-side legacy, canonical write path được guard bảo vệ.
- `CONDITIONAL GO` / `NO-GO` — nêu rõ class/call-site cụ thể cần xử lý khác.

Guard ở mục 4 đã active và không phụ thuộc trạng thái phê duyệt: kể cả khi DR còn `PROPOSED`, CI vẫn chặn việc tái xuất hiện write path thiếu guard.
