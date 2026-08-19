# WWHN-188 — QA candidate test matrix cho `POST /inbound-documents/{id}/scan`

| Trường | Giá trị |
|---|---|
| Review item đóng | `DEV188-P1-04R2` (P1, OPEN) — gate QA |
| Yêu cầu nguyên văn | *"QA1 exact candidate: 401/403/409/412/422/428, duplicate, serial, quantity, actual race, lost response retry, rollback/no side-effect"* |
| Owner ký | **QA1** (nghiệm thu) — BE1 chỉ cung cấp candidate và bằng chứng chạy |
| Closure evidence yêu cầu | Versioned QA report + build/SHA + DB evidence + defect disposition |
| Candidate SHA | đọc tại `docs/evidence/WWHN-188/WWHN-188_CANDIDATE_PIN.json → candidate.commit_sha` |
| Endpoint | `POST /api/v1/inbound-documents/{id}/scan` và alias Annex C `/scans` (cùng handler, cùng middleware) |

> Đây là **candidate** để QA1 chạy và ký, không phải QA report. Bảng dưới ánh xạ **từng ô reviewer yêu
> cầu** sang đúng một test đang tồn tại trong repo, chạy được tại candidate SHA. Không ô nào để trống,
> không ô nào chỉ có mô tả mà thiếu test.

---

## 1. Ma trận bắt buộc theo reviewer

### 1.1 Bộ mã HTTP (`REQ-SRS-024`, `BR-API-ERR-01..09`, SRS §8.2)

| HTTP | `error.code` | Test | File |
|---|---|---|---|
| **401** | `UNAUTHENTICATED` | `test_scan_requires_authentication` | `tests/Feature/Wwhn187InboundScanValidationTest.php` |
| **403** | `ACCESS_DENIED` | `test_scan_requires_inbound_scan_permission` (role `WAREHOUSE_APPROVER` không có `inbound.scan`) | như trên |
| **404** | `RESOURCE_NOT_FOUND` | `test_scan_returns_404_for_unknown_document` | như trên |
| **404** | `DOCUMENT_LINE_NOT_FOUND` | `test_scan_rejects_unknown_line_id` | như trên |
| **409** | `STATE_CONFLICT` | `test_scan_rejects_document_not_in_draft_or_scanning_state` | như trên |
| **409** | `DUPLICATE_ITEM_IN_DOCUMENT` | `test_scanning_same_item_twice_into_same_inbound_document_is_rejected` | như trên |
| **409** | `ITEM_ALREADY_RECEIVING` | `test_scanning_same_item_into_two_different_inbound_documents_is_rejected` | như trên |
| **409** | `BOX_NOT_RECEIVABLE` | `test_scan_rejects_box_not_in_receivable_state` | như trên |
| **409** | `BOX_IN_OTHER_ACTIVE_RECEIPT` | `test_scan_rejects_box_already_used_by_other_active_receipt` | như trên |
| **409** | `IDEMPOTENCY_CONFLICT` *(mới, DEC-SCAN-IDEMP-001)* | `test_same_key_with_different_quantity_is_rejected_with_409_idempotency_conflict` | `tests/Feature/Wwhn188ScanIdempotencyContractTest.php` |
| **409** | `CONCURRENCY_CONFLICT` *(mới)* | kịch bản race `same_idempotency_key_quantity_mode` — worker thua nhận đúng mã này | `docs/evidence/WWHN-188/runtime/runtime-concurrency-summary.json` |
| **412** | `STALE_VERSION` | `test_scan_rejects_stale_version_with_412` (If-Match sai tĩnh) + `test_stale_version_racer_is_rejected_after_winner_scan_commits` (race) | `Wwhn187InboundScanValidationTest.php`, `InboundScanConcurrencyBehaviorTest.php` |
| **422** | `VALIDATION_ERROR` (Idempotency-Key sai độ dài) | `test_too_short_idempotency_key_is_rejected_with_422` | `Wwhn188ScanIdempotencyContractTest.php` |
| **422** | `SCAN_EXCEEDS_REQUIRED` | `test_scan_rejects_quantity_exceeding_line_planned_qty` | `Wwhn187InboundScanValidationTest.php` |
| **422** | `SKU_NOT_ACTIVE` | `test_scan_rejects_inactive_sku` | như trên |
| **422** | `ITEM_REQUIRED` | `test_scan_product_line_without_item_is_rejected` | như trên |
| **422** | `SERIAL_QUANTITY_MUST_BE_ONE` | `test_scan_serial_tracked_line_rejects_quantity_greater_than_one` | như trên |
| **422** | `ITEM_SKU_MISMATCH` | `test_scan_rejects_item_belonging_to_different_sku` | như trên |
| **422** | `ITEM_NOT_RECEIVABLE` | `test_scan_rejects_item_not_receivable_when_already_in_stock` | như trên |
| **422** | `PHYSICAL_CODE_NOT_FOUND` | `test_scan_with_unknown_code_value_is_rejected`, `test_scan_with_inactive_code_value_is_rejected` | như trên |
| **422** | `PHYSICAL_CODE_ITEM_MISMATCH` | `test_scan_rejects_mismatched_item_id_and_code_value` | như trên |
| **422** | `COMPONENT_BOX_REQUIRED` / `COMPONENT_BOX_NOT_FOUND` / `BOX_SKU_MISMATCH` | `test_scan_rejects_component_line_without_box`, `test_scan_rejects_unknown_box_container_id`, `test_scan_rejects_box_with_mismatched_sku` | như trên |
| **422** | validation rỗng/0 | `test_scan_rejects_zero_quantity` | như trên |
| **428** | `PRECONDITION_REQUIRED` | `test_scan_requires_if_match_header` | như trên |

