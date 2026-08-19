# WWHN-273 — Provenance của release candidate (DEV273-P1-06)

| Field | Value |
|---|---|
| Jira task | `WWHN-273` — `[BE] Kiểm tra migration/smoke backend` |
| Task cha | `WWHN-72` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` (không tạo nhánh riêng) |
| **PR = N/A** | Toàn bộ công việc của `WWHN-273` được commit trực tiếp lên `m1`. Không có PR nào để pin. |
| Reviewed compare range | `c965db1...` → commit mang tài liệu này |
| Compare URL | `https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/compare/c965db1...m1` |
| Pin | `docs/evidence/WWHN-273/RC_PIN.json` (`node scripts/verify-wwhn-273-release-signoff.mjs`) |
| Ngày | `2026-08-19` |

---

## 1. Vì sao cần tài liệu này

Reviewer chấm `DEV273-P1-06` với lý do: **không có PR/commit nào mang task key `WWHN-273`, và
current HEAD là mixed commit**. Cả hai quan sát đều đúng, và hệ quả không hề nhẹ:

**Lọc commit theo chuỗi `WWHN-273` sẽ bỏ sót đúng phần quan trọng nhất của task.**

Bằng chứng cụ thể: commit `68852e1` có tiêu đề `docs(WWHN-295): add runtime/config/health remediation
register` nhưng nội dung thật của nó gồm:

| File | Nó làm gì trong `WWHN-273` |
|---|---|
| `.github/workflows/wwhn-273-backend-release-signoff.yml` (+31/−3) | Thêm **toàn bộ smoke nghiệp vụ có token** (`auth/login` → `skus`, `inbound-documents`, `outbound-documents`, `inventory/balances`, `reports/hub`) — tức là đóng `W273-P1-04`, chính AC trung tâm của `WWHN-72` |
| `tests/Feature/Wwhn273ReleaseSignoffContractTest.php` (+42) | Khoá hợp đồng cho phần smoke vừa thêm |
| `docs/docs_task_jira/WWHN-273.md` (+5) | Ghi kết quả smoke nghiệp vụ vào bảng evidence |

Nếu reviewer chỉ đọc các commit có tiền tố `ci(WWHN-273)` / `fix(WWHN-273)`, họ sẽ kết luận sai rằng
smoke nghiệp vụ chưa được hiện thực. Đây là lý do provenance phải pin **theo file**, không theo task
key trong commit message.

---

## 2. Reviewed source → candidate diff (thay cho PR)

Base `c965db1` là commit cuối cùng **trước khi** `WWHN-273` tồn tại trong repo
(`fix(ci): align m1 release smoke with canonical health API`, 2026-08-18).

| # | Commit | Tiêu đề | File `WWHN-273` bị chạm | Task key trong message có khớp? |
|---|---|---|---|---|
| 1 | `30dc01a` | `ci(WWHN-273): add exact-SHA backend release sign-off gate` | workflow | Có |
| 2 | `aa71694` | `ci(WWHN-273): add rollback drill, golive check and served smoke to release gate` | workflow, contract test, task doc | Có |
| 3 | `68852e1` | `docs(WWHN-295): add runtime/config/health remediation register` | workflow, contract test, task doc | **KHÔNG — mixed commit** |
| 4 | `1655d0b` | `fix(WWHN-273): reseed before the served smoke so the gate cannot pass on an empty database` | workflow, contract test | Có |
| 5 | `7dacc8e` | `fix(WWHN-191): pin candidate theo commit cuoi cham artifact, khong theo HEAD` | workflow, contract test, task doc, verifier, RC pin, disposition, provenance, QA handoff | **KHÔNG — mixed commit** |

Dòng 5 lặp lại đúng hiện tượng mà tài liệu này cảnh báo, lần này với công việc của chính `WWHN-273`:
toàn bộ thay đổi `WWHN-273` (gate absence + gate-status, verifier, RC pin, disposition, provenance,
QA handoff, task doc) nằm trong `7dacc8e` — một commit mang nhãn `WWHN-191`. Nguyên nhân là `m1` được
nhiều phiên dùng chung: các file đã `git add` của phiên này bị cuốn vào commit của phiên kia. Vì vậy
tập file ở §3 và sha256 trong `RC_PIN.json` mới là nguồn provenance, **không phải** commit message.

Nguồn số liệu (chạy lại được):

```bash
git log --format='%h %s' c965db1..m1 -- \
  .github/workflows/wwhn-273-backend-release-signoff.yml \
  tests/Feature/Wwhn273ReleaseSignoffContractTest.php \
  docs/docs_task_jira/WWHN-273.md
