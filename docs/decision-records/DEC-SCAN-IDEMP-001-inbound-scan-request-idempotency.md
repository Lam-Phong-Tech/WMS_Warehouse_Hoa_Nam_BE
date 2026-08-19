# DEC-SCAN-IDEMP-001 — Ngữ nghĩa retry/recovery cho `POST /inbound-documents/{id}/scan`

| Trường | Giá trị |
|---|---|
| Decision ID | `DEC-SCAN-IDEMP-001` |
| Ngày lập | 2026-08-19 |
| Task | `WWHN-188` — [DEV] Rà soát giao dịch/khóa đồng thời (HN-IN-002, BE1) |
| Review item đóng | `DEV188-P1-03R2` — *OPEN — VERIFIED DEFECT* |
| Người soạn | BE1 (DEV) — **DEV không tự ký duyệt** |
| Owner quyết định | BA + Tech Lead (semantics) |
| Người thực thi | BE1 (BE), FE1 (client) |
| Trạng thái | **PROPOSED — chờ BA + Tech Lead sign-off**; code đã hiện thực đúng nội dung dưới đây và có thể revert nguyên khối nếu quyết định khác |
| Nguồn kiểm soát | SRS/FRD v1.12 §5 (Inbound), §8.1 Annex C, §8.2 Error contract, §13.3, NFR-SRS-001 |

---

## 1. Vấn đề (đã được chứng minh, không phải suy đoán)

Reviewer xác nhận đây là **defect thật**, không phải quan sát lý thuyết:

> `DEV188-P1-03R2` — *"BA/Tech chọn semantics. Khuyến nghị request-level idempotency/dedupe có
> deterministic fingerprint thay vì time-window heuristic. […] final retry must not double-count."*

Bước scan được bảo vệ bằng optimistic lock `If-Match` cấp document. Guard đó chặn được **replay cũ**
(client gửi lại version đã lỗi thời → 412 `STALE_VERSION`), nhưng không chặn được **replay mới**:

1. Client gửi scan `qty=2` với `If-Match: 1`. Server ghi xong: `scanned_qty` 0 → 2, version 1 → 2.
2. Response rơi trên đường về (mạng kho, thiết bị cầm tay, proxy timeout).
3. Client tải lại phiếu, thấy version **2**, tưởng lần quét trước trượt.
4. Client gửi lại đúng hành động đó với `If-Match: 2` — version hoàn toàn hợp lệ.
5. Server không có căn cứ nào để phân biệt với một lần quét thật thứ hai → `scanned_qty` = **4**.

Phạm vi thủng chính xác:

| Nhánh scan | Guard hiện có | Có thủng không |
|---|---|---|
| PRODUCT / serial-tracked (`item_id` có giá trị) | `assertNoDuplicateItemInDocument()` + row lock `item_instances` → 409 `DUPLICATE_ITEM_IN_DOCUMENT` | **Không** — dedupe tuyệt đối theo item |
| COMPONENT quantity-mode (không có `item_id`) | chỉ `If-Match` | **Có** — cộng dồn `scanned_qty` lần thứ hai |

Hậu quả nghiệp vụ: `scanned_qty` sai làm sai điều kiện Post (Post chỉ mở khi mọi dòng
`scanned_qty = qty_planned`), và nếu vượt `qty_planned` thì người vận hành bị chặn Post mà không hiểu vì sao.
Scan là stock-neutral nên **không** làm sai tồn kho trực tiếp — đây là lý do mức độ là P1 chứ không phải P0.

## 2. Các phương án đã cân nhắc

