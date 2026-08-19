# WWHN-231 — Formal supersede nguồn: `m1-be-2` → `m1` (DEV231-P1-02R2)

## Kết luận

Nguồn chính thức (source of truth) cho candidate `a16d1d6` của WWHN-231 là nhánh **`m1`**,
không phải `m1-be-2`. Bằng chứng ancestry dùng chung cho mọi ticket từng bị review nhầm vào
`m1-be-2` đã được dựng đầy đủ tại `docs/evidence/WWHN-240/SOURCE_SUPERSEDE_M1_20260819.md`
(DEV240-P1-03R) — tài liệu này KHÔNG lặp lại full nội dung đó, chỉ neo riêng phần đặc thù
của WWHN-231: xác nhận đúng candidate SHA `a16d1d6` nằm trên `m1`, không phải trên nhánh cũ.

## Bằng chứng riêng cho candidate `a16d1d6` (đọc lại 2026-08-19, sau khi refetch)

- `git merge-base --is-ancestor a16d1d6 HEAD` (local `m1`, HEAD = `e45856a`) → **YES**.
- `git merge-base --is-ancestor a16d1d6 origin/m1` (sau `git fetch origin m1-be-2 m1`) →
  **YES** — candidate đã nằm trên bản `m1` ở remote, không chỉ tồn tại local.
- `git rev-list --left-right --count origin/m1-be-2...origin/m1` = `0  866` — `m1-be-2` vẫn
  **0 commit vượt trước `m1`** (số 866 tăng so với `814` ghi ở WWHN-240 vì nhiều phiên khác
  tiếp tục commit lên `m1`; số bên trái `0` là bất biến cần theo dõi, không phải số bên phải).
- `m1-be-2` vẫn còn `StocktakeController::applyAdjustment` (mô hình A) — đúng lý do
  `DEV231-P0-01/02` từng bị chấm nhầm khi review đọc lạc vào nhánh đó; trên `m1` tại
  `a16d1d6` các route/class đó không tồn tại (khoá bằng
  `Wwhn231StockNeutralLedgerTest::test_no_stock_mutating_route_exists_outside_canonical_posting_surface`).
- `git diff --stat a16d1d6..HEAD` trên các file WWHN-231 sở hữu (service/report/command/
  migration/test/evidence) = rỗng ngoài các evidence doc do chính vòng review sau ghi thêm
  (đã liệt kê ở `NO_DRIFT_RECHECK_20260819.json`); riêng
  `app/Console/Commands/ReconcileInventoryBalances.php` có diff 69 dòng nhưng toàn bộ đến từ
  commit `da1c5af` (WWHN-204, đã đóng — bổ sung cờ `--rebuild` không đụng logic dò lệch của
  WWHN-231), không phải drift ngoài kiểm soát.

## Việc cần DEV + PM/Tech xác nhận, Tech Lead review

Chấp nhận `m1` là nguồn chính thức duy nhất cho WWHN-231 candidate `a16d1d6`; đóng
`DEV231-P1-02R2` bằng pointer supersede này thay vì mở lại toàn bộ bằng chứng ancestry của
WWHN-240 — Tech Lead chỉ cần xác nhận 4 gạch đầu dòng trên khớp với `git log`/`git rev-list`
thật tại thời điểm review, không cần PR riêng (repo không dùng PR cho các task WWHN khác trên
`m1`, xem lý do đầy đủ ở `docs/evidence/WWHN-240/SOURCE_SUPERSEDE_M1_20260819.md` §"Trạng thái PR").
