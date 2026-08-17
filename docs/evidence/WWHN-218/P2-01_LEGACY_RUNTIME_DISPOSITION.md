# WWHN-218 / DEV218-P2-01 — Legacy runtime path disposition

Status: **SUPERSEDED by `docs/decision-records/DEC-STK-002.md` (2026-08-17)**

> **Đọc DEC-STK-002 thay cho file này.**
>
> File này gộp chung hai thứ khác bản chất dưới một nhãn "legacy", và đó chính là
> nguồn nhầm lẫn khiến câu hỏi bị nêu lại qua WWHN-190/195/205/207/218:
>
> - `CurrentBaselineStockController` — **zero call site**, là bản sao write path
>   thiếu toàn bộ control WWHN-218 (không `If-Match`, không `lockDocument`,
>   không label gate, không duplicate-item guard). **Đã xoá** theo DEC-STK-002.
> - `StockDocumentService` + `Inbound/OutboundDocumentController` — có route sống
>   (`index`/`show`) và caller thật. **Giữ**, đúng như phần còn lại của file này.
>
> Lập luận "cần zero-call-site migration trước khi xoá" ở mục 4 bên dưới đã thoả
> mãn với class thứ nhất: không có call site nào để migrate.
>
> Ràng buộc nay được thực thi bằng `scripts/verify-canonical-stock-write-path.php`
> trong CI, không còn phụ thuộc vào việc đọc tài liệu.

## Decision (nội dung gốc, giữ lại làm dấu vết)

Do **not** delete `StockDocumentService`, `InboundDocumentController`, `OutboundDocumentController` or the compatibility concern solely to satisfy WWHN-218. Retain them as compatibility/read-side code while keeping the current-baseline mutation path authoritative.

This is a scope-control decision, not a claim that every legacy class is part of the canonical write path.

## Canonical mutation boundary

For inbound/outbound stock-changing operations, `Modules/Warehouse/routes/api.php` routes mutation actions to the current-baseline controllers/services. The compatibility `InboundDocumentController` / `OutboundDocumentController` are not the authoritative implementation for posting or reversal.

`InteractsWithStockDocuments` is explicitly constrained to shared read behavior (`index` / `show`) plus compatibility helpers and documents that stock-changing runtime routes are implemented by the current-baseline flow.

`StockDocumentService` remains available for compatibility/non-authoritative callers that still depend on it. Its continued presence therefore does not establish a second canonical inbound/outbound mutation contract.

## Why retain instead of delete in this hardening task

1. WWHN-218 is a permission/idempotency/concurrency hardening task; deleting compatibility code is not required to close the P1 security/concurrency gates.
2. Removing shared legacy service/controller plumbing without a dedicated zero-call-site migration introduces avoidable regression risk to transfer/compatibility code.
3. Canonicality is already enforced at the route boundary: current-baseline controllers/services own the stock-changing inbound/outbound routes.
4. A future cleanup may remove legacy classes only after repository-wide call-site verification and regression coverage prove they are unused.

## Contract impact

- API contract change: **NONE**.
- Permission change: **NONE**.
- Database/schema change: **NONE**.
- FE mapping change: **NONE**.
- WWHN-218 hard-gate behavior: **NONE**; CI continues to verify the current-baseline runtime.

## Reviewer gate

Tech Lead should record one outcome on WWHN-218:

- `APPROVED — RETAIN compatibility/read-side legacy path; current-baseline mutation path is canonical`, or
- `CHANGES REQUIRED — <specific class/call-site to remove or migrate>`.

Until that reviewer disposition is recorded, this file is DEV evidence only and must not be represented as independent approval.
