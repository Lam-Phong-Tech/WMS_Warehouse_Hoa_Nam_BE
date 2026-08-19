# WWHN-187 — Khoá hợp đồng bước quét nhập (`DEV187-P1-05R`)

## 1. Hai việc phải đóng

1. **Ngữ nghĩa audit của scan** — `scan_event`/`scan_batch` đã đủ hay cần
   `AuditLogger`? → chốt tại `docs/decision-records/DEC-SCAN-AUDIT-001-scan-evidence-vs-audit-log.md`:
   **đủ, không thêm `audit_log` cho từng lần quét**. Đây cũng là lời đóng cho open
   point `BE2-INSCAN-OP-01`.
2. **Khoá contract + xác nhận consumer dùng cùng một revision.** Mục 2–4 dưới đây.

## 2. Contract của endpoint quét — trạng thái đã khoá

Hai alias cùng handler `CurrentBaselineStockFlowController::inboundScan()`:

| Path | operationId | Header | Response |
|---|---|---|---|
| `POST /api/v1/inbound-documents/{id}/scan` | `stock_in_scan` | `If-Match` **bắt buộc**, `Idempotency-Key` **tùy chọn** | 200, 400, 401, 403, 404, 409, 412, 422, 428, 500 |
| `POST /api/v1/inbound-documents/{id}/scans` (Annex C canonical) | `post_api_v1_inbound__documents_id_scans` | như trên | như trên |

Điểm bắt buộc phải giữ, đã được `scripts/verify-wwhn-187-closure.mjs` canh:

- `If-Match` là `required: true`. Thiếu → 428 `PRECONDITION_REQUIRED`.
- `Idempotency-Key` **không** được đặt `required: true`. Annex C dòng
  `scanInboundDocument` chỉ khai `If-Match`, và scan là stock-neutral nên không thuộc
  phạm vi bắt buộc idempotency của `NFR-SRS-001`. Đặt required sẽ biến mọi client cũ
  thành 428 — đó là contract break, không phải bug fix.
- Có đủ 409 / 412 / 422 / 428 trong danh sách response.

Ngữ nghĩa khi client **có** gửi key (`DEC-SCAN-IDEMP-001`):

| Tình huống | Kết quả |
|---|---|
| Cùng key + cùng nội dung quét | 200, `data.replayed=true`, header `Idempotent-Replay: true`, `scanned_qty` **không** tăng, version **không** bump — kể cả khi `If-Match` là version mới sau re-fetch |
| Cùng key + khác nội dung quét | 409 `IDEMPOTENCY_CONFLICT` (SRS §8.2) |
| Hai retry cùng key chạy song song | một thắng, một nhận 409 `CONCURRENCY_CONFLICT` |
| Key khác + cùng nội dung | lần quét thật thứ hai, cộng dồn bình thường |
| Key ngắn/dài sai (<8 hoặc >100 ký tự) | 422 `VALIDATION_ERROR` |
| Request lỗi (ví dụ 422 vượt Expected) | key **không** bị "nhiễm độc" — sửa dữ liệu rồi gửi lại đúng key cũ vẫn chạy |

"Nội dung quét" = fingerprint tất định gồm `line_id`, `quantity`, `code_value`,
`item_id`, `container_id`. **Không** gồm version và **không** gồm mốc thời gian —
đây là điều kiện để một retry sau re-fetch vẫn ra đúng fingerprint cũ. `qty` và
`quantity` là alias của cùng một trường nên không được coi là hai payload khác nhau;
`printer_config_id` là gợi ý thiết bị in, không thuộc nội dung được ghi nhận.

## 3. Cách khoá (pin theo tập operation, không pin cả file)

`docs/evidence/WWHN-187/WWHN-187_CONTRACT_PIN.json` giữ **digest SHA-256 của riêng hai
operation quét** trích từ `storage/api-docs/api-docs.json`, chuẩn hoá canonical JSON
(sort key) trước khi băm.

Lý do không pin hash cả file: `api-docs.json` được sinh lại mỗi khi *bất kỳ* controller
nào đổi annotation, mà `m1` có nhiều task song song — pin cả file sẽ đỏ vì lý do không
liên quan tới WWHN-187, và người sau sẽ học cách bỏ qua gate. Pin đúng tập operation
thuộc phạm vi task thì chỉ đỏ khi hợp đồng quét thật sự đổi.

Sinh lại digest sau khi cố ý đổi contract:

```bash
node scripts/verify-wwhn-187-closure.mjs --write-pin
```

## 4. Ma trận consumer — ai đang dùng revision nào

