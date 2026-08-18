# WWHN-273 → WWHN-275 — Bàn giao cho release smoke của QA1

| Field | Value |
|---|---|
| Từ | BE1 — `WWHN-273` (`Backend release sign-off`) |
| Tới | QA1 — `WWHN-275` (release smoke độc lập) |
| Task cha | `WWHN-72` |
| Liên quan | `WWHN-272` (DevOps deploy/backup/rollback), `WWHN-274` (gate FE) |
| RC pin | `docs/evidence/WWHN-273/RC_PIN.json` |
| Ngày | `2026-08-19` |

> Tài liệu này **không** phải QA report và **không** thay chữ ký của QA1. Nó chỉ nói rõ QA1 cần
> candidate nào, kiểm gì, và những gì BE1 **đã** kiểm để QA1 không kiểm lại một cách mù.

---

## 1. Điều kiện tiên quyết — đọc trước khi bắt đầu

Tại thời điểm bàn giao, gate BE **đang đỏ** (`RC_PIN.gate.status_at_pin = RED`, 7/7 run failure,
blocker `W273-P0-03` thuộc `WWHN-289`). **QA1 không nên bắt đầu release smoke trước khi**:

1. Gate `WWHN-273 Backend Release Sign-off` có một run `success`, và
2. `RC_PIN.gate.green_at_sha` đã được điền bằng SHA của run đó, và
3. DevOps (`WWHN-272`) đã deploy **đúng SHA đó** lên Beta.

Nếu smoke chạy trên một build khác SHA đã pin thì kết quả không dùng được để đóng `WWHN-72`.

---

## 2. Candidate mà QA1 phải smoke

| Hạng mục | Lấy ở đâu |
|---|---|
| Backend SHA | `RC_PIN.gate.green_at_sha` |
| Backend artifact | `wwhn-273-backend-release-signoff-<sha>` trong run tương ứng |
| Frontend SHA | Gate `WWHN-274` — SHA của run FE `success` được deploy |
| Build đang chạy | `GET /api/v1/health` → đối chiếu build SHA trong payload với SHA đã pin |

Bước đầu tiên của QA1 nên là **đối chiếu SHA**, không phải gọi nghiệp vụ.

---

## 3. BE1 đã kiểm những gì (đừng kiểm lại một cách mù)

Trên runtime CI đã `serve`, ở đúng SHA của run:

| Khẳng định | File evidence trong artifact |
|---|---|
| `migrate:fresh --seed` trên PostgreSQL 16 sạch | `release-signoff.txt` → `migrate_fresh_seed` |
| Diễn tập rollback `migrate:reset` → `migrate` → `db:seed` | `release-signoff.txt` → `rollback_drill` |
| Readiness trước cutover | `golive-check.json` → `overall` |
| `/api/v1/health` = 200 `status=ok`; `/api/health` = 404 | `health.json`, `served-smoke.txt` |
| Default-deny: `/api/v1/skus` không token = 401 | `served-smoke.txt` → `protected_no_token_http` |
| Smoke nghiệp vụ có token = 200 | `served-smoke.txt` → `smoke/api/v1/...` |
| Absence Stocktake trên runtime = 404 | `stocktake-absence.txt` |
| Trạng thái thật của run | `gate-status.txt` → `job_status`, `result` |

Giá trị QA1 thêm vào là **độc lập**: chạy trên môi trường Beta đã deploy thật, với dữ liệu và cấu
hình thật, không phải runtime tạm của job CI.

---

## 4. Việc của QA1

### 4.1. Smoke nghiệp vụ theo AC của `WWHN-72`

- [ ] `POST /api/v1/auth/login` → 200 + access token; refresh cookie hoạt động.
- [ ] Master: `GET /api/v1/skus` → 200.
- [ ] Inbound: `GET /api/v1/inbound-documents` → 200.
- [ ] Outbound: `GET /api/v1/outbound-documents` → 200.
- [ ] Inventory: `GET /api/v1/inventory/balances` → 200.
- [ ] Báo cáo: `GET /api/v1/reports/hub` → 200.
- [ ] RBAC: một user thiếu quyền nhận 403, không phải 200 hay 500.
- [ ] Đối soát: tồn theo `inventory/balances` khớp movement ledger trên cùng bộ dữ liệu.

### 4.2. "Kiểm kê" trong AC3 — kiểm chứng ABSENCE, không gọi API Stocktake

Neo: `REQ-SRS-037` + `AC-SRS-037` ("deployment v1.12 không chứa capability legacy").
Disposition: `docs/decision-records/DISP-REL-273-STOCKTAKE-RELEASE-CRITERIA.md`.

Tất cả probe dưới đây chạy **kèm Bearer token hợp lệ**, kỳ vọng đúng **404**:

- [ ] `GET /api/v1/stocktake-sessions`
- [ ] `GET /api/v1/inventory-snapshots`
- [ ] `GET /api/v1/stock-adjustments`
- [ ] `GET /api/v1/reports/stocktake-variance`
- [ ] `POST /api/v1/stocktake-sessions/{uuid}/apply-adjustment`
- [ ] UI không có menu/route/nút mang nghĩa kiểm kê hoặc điều chỉnh tồn trực tiếp.
- [ ] Catalog permission của user không chứa `stocktake.*`.
- [ ] Đường sửa sai được xác minh là **Reversal**.

403 hoặc 200 ở bất kỳ dòng nào là **defect**, không phải "chưa hiện thực".

### 4.3. Ghi evidence

QA report của `WWHN-275` cần có: version của report, backend SHA, frontend SHA, môi trường, thời
điểm, raw response API (mã HTTP + body rút gọn), bằng chứng đối soát DB, và một dòng kết luận
`AC-SRS-037 absence verification: PASS/FAIL`.

---

## 5. Ranh giới

- BE1 **không** ký thay QA1. `release-signoff.txt` ghi rõ `qa1_signoff=NOT_CLAIMED_BY_BE`.
- BE1 **không** ký thay DevOps. Deploy/backup/restore/rollback thật thuộc `WWHN-272`; runbook cutover
  nằm ở `docs/evidence/WWHN-300/CUTOVER_ROLLBACK_RUNBOOK.md`.
- Diễn tập rollback trong gate BE là **rollback của migration chain**, không phải restore-from-backup.
  Các migration `down()` rỗng theo chủ đích (fail-forward) đã được dispositioned tại `WWHN-300`; quyền
  hoàn tác quá các bước đó thuộc restore-from-backup của DevOps.