| # | Phương án | Kết luận |
|---|---|---|
| A | Giữ nguyên `If-Match`, chấp nhận rủi ro | **Loại.** Reviewer đã phân loại VERIFIED DEFECT; giữ nguyên nghĩa là đóng task bằng cách khai báo rủi ro, không phải bằng cách sửa. |
| B | Dedupe theo **cửa sổ thời gian** (ví dụ: chặn scan trùng nội dung trong 5 giây) | **Loại.** Reviewer nói rõ *"thay vì time-window heuristic"*. Cửa sổ thời gian vừa lọt (retry chậm hơn cửa sổ) vừa chặn nhầm (hai lần quét thật liên tiếp nhanh hơn cửa sổ), và không tái lập được trong test. |
| C | Dedupe theo **nội dung thuần** (hash `line_id`+`qty`+`code`+`box`, không có danh tính request) | **Loại.** COMPONENT quantity-mode quét 2 cái rồi quét tiếp 2 cái nữa trên cùng dòng/cùng BOX là nghiệp vụ bình thường — phương án này sẽ nuốt oan lần quét thứ hai. |
| D | **Request-level idempotency với fingerprint tất định** | **Chọn.** Danh tính request do client cấp (`Idempotency-Key`), nội dung request được chuẩn hoá thành fingerprint tất định. Không dùng thời gian, không nuốt lần quét thật. |
| E | Bắt buộc `Idempotency-Key` (428 nếu thiếu) | **Loại ở MVP.** Annex C §8.1 chỉ khai `Idempotency-Key` bắt buộc cho **Full Post** (`post-receipt`), scan là stock-neutral. Bắt buộc sẽ phá client hiện hành và lệch contract đã ký. Ghi nhận là ứng viên siết ở bản contract sau. |

## 3. Quyết định

**Phương án D.** `POST /api/v1/inbound-documents/{id}/scan` (và alias `/scans`) nhận `Idempotency-Key`
**tùy chọn**, xử lý theo đúng `IdempotencyStore` dùng chung của hệ thống, với `request_hash` là một
**fingerprint tất định trên nội dung scan**.

### 3.1 Ma trận ngữ nghĩa (đây là phần BA/Tech Lead ký)

| Tình huống | `Idempotency-Key` | Nội dung scan | Kết quả | `scanned_qty` |
|---|---|---|---|---|
| Lần gửi đầu | có | — | 200, `data.replayed=false`, header `Idempotent-Replay: false` | +qty |
| Lost-response retry (cùng `If-Match` cũ) | **cùng key** | **giống** | 200, `replayed=true` | **không đổi** |
| Stale retry (version đã bị writer khác đẩy lên) | **cùng key** | **giống** | 200, `replayed=true` | **không đổi** |
| Re-fetch retry (`If-Match` mới, hợp lệ) | **cùng key** | **giống** | 200, `replayed=true` | **không đổi** |
| Cùng key, đổi nội dung (đổi qty/line/box/item) | **cùng key** | **khác** | **409 `IDEMPOTENCY_CONFLICT`** | không đổi |
| Cùng key đang được xử lý bởi request khác | **cùng key** | giống | **409 `CONCURRENCY_CONFLICT`** | không đổi |
| Lần quét thật thứ hai | **key mới** | giống | 200, `replayed=false` | +qty |
| Client cũ không gửi key | không có | — | 200 như trước, **không 428** | +qty |
| Key sai độ dài (<8 hoặc >100 sau trim) | có | — | **422 `VALIDATION_ERROR`** | không đổi |
| Request lỗi (422/409/412) | có | — | lease bị xoá; **cùng key dùng lại được** sau khi client sửa dữ liệu | không đổi |

Mọi mã 409 nằm đúng trong tập cho phép của SRS §8.2
(`STATE_CONFLICT` / `IDEMPOTENCY_CONFLICT` / `CONCURRENCY_CONFLICT` / `DOWNSTREAM_DEPENDENCY_EXISTS`).

### 3.2 Định nghĩa fingerprint (phần "deterministic" mà reviewer yêu cầu)

`App\Support\ScanRequestFingerprint::for()`:

```
scanfp1:sha256(json({
  fp, operation, document_id, line_id, quantity, code_value, item_id, container_id
}))
```

Ràng buộc thiết kế, mỗi cái đóng một cách hỏng cụ thể:

| Ràng buộc | Đóng cách hỏng nào |
|---|---|
| **Không** chứa version / `If-Match` | Nếu chứa thì re-fetch retry (version 1 → 2) ra fingerprint khác và defect vẫn còn nguyên. Đây là điểm cốt lõi. |
| **Không** chứa mốc thời gian, không có TTL nghiệp vụ | Loại bỏ hoàn toàn heuristic cửa sổ thời gian. |
| `qty` và `quantity` chuẩn hoá về **một** trường số nguyên | Retry đổi alias (Annex C vs legacy) không bị 409 sai. |
| Thứ tự khoá cố định, chuỗi rỗng chuẩn hoá về `null` | Hai payload tương đương JSON khác thứ tự không sinh 409 sai. |
| **Loại** `printer_config_id` | Đó là gợi ý thiết bị in nhãn, không phải nội dung được cộng vào `scanned_qty`; đổi máy in giữa hai lần retry không được thành xung đột payload. |
| Prefix phiên bản `scanfp1:` | Đổi tập trường sau này không so nhầm với hash cũ. |