| Consumer | Artifact | Trạng thái | Bằng chứng |
|---|---|---|---|
| BE (nguồn) | annotation `stock_in_scan` trong `Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php` → sinh ra `storage/api-docs/api-docs.json` | **LOCKED** | digest ở `WWHN-187_CONTRACT_PIN.json` |
| FE (Web WMS) | `src/modules/inbound/services/inboundService.ts` — luôn gửi `Idempotency-Key`, tách theo từng mã; `MvpInboundScreens.tsx` giữ và dùng lại key khi gửi lại | **CONSUMED** | `inboundService.test.ts` (4 test hợp đồng, xem mục 5); check `FE-SENDS-KEY` / `FE-KEY-PER-CODE` / `FE-REUSES-KEY-ON-RETRY` / `FE-SHOWS-REPLAY` của verifier |
| QA | `docs/postman/WWHN-187 Inbound Scan Retry.postman_collection.json` — 9 request, phủ đủ 4 kịch bản register yêu cầu | **READY** | `WWHN-187_QA_EXECUTION_HANDOFF.md` |
| Client khác (script cũ, mobile chưa cập nhật) | không gửi key | **BIÊN ĐÃ GHI NHẬN** | không 428, nhưng cũng không được bảo vệ khỏi double-count — xem mục 6 |

## 5. Test hợp đồng đang canh contract này

Backend (`php vendor/bin/phpunit`):

- `Wwhn188ScanIdempotencyContractTest` — 10 case: lost-response retry, re-fetch retry,
  chuỗi retry xen kẽ version, cùng key khác quantity → 409, alias `qty`/`quantity`,
  key khác cùng payload, không key → không 428, key quá ngắn → 422, key không bị nhiễm
  độc sau lỗi, nhánh serial replay thay vì 409 trùng item.
- `InboundScanConcurrencyBehaviorTest::test_component_quantity_mode_refetch_retry_is_deduped_by_idempotency_key`
  — giữ nguyên chuỗi thao tác của bằng chứng defect gốc.
- `Wwhn187InboundScanValidationTest` — 27 case validation/permission/evidence.

Frontend (`npx vitest run --pool=threads src/modules/inbound`):

- gửi `Idempotency-Key` kèm `If-Match` ở cả nhánh có `code_value` lẫn nhánh COMPONENT
  quantity-mode;
- tách key theo chỉ số mã (`:0`, `:1`) trong một lần gọi nhiều mã;
- `replayed=true` không cộng vào `scanned_qty` cục bộ;
- 409 `CONCURRENCY_CONFLICT` của bước quét được phân loại `RELOAD_THEN_RETRY` +
  `reuseIdempotencyKey=true`.

## 6. Biên còn lại (cần BA/Tech biết khi ký)

### 6.1 Client không gửi key

Client **không** gửi `Idempotency-Key` vẫn cộng dồn khi gửi lại — server không có căn cứ
phân biệt với một lần quét thật thứ hai, và hai lần quét thật liên tiếp cùng nội dung là
nghiệp vụ hợp lệ của COMPONENT quantity-mode. Đây là ràng buộc cố ý của
`DEC-SCAN-IDEMP-001`, không phải sót. Điều kiện để biên này an toàn: **mọi consumer
chính thức đều gửi key** — đã khoá bằng ma trận mục 4 + verifier. Consumer mới phải được
thêm vào bảng đó trước khi lên production.

## 7. Phát hiện ngoài phạm vi WWHN-187, đã bàn giao (không giấu)

Khi khoá contract, verifier bắt được một lệch **thật** giữa contract công bố và hành vi
runtime, nhưng chủ sở hữu không phải WWHN-187:

> `storage/api-docs/api-docs.json` hiện có **58 operation khai header `If-Match`**, trong
> đó chỉ **6 operation publish `required: true`** — đúng 6 operation được viết tay bằng
> OA attribute trong `InboundDocumentController.php`. **52 operation còn lại**, tức toàn
> bộ nhóm do `app/Swagger/Processors/RouteSyncProcessor.php` tự sinh (bao gồm alias
> canonical `POST /inbound-documents/{id}/scans` mà **FE đang thật sự gọi**), publish
> `required: false` — dù runtime trả 428 `PRECONDITION_REQUIRED` khi thiếu header.

Đây chính xác là lỗi mà comment `WWHN-266 (BE266-D01)` trong `RouteSyncProcessor` mô tả
là *đã sửa*. Hai khả năng, phải phân biệt bằng một lệnh:

```bash
php artisan l5-swagger:generate
git diff --stat storage/api-docs/api-docs.json
```

- Nếu sau khi sinh lại, 52 operation chuyển sang `required: true` → artifact
  `api-docs.json` đang **stale** (được commit trước khi `WWHN-266` landed). Việc cần làm
  là sinh lại và commit.
- Nếu vẫn `required: false` → logic phân biệt bắt buộc/tuỳ chọn trong
  `actionContractFlags()` đã **regress**, phải mở lại `WWHN-266`.

**Vì sao WWHN-187 không tự sửa:** `storage/api-docs/*` là artifact sinh tự động cho toàn
hệ thống và đang nằm trong working tree của nhánh công việc `WWHN-309`; sinh lại từ đây
sẽ kéo thay đổi của 50+ operation không liên quan vào diff của task này và đè lên công
việc đang dở của họ. Ảnh hưởng tới WWHN-187 là **không có**: hành vi runtime đúng, test
hợp đồng phủ đủ, và consumer FE/QA đều đã gửi `If-Match`.

Verifier in dòng này ở mục **WARN** (không đánh đỏ gate của WWHN-187) kèm tên chủ sở
hữu, để nó không bị trôi mất giữa hai task.