Mọi mã 409 xuất hiện ở trên đều nằm trong tập cho phép của SRS §8.2 hoặc là mã miền cụ thể của lớp
`STATE_CONFLICT` — FE đã map sẵn qua `src/shared/api/concurrency-contract.ts`.

### 1.2 Duplicate / serial / quantity

| Ô reviewer | Ý nghĩa | Test |
|---|---|---|
| **duplicate** — cùng phiếu | Quét lại đúng item đã quét trong chính phiếu đó | `test_scanning_same_item_twice_into_same_inbound_document_is_rejected` (409) |
| **duplicate** — khác phiếu | Cùng item bị quét vào hai phiếu nhập khác nhau | `test_scanning_same_item_into_two_different_inbound_documents_is_rejected` (409 `ITEM_ALREADY_RECEIVING`) |
| **duplicate** — BOX | Cùng BOX bị dùng ở hai phiếu còn hiệu lực | `test_scan_rejects_box_already_used_by_other_active_receipt` |
| **serial** | Dòng serial-tracked bắt buộc `item_id`, và `quantity` phải = 1 | `test_scan_product_line_without_item_is_rejected`, `test_scan_serial_tracked_line_rejects_quantity_greater_than_one` |
| **serial** — resolve theo mã vật lý | `code_value` phải resolve đúng item và ghi `inbound_scan_entries` | `test_scan_code_value_resolves_item_and_records_scan_entry` |
| **quantity** | COMPONENT quantity-mode: không vượt `qty_planned`, không nhận 0/âm | `test_scan_rejects_quantity_exceeding_line_planned_qty`, `test_scan_rejects_zero_quantity` |
| **quantity** — cộng dồn đúng | Hai lần quét thật liên tiếp cùng nội dung vẫn phải cộng dồn | `test_different_key_same_payload_is_a_genuine_second_scan` |

### 1.3 Actual race (≥2 connection/process PostgreSQL thật)

