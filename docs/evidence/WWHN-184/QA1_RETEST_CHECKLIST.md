# WWHN-184 — Checklist retest QA1 (DEV184-P1-03R)

| Field | Value |
|---|---|
| Jira task | `WWHN-184` (task cha `WWHN-41`), liên quan `WWHN-185` |
| Owner thực thi | **QA1** |
| Reviewer | QA Lead + BA |
| Điều kiện tiên quyết | `DEV184-P1-02R` đã có `deployed_sha` trong `docs/evidence/WWHN-184/RC_PIN.json` |
| DB engine ưu tiên | **PostgreSQL** (sqlite chỉ dùng cho suite dev) |

---

## 0. Phần DEV đã đóng trước khi bàn giao — QA1 không phải làm lại

Register ghi: *"DEV race chỉ SQLite stale replay"*. Nhận xét đó đúng với hiện trạng cũ và
đã được xử lý tận gốc:

| Trước | Sau |
|---|---|
| `PostReceiptConcurrencyBehaviorTest` phát lại **tuần tự** hai request trên **một** connection sqlite — hai backend chưa bao giờ thực sự tranh chấp | `Wwhn184PostReceiptRuntimeConcurrencyTest` + `scripts/wwhn184-post-receipt-runtime-concurrency.php`: spawn **tiến trình OS thật**, mỗi tiến trình **một PostgreSQL backend**, đua trên chính `postReceipt()` / `cancelInbound()` |
| Không có bằng chứng tranh chấp thật | Lấy mẫu `pg_locks` / `pg_stat_activity` trong lúc đua; assert `distinct_backend_pid_count = 2`, `real_contention_proven`, và timestamp thô chứng minh hai worker **chồng lấn wall-clock** |
| Chỉ assert HTTP status | Assert trạng thái DB **đã commit** trước/sau: `stock_documents.status/version`, số `stock_movements` của phiếu, `inventory_balances.on_hand_qty` |

Ba kịch bản race đã tự động hoá (chạy trong job `postgres-post-receipt-race`):

1. `same_document_same_version_two_keys` — hai writer Post cùng phiếu, cùng version, **hai**
   Idempotency-Key. Đúng một winner; loser bị từ chối bằng mã hợp đồng; tồn tăng **đúng một lần**.
2. `shared_idempotency_key` — hai writer **cùng** Idempotency-Key. Lease chỉ cho một writer
   thực thi; key chỉ lưu một hàng; tồn tăng một lần.
3. `post_vs_cancel` — Post đua Cancel trên cùng version. Chỉ một transition terminal commit;
   stock effect khớp đúng transition thắng.

QA1 **xác minh lại bằng chứng này** (đọc artifact) chứ không cần dựng lại harness.

---

## 1. Ghi nhận môi trường — bắt buộc, thiếu là không tính

| Mục | Giá trị QA1 phải ghi |
|---|---|
| Exact SHA đã deploy | |
| Build/run ID | |
| Environment | |
| DB engine + version | `SELECT version();` |
| `APP_ENV` / `APP_DEBUG` | |
| Ngày giờ chạy (Asia/Ho_Chi_Minh) | |

---

## 2. API + hợp đồng lỗi

| # | Ca kiểm thử | Kỳ vọng | Đã chạy | Bằng chứng thô |
|---|---|---|---|---|
| 2.1 | `POST /inbound-documents` hợp lệ | `201`, `status=DRAFT`, `version=1`, header `ETag: "1"` | | request/response |
| 2.2 | `PATCH /inbound-documents/{id}` thiếu `If-Match` | `428 PRECONDITION_REQUIRED` | | |
| 2.3 | `PATCH` với `If-Match` cũ | `412 STALE_VERSION` | | |
| 2.4 | `POST /{id}/scan` đủ số lượng | `200`, `version+1`, **không** sinh `stock_movements` | | so `stock_movements` trước/sau |
| 2.5 | `POST /{id}/post-receipt` thiếu `Idempotency-Key` | `428 PRECONDITION_REQUIRED` | | |
| 2.6 | Post Receipt hợp lệ | `200`, `status=POSTED`, `version+1`, `data.replayed=false` | | |
| 2.7 | Cancel phiếu đã POSTED, **If-Match hiện hành** | `409 STATE_CONFLICT` | | |
| 2.8 | Cancel phiếu đã CANCELLED, **If-Match cũ** | `412 STALE_VERSION` (không phải 409) | | |

> **2.7 và 2.8 là cặp quan trọng nhất của lần retest này.** Cùng một request, khác đúng một
> thứ: giá trị `If-Match`. `cancelDocument()`/`postDocument()` gọi `assertVersion()` **trước**
> khi kiểm tra status, nên gửi version cũ thì dừng ở `412` và **không bao giờ** chạm state guard.
> Đây chính là defect đã làm bản Postman cũ không thể pass. Nếu QA1 quan sát `409` ở 2.8 hoặc
> `412` ở 2.7 thì contract đã đổi — báo lại DEV ngay.

