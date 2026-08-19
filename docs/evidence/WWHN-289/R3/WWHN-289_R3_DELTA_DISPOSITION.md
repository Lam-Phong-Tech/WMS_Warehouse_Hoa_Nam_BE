# WWHN-289 · R3 · DEV289-P1-01R3-CANDIDATE — Freeze QA candidate + delta disposition

> Đóng finding R3 (84/100): *"PINS pin `8bdf9ad…`, nhưng live m1 đã tiến xa hơn; code-scope đã có
> post-final modification. Freeze lại MỘT QA candidate duy nhất."*
>
> **Quyết định: GIỮ frozen candidate `8bdf9ad0a00461afe27e3e11a26e7fd13d68f9e6` + delta
> disposition có bằng chứng chạy thật dưới đây.** Không repin SHA mới.

## 1. Vì sao GIỮ `8bdf9ad` chứ không repin

1. **CI run đã pin (32206778306, success, PostgreSQL 16, 93/93) chứng minh đúng code tại
   `8bdf9ad`.** Repin candidate sang một SHA mới hơn sẽ làm run đó mất giá trị chứng minh và
   bắt buộc phải có một run CI mới — trong khi quota GitHub Actions của tổ chức đã cạn
   (3.000/3.000 phút) và có chỉ thị vận hành **không phát sinh thêm bất kỳ phút Actions nào**.
2. m1 là nhánh dùng chung nhiều ticket; "live m1" là mục tiêu di động theo đúng nghĩa mà
   register cấm dùng làm target QA. Frozen candidate + delta disposition là phương án duy nhất
   vừa bất động vừa kiểm chứng được bằng hạ tầng local.
3. Mọi commit hậu-final chạm code-scope đều đã được **liệt kê tường minh, phân loại, và chứng
   minh trung tính** bằng lần chạy thật trên PostgreSQL (mục 3). Fail-closed: verifier
   `scripts/verify-wwhn289-closure.mjs` từ R3 sẽ ĐỎ nếu xuất hiện bất kỳ commit hậu-final nào
   chạm code-scope mà KHÔNG có trong danh sách disposition của PINS.

## 2. Delta map — mọi commit hậu-final chạm `code_scope_paths`

Đo bằng: `git log 8bdf9ad..HEAD -- <9 đường dẫn code_scope_paths>` tại HEAD
`5db3cb06a33aef726466e54801c7e087915d1801` (2026-08-19). Đúng **3 commit**, không hơn:

| # | Commit | Ticket chủ | File code-scope bị chạm | Bản chất thay đổi | Phân loại |
|---|--------|-----------|--------------------------|-------------------|-----------|
| 1 | `879c9704d55cf435bbb7445d87e594f7428391a6` | WWHN-222 R1 | `Modules/Auth/app/Http/Controllers/AuthController.php` | Bổ sung field catalog (event code, result, error_code) vào các lời gọi AuditLogger. Không đổi status code, envelope, hay logic throttle/scope/idempotency. | AUDIT-METADATA-ONLY |
| 2 | `a2e4fc4d497a7fa34c0a1b2615efece237046958` | WWHN-234 | `tests/Feature/Wwhn289StockInvariantProofTest.php` | THÊM assertion (bất biến posting_event unique theo (operation, idempotency_key) — Query #4 của QA1 WWHN-235) vào test retry sẵn có. Chỉ siết chặt phép đo, không nới. | TEST-ADDITIVE-STRENGTHENING |
| 3 | `0222069b3c90b1227bdf4957c9c915ef8c7db191` | WWHN-222 R2 | `Modules/Auth/app/Http/Controllers/AuthController.php` | Đổi vocabulary cột audit `result`: `FAILURE`→`FAILED`/`DENIED` theo enum ERD §7 (DEC-AUDIT-RESULT-001). HTTP contract (401/403/429, error_code, Retry-After) giữ nguyên. | AUDIT-METADATA-ONLY |

Ghi chú thêm (KHÔNG thuộc code-scope nhưng reviewer đã thấy):
- `e45856a83d45e3948e25fa5673caf8c0ec6ae290` — một phiên làm việc song song tái sinh
  `build/evidence/wwhn-289/*.json` bằng lần chạy **sqlite**, ghi đè bản PostgreSQL của CI run
  32206778306. Đây chính là lý do check `P1-03R/postgres-evidence` đỏ tại thời điểm review R3.
  R3 đã khôi phục bằng artifact sinh từ lần chạy PostgreSQL thật tại HEAD (mục 3.2).
- `4a6965b5d432947c497a2c3370a62a17907251a3`, `5db3cb06a33aef726466e54801c7e087915d1801` —
  docs/postman, không chạm code-scope.

## 3. Bằng chứng delta trung tính — chạy thật, PostgreSQL 16.14, DB cô lập

Môi trường: PHP 8.3.30 + PHPUnit 12.5.33 (Windows host), PostgreSQL 16.14
(container `wms_warehouse_hoa_nam_be-postgres-1`, image postgres:16, x86_64-pc-linux-musl).
Worktree detached + vendor copy thật (không junction) — không dùng GitHub Actions,
không phát sinh phút CI nào.

### 3.1 Tại frozen candidate `8bdf9ad` (DB `hn_wwhn289_r3_frozen`)
- `Wwhn289StockInvariantProofTest` + `Wwhn289SecurityRegressionProofTest`:
  **OK (16 tests, 197 assertions)** — raw log: `R3/QA1_LIVE_RUN_FROZEN_8bdf9ad.txt`.
- Artifact sinh ra (driver **pgsql**, reconcile mismatch_count **0**, status **PASS**):
  `R3/stock-invariant-proof.frozen-8bdf9ad.json`, `R3/security-regression-proof.frozen-8bdf9ad.json`.

### 3.2 Tại HEAD `5db3cb0` — frozen candidate + toàn bộ delta (DB `hn_wwhn289_r3_head`)
- Cùng 2 suite + `Wwhn289ErdMirrorTypeSafetyTest` (khoá hồi quy BUILD289-05):
  **OK (18 tests, 202 assertions)** — raw log: `R3/QA1_LIVE_RUN_HEAD_5db3cb0.txt`.
- Artifact pgsql của lần chạy này được commit lại vào `build/evidence/wwhn-289/*.json`
  (khôi phục sau sự cố sqlite-clobber của `e45856a`).

### 3.3 Kết luận disposition
Cả 7 chiều stock lẫn 6 nhóm security cho **cùng verdict PASS ở cả hai đầu dải delta**
(chênh lệch 16→18 test / 197→202 assertion đến từ chính các bổ sung TEST-ADDITIVE và suite
type-safety, tức phép đo tại HEAD CHẶT HƠN tại frozen). Không thay đổi nào trong 3 commit delta
chạm logic enforcement stock/security/core; hai commit AuthController chỉ đổi metadata audit
theo ERD. **Delta = NEUTRAL đối với mọi kết quả đã pin của WWHN-289.**

## 4. Ràng buộc fail-closed từ R3

- PINS (`WWHN-289_PINS.json`) từ R3 mang mục `post_final_delta_disposition` liệt kê đúng 3 SHA
  trên. Verifier check `P1-02R/no-code-after-final` được thay bằng
  `P1-01R3/post-final-dispositioned`: commit hậu-final chạm code-scope **ngoài danh sách → FAIL**.
- Danh sách disposition KHÔNG được phép nở ra âm thầm: mỗi mục bắt buộc đủ
  (`sha`, `owner_ticket`, `files`, `classification`, `neutrality_evidence`), thiếu trường → FAIL.
- Ai muốn thêm mục mới phải kèm bằng chứng trung tính chạy thật mới (log + artifact pgsql) và
  cập nhật tài liệu này — tức là một hành vi review được, không phải một lối thoát.

## 5. Trách nhiệm phê duyệt

Disposition này do DEV lập; hiệu lực đóng DEV289-P1-01R3 cần **Tech Lead + QA1** xác nhận
bằng comment trên WWHN-289 (một lần mỗi vai trò). Phần chạy sống của QA1 xem
`R3/WWHN-289_R3_QA1_REPORT.md`.