| Kịch bản | Tranh chấp trên | Kết quả bắt buộc | Nguồn |
|---|---|---|---|
| `same_document_same_line` | row lock `stock_documents` | đúng 1 winner (version +1, `scanned_qty` +1, đúng 1 scan entry); loser 412 `STALE_VERSION`, không side effect | `runtime/runtime-concurrency-summary.json` |
| `same_document_different_line` | row lock `stock_documents` | loser bị 412 dù dòng của nó chưa bị đụng ⇒ chứng minh khoá là **cấp document**; dòng của loser vẫn `scanned_qty=0` | như trên |
| `same_item_different_document` | row lock `item_instances` | đúng 1 winner; loser 409 `ITEM_ALREADY_RECEIVING`; đúng 1 scan entry cho item dùng chung | như trên |
| `same_idempotency_key_quantity_mode` *(mới)* | lease `idempotency_keys` | đúng 1 worker áp dụng (`replayed=false`); worker còn lại replay hoặc 409 `CONCURRENCY_CONFLICT`; `scanned_qty` +2 **đúng một lần**, version +1 đúng một lần, đúng 1 scan entry ACTIVE | như trên |

Mỗi kịch bản kèm: 2 `backend_pid` PostgreSQL phân biệt, mẫu `pg_locks`/`pg_stat_activity` thô, mốc thời
gian thô từng worker (chứng minh hai tiến trình **chồng lấn** thật), và snapshot DB `db_before`/`db_after`.

Gate tự động: `tests/Feature/InboundScanRuntimeConcurrencyTest.php` (bỏ qua nếu không phải PostgreSQL
hoặc thiếu `WWHN188_RUNTIME_CONCURRENCY=1`).

### 1.4 Lost-response retry / recovery (`DEC-SCAN-IDEMP-001`)

| Ô reviewer | Test | Kết quả bắt buộc |
|---|---|---|
| lost-response retry | `test_lost_response_retry_with_same_key_replays_and_does_not_double_count` | 200, `data.replayed=true`, header `Idempotent-Replay: true`, `scanned_qty` **không đổi** |
| stale retry | như trên (retry mang `If-Match` cũ sau khi version đã nhảy) | replay, không 412, `scanned_qty` không đổi |
| re-fetch retry | `test_refetch_retry_with_same_key_and_current_version_does_not_double_count` | replay, `scanned_qty` không đổi (trước fix là ×2) |
| **final retry must not double-count** | `test_repeated_mixed_version_retries_apply_the_scan_exactly_once` (6 lần gửi lại xen kẽ version cũ/mới) | `scanned_qty` đúng bằng **một** lần quét |
| same key / different payload | `test_same_key_with_different_quantity_is_rejected_with_409_idempotency_conflict` | 409 `IDEMPOTENCY_CONFLICT`, không ghi số lượng mới |
| alias `qty` vs `quantity` | `test_qty_alias_is_not_treated_as_a_different_payload` | replay, **không** 409 sai |
| nhánh serial | `test_serialized_item_retry_with_same_key_replays_instead_of_duplicate_conflict` | replay thay vì 409; không có key thì vẫn 409 `DUPLICATE_ITEM_IN_DOCUMENT` |
| client cũ không gửi key | `test_scan_without_idempotency_key_still_works_and_is_not_428` | 200, **không** 428 |

### 1.5 Rollback / no side-effect

| Ô reviewer | Test | Kiểm ở tầng DB |
|---|---|---|
| loser của race không để lại dấu vết | `test_stale_version_racer_is_rejected_after_winner_scan_commits` | `inbound_scan_entries` chỉ +1, dòng của loser `scanned_qty=0`, `stock_documents.version=2` |
| scan bị state guard chặn không tạo entry | `test_scan_with_current_version_on_posted_document_is_rejected_by_state_guard` | số `inbound_scan_entries` không đổi sau 409 |
| request lỗi không làm kẹt key | `test_failed_scan_does_not_poison_the_idempotency_key` | `idempotency_keys` không còn row sau 422; cùng key dùng lại được |
| replay không bump version | `test_lost_response_retry_...`, `test_repeated_mixed_version_retries_...` | `stock_documents.version` giữ nguyên |
| runtime race | 4 kịch bản mục 1.3 | `db_before` / `db_after` trong summary JSON |

