# WWHN-204 — DEV204-P1-01: Candidate branch và exact commit pin

**Kết luận:** `m1` **là** candidate branch của WWHN-204. **Không có thao tác port/cherry-pick
nào cần làm** sang `m1-be-2` — và làm thì sẽ sai. Dưới đây là bằng chứng chạy lại được, không
phải lời khẳng định.

---

## 1. Câu hỏi của register

> *"Formal xác nhận m1 là candidate branch của WWHN-204 **hoặc** port/cherry-pick exact
> implementation sang m1-be-2; pin exact commit/PR"* — nguồn: Jira comment 14285 / Git branches.

Register đưa ra hai lựa chọn vì reviewer không xác định được implementation của WWHN-204 nằm ở
nhánh nào. Quan hệ thật giữa hai nhánh trả lời dứt điểm câu hỏi đó.

## 2. Bằng chứng ancestry

```
$ git merge-base m1 origin/m1-be-2
60816499066f9f493596e326dc41116ef32f5c4b

$ git rev-parse origin/m1-be-2
60816499066f9f493596e326dc41116ef32f5c4b

$ git rev-list --left-right --count origin/m1-be-2...m1
0       719
```

`merge-base` **bằng đúng HEAD của `m1-be-2`**, và `m1` behind **0** / ahead **719**.

⇒ `m1-be-2` là **ancestor thuần** của `m1`. Mọi commit có trên `m1-be-2` đều đã có trên `m1`.
Không tồn tại "branch source conflict" — chỉ có một dòng lịch sử, `m1-be-2` là ảnh chụp cũ hơn.

*(Cùng kết luận, cùng phương pháp đã dùng để đóng DEV290-P1-02/03 ở
`docs/evidence/wwhn-290/` — không phải phát hiện mới, mà là hệ quả ổn định của cây commit.)*

## 3. Bằng chứng reachability của chính code WWHN-204

```
$ git merge-base --is-ancestor 7a78477424cc5b4e0685a0fa702dd085ee9f882e m1            ; echo $?
0        # CÓ trên m1

$ git merge-base --is-ancestor 7a78477424cc5b4e0685a0fa702dd085ee9f882e origin/m1-be-2 ; echo $?
1        # KHÔNG có trên m1-be-2

$ git cat-file -e origin/m1-be-2:Modules/Warehouse/database/migrations/2026_08_15_000100_enforce_stock_movements_append_only_trigger.php ; echo $?
1        # file không tồn tại trên m1-be-2
```

Commit `7a78477` (2026-08-15T13:40:28+07:00) mang đúng ba artifact của WWHN-204:

| Artifact | Vai trò |
|---|---|
| `Modules/Warehouse/database/migrations/2026_08_15_000100_enforce_stock_movements_append_only_trigger.php` | Trigger append-only cho ledger |
| `tests/Feature/Wwhn204LedgerReconciliationCoverageTest.php` | 6 test HTTP end-to-end của bề mặt ledger/balance |
| `docs/docs_task_jira/WWHN-204.md` | Hồ sơ rà soát của BE2 |

## 4. Vì sao KHÔNG chọn phương án port/cherry-pick

| Nếu chọn `m1-be-2` làm candidate | Hệ quả |
|---|---|
| WWHN-204 coi như **chưa được triển khai** | `7a78477` không có trên nhánh đó |
| Mất 719 commit | Toàn bộ công việc sau `6081649`, gồm WWHN-231/237/239/240/254/290/310… |
| Sinh ra hai dòng lịch sử song song | Đúng loại "branch source conflict" mà register đang muốn loại bỏ |

Port ngược về ancestor không phải là "đồng bộ hai nhánh" mà là **quay lui**. Vì vậy phương án
duy nhất đúng là xác nhận `m1`.

## 5. Pin chính thức

| Mục | Giá trị |
|---|---|
| Repo | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Candidate branch | **`m1`** |
| Exact implementation commit (BE2, 2026-08-15) | `7a78477424cc5b4e0685a0fa702dd085ee9f882e` |
| Ancestor pin (`origin/m1-be-2` HEAD) | `60816499066f9f493596e326dc41116ef32f5c4b` |
| Commit đóng P1 của register | ghi tại `docs/evidence/WWHN-204/DEFECT_CLOSURE_REGISTER.md` §5 |
| PR | Không dùng PR. Register nhắc PR #4 (`dev219-p1-hardening-20260815`) — PR đó **CLOSED, chưa merge**, tuyệt đối không được lấy SHA/CI của nó làm bằng chứng cho `m1` (đã ghi ở `docs/evidence/WWHN-219/`). |

## 6. Cảnh báo chống "pin rot"

`m1` là nhánh dùng chung, nhiều phiên làm việc đồng thời ⇒ **HEAD đổi nhiều lần trong một
ngày**. Vì vậy tài liệu này **không** pin HEAD tuyệt đối; nó pin:

- commit **bất biến** đã thuộc về WWHN-204 (`7a78477`), và
- **invariant** (`INV-BRANCH-01..04` trong `WWHN-204_PINS.json`) — luôn kiểm chứng lại được
  bất kể HEAD trôi tới đâu.

Reviewer chạy `node scripts/verify-wwhn-204-closure.mjs` để dựng lại toàn bộ chứng minh trên;
script exit khác 0 nếu bất kỳ invariant nào lệch.
