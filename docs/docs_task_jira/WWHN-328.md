# WWHN-328 — [BE] Cung cấp endpoint tổng hợp nếu cần (dashboard)

Cập nhật: 2026-08-19
Repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
Nhánh thực hiện: `m1`
Parent: `WWHN-88` — cặp FE `WWHN-327`, QA độc lập `WWHN-329`
Endpoint: `GET /api/v1/reports/dashboard-quick` — **tái sử dụng, không tạo route mới**

## 1. Phạm vi và nguồn chuẩn

Nguồn đã đọc và đối chiếu cho vòng R4:

1. `docs/HN_SRS_FRD_v1.12_FULL.md` — §8.2 error taxonomy, §13.10 Report Contract (RPT-INV-01),
   AC-SRS-032 (dữ liệu đúng scope), BR-EXP-01..05 (export dùng cùng filter/scope với màn hình),
   `DEC-CONTRACT-PHASE-001` / `ROADMAP-CTR-001` — **dashboard nâng cao là Non-MVP/Phase 2**, nên
   WWHN-328 chỉ được tổng hợp trên dữ liệu đã có, không mở rộng surface.
2. `docs/HoaNam_ERD_Moi_Nhat.md` — entity 30 `inventory_movements` (ledger authoritative,
   append-only) và entity 45 `warranty_cases`. Đã kiểm từng cột của entity 45: **không có
   `warehouse_id`** — đây là cơ sở dữ liệu học của ngoại lệ warranty GLOBAL, không phải một
   nhân nhượng kỹ thuật.
3. `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — màn Dashboard và các state bắt buộc.
4. `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — `DASHBOARD_KPI` gọi qua `http` client
   dùng chung (không `axios` trần), RBAC theo permission code, CI gate theo exact SHA.

Bất biến không được làm sai: `stock_movements` là ledger authoritative; `inventory_balances` là
projection; dashboard **không** tự định nghĩa lại MISMATCH mà gọi lại chính builder RPT-INV-01.

## 2. Nội dung nghiệp vụ đã chốt

Toàn bộ nằm ở `docs/evidence/WWHN-328/KPI_MATRIX.md` **v1.2**: data-scope (§1), công thức
K0–K10 kèm nguồn authoritative và ánh xạ ERD (§2), expected totals (§3), error contract (§4),
timezone/cutoff đo trên DB tham chiếu (§5), cache (§6), bất biến đối soát với RPT-INV-01 (§7),
ràng buộc một route aggregate (§8), sổ ký (§9).

Hai điểm hay bị chấm ngược khi review/kiểm thử:

- **`warranty_cases_by_status` luôn GLOBAL**, kể cả với người dùng bị giới hạn kho. Đúng theo
  ERD; FE khai ngoại lệ này trên màn hình (WWHN-327, khối `role="note"`).
- **`reconciliation_warnings` có trần 50 dòng**, nên `length` của mảng **không** phải tổng.
  Con số để hiển thị và đối soát là `reconciliation_warning_count`.

## 3. Trạng thái register R4

Đóng theo từng ID tại `docs/evidence/WWHN-328/R4_CLOSURE.md §2`. Tóm tắt:

| Nhóm | ID | Trạng thái |
|---|---|---|
| DEV đóng được bằng bằng chứng | P1-01R4, P1-02R4, P1-03R4, P1-04R4, P1-05R4 | CLOSED |
| Cần đúng vai trò khác thực hiện | P1-06R4 (QA2 chạy lại), P1-07R4 (BA + QA2 chấp nhận) | READY |
| Thao tác metadata trên Jira | P2-01R4, P2-02R4 | READY (PM/BAS) |

## 4. Candidate và cách chống "evidence stale"

Cặp SHA và cơ chế bất biến: `R4_CLOSURE.md §3` + `docs/evidence/WWHN-328/CONTRACT_PIN.json`.

Nguyên tắc rút ra sau ba vòng bị trả lại: **không pin hash cả tệp dùng chung, không pin HEAD**.
Pin ở mức đối tượng (OpenAPI operation / Postman request / khối mã / dòng route) rồi chứng minh
bất biến trên dải `candidate..HEAD`. Vì `m1` có nhiều phiên đẩy song song, đây là cách duy nhất
để một bằng chứng còn giá trị sau khi nhánh chạy tiếp.

## 5. Chạy lại

```bash
node scripts/verify-wwhn-328-closure.mjs
node scripts/wwhn-328-contract-fingerprint.mjs --origin
php vendor/bin/phpunit --filter 'Wwhn328DashboardQuickContractTest|Wwhn328R4ClosureTest'
```

CI: `.github/workflows/wwhn-328-dashboard-closure.yml` (PostgreSQL 16, `workflow_dispatch` nhận
`candidate_sha` để chạy lại đúng SHA đã pin trên Jira).

## 6. Quy tắc cho lần sửa sau

1. Commit chạm dashboard **phải mang Jira key** ở tiêu đề. `gỡ cờ 12`/`gỡ cờ 13` là lý do
   DEV328-P1-03R4 bị mở lại; không lặp lại.
2. Đổi bất kỳ ô nào trong KPI matrix ⇒ **tăng version** và ký lại. `verify-wwhn-328-closure.mjs`
   ghim version đang được ký nên đổi lặng lẽ sẽ làm gate đỏ.
3. Thêm route dashboard mới là **đổi contract**, không phải bổ sung — `Wwhn328R4ClosureTest`
   assert đúng một route aggregate.
4. Nếu `stock_movements.occurred_at` chuyển sang `timestamptz`, biên kỳ phải đổi sang
   `>= from 00:00:00 AND < to+1 ngày` và matrix lên v1.3 (KPI matrix §5).