### 3.3 Trách nhiệm phía client (FE1)

- Sinh **một** `Idempotency-Key` cho **một hành động quét**; mọi lần gửi lại của đúng hành động đó
  dùng lại **đúng** key cũ.
- Không bao giờ retry bằng key mới — trùng đúng điều `AC-GWT-CONC-001` đã cấm và trùng hợp đồng
  `concurrency-contract.ts` (`reuseIdempotencyKey`).
- Nhiều mã trong một lần gọi = nhiều hành động quét: mỗi mã một key riêng.
- `data.replayed=true` phải được phản ánh lên UI (không cộng thêm vào bản sao `scanned_qty` cục bộ,
  và nói rõ cho người vận hành để họ không quét bù).

## 4. Phạm vi cố ý KHÔNG mở rộng

- **Outbound scan** (`AlignedOutboundFlowController::outboundScan`) không đổi trong quyết định này.
  Outbound có tác dụng phụ tạo/gửi nhãn và thuộc luồng WWHN-46/Flow 03 đã đóng — mở rộng ở đây sẽ
  chạm contract của task khác. Ghi nhận là open point `BE1-SCANCONC-OP-05` (mục 6).
- **`inbound-documents/{id}/unscan`** không đổi: thao tác gỡ mang `inbound_scan_entry_id` cụ thể, gỡ
  hai lần cùng một entry đã bị chặn bằng state `ACTIVE → REMOVED`.
- **Không** đổi bất kỳ ngữ nghĩa nào của `If-Match`, row lock, hay guard trạng thái. Lớp idempotency
  nằm **trên** chúng, không thay thế.

## 5. Bằng chứng thực thi

| Bằng chứng | Vị trí |
|---|---|
| Hiện thực | `app/Support/ScanRequestFingerprint.php`, `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController::inboundScan()` |
| Contract test (10 case, chạy thật qua HTTP + kiểm ở tầng DB) | `tests/Feature/Wwhn188ScanIdempotencyContractTest.php` |
| Bằng chứng defect cũ được chuyển thành bằng chứng đã sửa | `tests/Feature/InboundScanConcurrencyBehaviorTest::test_component_quantity_mode_refetch_retry_is_deduped_by_idempotency_key` |
| Race thật 2 tiến trình PostgreSQL với cùng một key | `scripts/wwhn188-inbound-scan-runtime-concurrency.php` kịch bản `same_idempotency_key_quantity_mode` |
| OpenAPI | `Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php` (`operationId: stock_in_scan`); bản trích + hash: `docs/evidence/WWHN-188/openapi/` |
| Client | `src/modules/inbound/services/inboundService.ts` (FE, `scanInboundDocument`) |

## 6. Open point phát sinh

| ID | Nội dung | Owner | Trạng thái |
|---|---|---|---|
| `BE1-SCANCONC-OP-05` | Outbound scan có cùng hình dạng gap (label dispatch là tác dụng phụ không idempotent). Cần Tech Lead quyết định có áp cùng cơ chế không, trong phạm vi Flow 03 chứ không phải WWHN-188 | Tech Lead | Mở, đã ghi nhận |
| `BE1-SCANCONC-OP-06` | Có siết `Idempotency-Key` thành bắt buộc cho scan ở bản contract sau MVP không (khi mọi client đã gửi key) | BA + Tech Lead | Mở, không chặn release |

## 7. Điều kiện đảo quyết định

Nếu BA/Tech Lead chọn phương án khác, phần cần revert là **một khối liền**: `ScanRequestFingerprint`,
đoạn `IdempotencyStore::handle` trong `inboundScan()`, annotation OpenAPI, và
`Wwhn188ScanIdempotencyContractTest`. Không có migration, không có thay đổi schema, không có dữ liệu
phải dọn — bảng `idempotency_keys` đã tồn tại và dùng chung cho mọi operation.