---

## 3. RBAC

| # | Ca kiểm thử | Kỳ vọng |
|---|---|---|
| 3.1 | `WAREHOUSE_STAFF` (chỉ `inbound.create`) Cancel phiếu DRAFT/SCANNING | **`200`**, không phải `403` — route khai `inbound.cancel\|inbound.create` (Annex C dòng 1443) |
| 3.2 | `WAREHOUSE_APPROVER` (có `inbound.cancel`) Cancel | `200` |
| 3.3 | `VIEWER` gọi `post-receipt` | `403`, body không lộ stack trace |
| 3.4 | User bị giới hạn `scope_ref` sang kho khác gọi bất kỳ endpoint nhập kho nào | `403 DATA_SCOPE_DENIED` (`EnforceInboundWarehouseScope`) |

---

## 4. Idempotency + no-stock-effect

| # | Ca kiểm thử | Kỳ vọng |
|---|---|---|
| 4.1 | Replay **cùng** Idempotency-Key sau khi Post | `200`, `data.replayed=true`, header `Idempotent-Replay: true`, tồn **không** tăng lần hai |
| 4.2 | **Cùng** key, **khác** payload | `409 IDEMPOTENCY_CONFLICT` (SRS §8.2). **Không phải** `IDEMPOTENCY_ERROR` — mã đó thuộc luồng SKU |
| 4.3 | **Khác** key trên phiếu đã POSTED, If-Match hiện hành | `409 STATE_CONFLICT` (REQ-SRS-007 "không Post lần hai") |
| 4.4 | Toàn bộ DRAFT/SCANNING/CANCELLED | **Không** sinh `stock_movements`, `inventory_balances` không đổi |

---

## 5. Audit / history

| # | Kiểm tra | Nguồn |
|---|---|---|
| 5.1 | Create/Update/Cancel/Unscan có bản ghi audit | `audit_log` qua `AuditLogger::record` |
| 5.2 | Scan **không** ghi `audit_log` generic mà ghi bằng chứng riêng | `inbound_scan_entries` / `scan_batch` / `scan_event` — thiết kế có chủ đích, xem `DEC-SCAN-AUDIT-001` |
| 5.3 | Post Receipt có `document_status_history` + `posting_event` | |
| 5.4 | Audit của lần thử **thất bại** không chứa PII thô | |

> 5.2 là điểm DEV đã nêu là "cần QA1 xác nhận cơ chế này đáp ứng yêu cầu audit/history".
> Nếu QA1 kết luận chưa đủ, đây là input cho BA chứ không phải defect code.

---

## 6. Race ≥2 connection (xác minh lại bằng chứng DEV, không dựng lại harness)

| # | Kiểm tra trên artifact `wwhn-184-pg-race-evidence-<sha>` | Kỳ vọng |
|---|---|---|
| 6.1 | `post-receipt-concurrency-summary.json` → `status` | `PASS` |
| 6.2 | `git_sha` của summary | **bằng** exact SHA đã deploy |
| 6.3 | Mỗi kịch bản → `distinct_backend_pid_count` | `2` |
| 6.4 | `same_document_same_version_two_keys` và `post_vs_cancel` → `real_contention_proven` | `true` |
| 6.5 | Mọi kịch bản → `timing.workers_in_flight_simultaneously` | `true` |
| 6.6 | `pg-lock-samples.json` từng kịch bản | có hàng `granted=false` hoặc `wait_event_type=Lock` |
| 6.7 | `db_before` / `db_after` của kịch bản 1 | `on_hand_qty` tăng đúng **một** lần; `movements_for_document = 1` |

QA1 bổ sung **một** race thủ công trên môi trường đã deploy (2 terminal, cùng phiếu, cùng
`If-Match`, hai Idempotency-Key khác nhau) và lưu raw request/response của cả hai — để chứng
minh hành vi đứng vững ngoài harness.

---

## 7. Rollback + reconciliation

| # | Kiểm tra | Kỳ vọng |
|---|---|---|
| 7.1 | Post Receipt thất bại giữa chừng (ví dụ ép lỗi ở service) | Rollback toàn bộ: không movement, không đổi balance, không đổi status |
| 7.2 | Reconciliation sau toàn bộ ca kiểm thử | `SUM(stock_movements.qty_delta)` khớp `inventory_balances.on_hand_qty` theo từng SKU/kho |
| 7.3 | Không có tồn âm | |
| 7.4 | Reversal của phiếu đã Post | Movement gốc giữ nguyên, sinh movement đối ứng (REQ-SRS-013) |

---

## 8. Kết luận QA1

| Mục | Kết quả |
|---|---|
| Exact SHA đã test | |
| Build/run ID | |
| DB engine | |
| Số ca đạt / tổng | |
| Defect mở (kèm mã) | |
| Kết luận | PASS / FAIL |

QA1 đính kèm: raw API log, raw DB query trước/sau, kết quả reconciliation, và ảnh chụp/log
của race thủ công ở mục 6.