## 2. Lệnh chạy candidate (QA1 chạy đúng như dưới đây)

Bộ hợp đồng + đồng thời trên driver mặc định (sqlite in-memory):

```bash
php vendor/bin/phpunit tests/Feature/Wwhn188ScanIdempotencyContractTest.php tests/Feature/InboundScanConcurrencyBehaviorTest.php tests/Feature/Wwhn187InboundScanValidationTest.php tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php tests/Feature/PostReceiptConcurrencyBehaviorTest.php tests/Feature/StockDocumentConcurrencyGuardTest.php
```

Race thật trên PostgreSQL (bắt buộc cho `DEV188-P1-02R2`):

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=wms_hoanam DB_USERNAME=postgres DB_PASSWORD=postgres WWHN188_RUNTIME_CONCURRENCY=1 php vendor/bin/phpunit tests/Feature/InboundScanRuntimeConcurrencyTest.php
```

Kiểm toàn bộ gói closure (pin + hash + evidence):

```bash
node scripts/verify-wwhn-188-closure.mjs
```

## 3. Kết quả chạy tại candidate (BE1 tự chạy, QA1 chạy lại để ký)

| Bộ | Kết quả |
|---|---|
| 6 file test ở mục 2 (sqlite) | **51 passed, 317 assertions, 0 failed** |
| `Wwhn188ScanIdempotencyContractTest` riêng | **10 passed, 100 assertions** |
| Runtime concurrency PostgreSQL 16.14 | **4/4 kịch bản PASS**, mỗi kịch bản 2 backend PID phân biệt |
| `verify-wwhn-188-closure.mjs` | xem output tại thời điểm chạy |

Số liệu thô, log worker, mẫu `pg_locks` và snapshot DB nằm trong `docs/evidence/WWHN-188/runtime/`.

## 4. Defect disposition (QA1 điền khi nghiệm thu)

| ID | Nội dung | Trạng thái đề xuất |
|---|---|---|
| `DEV188-P1-03` / `BE1-SCANCONC-OP-03` | Double-count quantity-mode khi re-fetch retry | **FIXED** — `DEC-SCAN-IDEMP-001`, có test tái lập trước/sau |
| `BE1-SCANCONC-OP-01` | Không có `AuditLogger::record()` riêng cho scan (chỉ `scan_event`/`scan_batch`) | Kế thừa từ `WWHN-187`, chờ BA/Tech Lead — không chặn |
| `BE1-SCANCONC-OP-02` | Scan không bắt buộc `Idempotency-Key` | **CLOSED** bằng `DEC-SCAN-IDEMP-001` (optional, đúng Annex C) |
| `BE1-SCANCONC-OP-05` | Outbound scan cùng hình dạng gap | Mở, ngoài phạm vi WWHN-188 |
| `BE1-SCANCONC-OP-06` | Siết `Idempotency-Key` thành bắt buộc sau MVP | Mở, không chặn release |

## 5. Ô cố ý KHÔNG có trong candidate này

- **400 `BAD_REQUEST`**: endpoint scan không có nhánh nào trả 400 — payload sai hình dạng bị Laravel
  validation bắt thành **422** theo đúng `BR-API-ERR`. Ghi ở đây để QA không coi là ô bị bỏ sót.
- **Outbound scan**: ngoài phạm vi WWHN-188 (xem `BE1-SCANCONC-OP-05`).
- **Post Receipt**: thuộc `WWHN-190`, có bộ test đồng thời riêng (`PostReceiptConcurrencyBehaviorTest`)
  đã nằm trong lệnh chạy ở mục 2 để chứng minh không regression.