```

---

## 3. Tập file thuộc `WWHN-273`

Đây là tập được pin trong `RC_PIN.json` (`owned_files`). Verifier so sánh sha256 nội dung, nên một
phiên/ticket khác sửa nội dung các file này sẽ làm gate đỏ thay vì đi qua im lặng.

| File | Vai trò |
|---|---|
| `.github/workflows/wwhn-273-backend-release-signoff.yml` | Gate exact-SHA: migrate/seed, rollback drill, golive-check, suite, smoke đã serve, absence Stocktake, manifest |
| `tests/Feature/Wwhn273ReleaseSignoffContractTest.php` | Khoá hợp đồng của gate (không cho gỡ bước trong im lặng) |
| `scripts/verify-wwhn-273-release-signoff.mjs` | Verifier pin/provenance/trung thực trạng thái |
| `docs/docs_task_jira/WWHN-273.md` | Task doc + register P0/P1/P2 |
| `docs/decision-records/DISP-REL-273-STOCKTAKE-RELEASE-CRITERIA.md` | Disposition cho `DEV273-P1-05` |
| `docs/evidence/WWHN-273/RC_PIN.json` | RC pin: run history, artifact bắt buộc, blocker, truth boundary |
| `docs/evidence/WWHN-273/CANDIDATE_PROVENANCE.md` | Tài liệu này |
| `docs/evidence/WWHN-273/QA1_RELEASE_SMOKE_HANDOFF.md` | Bàn giao cho `WWHN-275` |

---

## 4. File KHÔNG thuộc `WWHN-273` nhưng chặn gate của nó

| File | Ticket sở hữu | Quan hệ |
|---|---|---|
| `Modules/Warehouse/database/migrations/2026_08_10_000765_keep_legacy_runtime_and_erd_physical_schema_in_sync.php` | `WWHN-289` / `WWHN-310` | Chứa generator write-bridge sinh ra defect `W273-P0-03` |
| `Modules/Warehouse/database/migrations/2026_08_19_000100_add_optimistic_lock_to_pv_master_tables.php` | `WWHN-252` | Là nơi defect phát nổ (không phải nguyên nhân) |
| `Modules/Warehouse/database/migrations/2026_08_19_000050_repair_erd_mirror_projection_casts.php` | `WWHN-289` (`DEV289-P1-02R`) | Bản sửa gốc đang bay khi tài liệu này được viết |

`WWHN-273` **không** sửa các file trên. Nhánh `m1` dùng chung và có nhiều phiên làm song song; sửa
chồng lên một fix đang bay sẽ tạo xung đột và hai bản sửa khác nhau cho cùng một defect.

---

## 5. Việc cần Tech Lead làm (thay cho PR review)

Vì `PR = N/A`, chữ ký review phải nằm ở Jira. Tech Lead cần comment xác nhận:

- [ ] Đã đọc reviewed diff `c965db1` → candidate trên đúng tập file ở §3.
- [ ] Xác nhận `68852e1` là mixed commit và phần `WWHN-273` trong đó đã được review.
- [ ] Xác nhận `PR = N/A` là hình thức được chấp nhận cho `m1`, và provenance này thay thế PR.
- [ ] Xác nhận `WWHN-273` không sở hữu các file ở §4.
