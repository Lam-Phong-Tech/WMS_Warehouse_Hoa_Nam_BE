# WWHN-230 — [DEV] Kiểm kê (core) · Scope disposition & closure pack

| Field | Value |
|---|---|
| Jira | `WWHN-230` |
| Liên đới | `WWHN-58` (story kiểm kê), `WWHN-232` (QA1 test kiểm kê) |
| Governance gate | `WWHN-25` / `DEC-STK-001` — **In Progress**, effective date `NULL` |
| Dependency | `HN-INV-001` → **`WWHN-48`** — [INVENTORY] Số dư, movement không chỉnh sửa và API đối soát |
| Disposition | `DISP-STK-230` v1.0.0 — **Option B: park / de-scope** |
| Branch | `m1` (BE + FE) |
| Review nguồn | 26/100 — register `DEV230-P1-01..04`, không có P0 xác minh được |

---

## 1. Kết luận một dòng

`WWHN-230` yêu cầu implement core kiểm kê; baseline đang có hiệu lực (`REQ-SRS-037`,
priority `Must`, adopt qua `WWHN-334` Done) **cấm** đúng bộ chức năng đó. Đây là xung đột
phạm vi, không phải nợ kỹ thuật. Xử lý bằng **Option B — park/de-scope**, ghi thành
`DISP-STK-230`, và khoá bằng gate CI để lần rà soát sau không phải chứng minh lại.

## 2. Bốn hạng mục review được xử lý thế nào

| ID | Nội dung yêu cầu | Đã làm | Bằng chứng |
|---|---|---|---|
| `DEV230-P1-01` | Quyết định (A) CR đưa Stocktake vào scope hoặc (B) park/de-scope, ghi version/effective date, cập nhật downstream | Chọn **B**. Disposition v1.0.0, effective date `NULL` cho tới khi Approver ghi trên Jira. Bảng downstream + cách verify từng artifact ở §6 của disposition | `docs/decision-records/DISP-STK-230-scope-disposition.md`, `DISP-STK-230-APPROVAL.json` |
| `DEV230-P1-02` | Pin exact repo/branch/PR/SHA/build; map code/schema/API; attach evidence | Không có implementation để map — evidence là **absence proof tại exact SHA**. Verifier ghi branch/SHA/dirty-state của cả BE và FE, digest baseline, kết quả từng check | `scripts/verify-wwhn-230-closure.mjs` → `docs/evidence/WWHN-230/CLOSURE_EVIDENCE.json` + `SHA256SUMS` |
| `DEV230-P1-03` | QA1 chạy 8 nhóm AC + chứng minh snapshot/count không tạo movement/balance delta | Ma trận AC viết lại dạng absence & substitution, mỗi dòng có một assertion tự động; điểm 8 được chứng minh **mạnh hơn** AC gốc (không tồn tại đường ghi tồn nào ngoài Post Receipt / Post Issue / Reversal) | §5 disposition + `tests/Feature/Wwhn230StocktakeScopeDispositionTest.php` (11 test) + `src/test/wwhn-230-stocktake-scope-guard.test.ts` (4 test) |
| `DEV230-P1-04` | Map temp key `HN-INV-001` sang exact Jira/artifact canonical | `HN-INV-001 → WWHN-48`, status `CONFIRMED`, dẫn nguồn `docs/docs_task_jira/WWHN-205.md`. Đồng thời map toàn bộ temp key còn lại, phân biệt `CONFIRMED` / `CANDIDATE` / `RETIRED` / `UNRESOLVED` — không suy diễn | `docs/traceability/TEMP_KEY_CANONICAL_REGISTER.json` |

## 3. Vì sao không chọn Option A

DEV/QA không tạo được căn cứ cho Option A: nó đòi sửa `REQ-SRS-037` từ `Must` sang trạng
thái khác **trên chính baseline SRS**, phát hành version mới, rồi lan xuống ERD/BPD/RBAC/
OpenAPI/wireframe/test. Không có CR nào như vậy tồn tại tính đến `2026-08-18`, và
`DEC-STK-001` vẫn `In Progress`. Chính `DEC-STK-001` §Impacted tickets đã ghi: `WWHN-58`,
`WWHN-230` "must follow decision status".

Implement trước khi có CR sẽ tạo **P0 governance breach** và làm đỏ các gate đang xanh
(`Wwhn219ApplyAdjustmentDispositionTest`, `check-api-mapping`, `wwhn-296:verify`,
`wwhn219-permission-catalog.php`).

## 4. Cách chạy lại (QA1 / Tech Lead)

```bash
cd C:/LAM_PHONG_TECH/HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE && node scripts/verify-wwhn-230-closure.mjs --with-tests
```

```bash
cd C:/LAM_PHONG_TECH/HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE && npm run wwhn-230:verify -- --with-tests
```

Sau khi 5 vai trò đã comment sign-off, điền `DISP-STK-230-APPROVAL.json` rồi chạy lại với
`--require-signoff` để chuyển gate sang chế độ cuối.

## 5. Kết quả tại phiên này

- BE `Wwhn230StocktakeScopeDispositionTest`: **11/11 pass**.
- FE `wwhn-230-stocktake-scope-guard`: **4/4 pass**.
- BE gate `verify-wwhn-230-closure.mjs`: **PASS** (27 check, 1 PENDING là sign-off).

## 6. Vòng 2 — review 67/100 (register đổi tên `DEV230-P1-01..04R` + `DEV230-P2-01`)

Không có P0. 4 hạng mục còn mở, không hạng mục nào yêu cầu implement chức năng kiểm kê:

| ID | Ai làm | Trạng thái |
|---|---|---|
| `DEV230-P1-01R` | BA/PM/Tech/QA/BAS formalize Option B trên Jira + đối chiếu `WWHN-334` | Jira-side, chưa làm được từ repo — cần người có quyền Jira |
| `DEV230-P1-02R` | DEV — regenerate evidence trên clean checkout candidate thật | **Đã đóng.** SHA cũ bị stale + `workingTreeClean=false` + `testResult=null`. Regenerate bằng `git worktree` sạch (đối xứng BE+FE để cross-reference đúng), pin đúng candidate, chạy PHPUnit/vitest thật. Commit BE `b589106` (candidate `831bda6`, 11/11 pass), commit FE `52969fb` (candidate `f9f2e65`, 4/4 pass). Cả hai phía `workingTreeClean=true`. |
| `DEV230-P1-03R` | QA1 — chạy độc lập bộ absence & substitution | Cùng test run ở `DEV230-P1-02R` đã phủ đúng yêu cầu (route/OpenAPI/schema/source absence + Reversal substitute) — QA1 chỉ cần review + sign-off, không cần chạy lại bộ khác |
| `DEV230-P1-04R` | TECH CLOSED, QA1 verify no regression | `TEMPKEY-*` 4/4 PASS trong lần chạy vừa rồi — không regression |
| `DEV230-P2-01` | BA/PM + DEV — sửa metadata Jira | Jira-side; nội dung §1 tài liệu này đã đúng tinh thần "scope disposition" từ vòng 1, không cần sửa repo doc thêm |

Cách chạy lại để tái tạo bằng chứng sạch (không đụng working tree chung đang có phiên khác sửa):

```bash
git worktree add --detach <tmp>/HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE <candidate-sha>
git worktree add --detach <tmp>/HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE <candidate-sha-fe>
# mklink /J <tmp>/HoaNam_BE/.../vendor <repo-that>/vendor  (junction, không copy)
# mklink /J <tmp>/HoaNam_FE/.../node_modules <repo-that>/node_modules
cd <tmp>/HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE && node scripts/verify-wwhn-230-closure.mjs --with-tests
cd <tmp>/HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE && node scripts/verify-wwhn-230-closure.mjs --with-tests
```
- FE gate `wwhn-230:verify`: **PASS** (21 check, 1 PENDING là sign-off).

### 6.1 Soát lại vòng 2 — sửa lệch nhãn commit (round 3, 2026-08-19)

Soát lại bằng `git merge-base --is-ancestor` phát hiện: **nội dung** `CLOSURE_EVIDENCE.json` tại
commit `b589106` tự nhất quán (worktree cô lập tại `831bda6d554faf3d1e1b11c5ff299bd53792f7bf`,
test 11/11 pass, đúng như bảng trên đã ghi), nhưng **chủ đề (subject) của chính commit `b589106`**
lại ghi "candidate 87bdd4c" — đó là SHA của HEAD nhánh `m1` tại thời điểm soạn message, không
phải SHA đã pin trong file. `87bdd4c` là cha trực tiếp của `b589106`; `831bda6` là ancestor xa
hơn — tức worktree cô lập được dựng trước, rồi các commit khác của phiên song song chen vào
trước khi `b589106` được commit lên working tree chung, làm message soạn theo HEAD ambient thay
vì theo giá trị đã pin trong file. Cùng bẫy bên FE: message `52969fb` ghi "candidate 1bb3cba"
(cha trực tiếp) trong khi JSON pin `f9f2e65e687669f033d259a693635546876f05a8` (ancestor xa hơn —
đúng như bảng §"Vòng 2" bản FE `docs/Task_docs_Jira/WWHN-230.md` đã ghi từ đầu).

**Không sửa lại commit cũ** (nhánh `m1` dùng chung, amend là destructive/rewrite history). Ghi
nhận tại đây làm nguồn tham chiếu chuẩn: SHA đúng, đã test thật bằng candidate cô lập, luôn là
SHA nằm **trong nội dung file** (`identity.backend.commitSha` / `identity.frontend.commitSha`),
không phải SHA trong dòng subject `git log --oneline` của commit regenerate đó. Áp dụng quy tắc
này cho mọi lần regenerate evidence sau này trên nhánh `m1` dùng chung: soạn message **sau khi**
đã ghi file xong, đọc lại đúng giá trị vừa ghi (`jq .identity.backend.commitSha
docs/evidence/WWHN-230/CLOSURE_EVIDENCE.json`) thay vì gọi lại `git rev-parse HEAD` một lần nữa —
giữa hai lần gọi, một phiên khác có thể đã commit thêm.

Không thay đổi kết luận `DEV230-P1-02R`: evidence vẫn hợp lệ, `commitSha` bên trong file vẫn là
candidate đã test thật (`workingTreeClean=true`, `testResult` không null). Chỉ nhãn subject của
2 commit lịch sử là lệch, đã ghi rõ tại đây để reviewer sau không hiểu nhầm SHA nào là chuẩn.

## 7. Điều còn mở, thuộc thẩm quyền ngoài repo

1. Sign-off 5 vai trò trên `WWHN-230` (comment mẫu: `docs/Task_docs_Jira/WWHN-230_SIGNOFF_COMMENTS.md` bên FE).
2. `WWHN-25 / DEC-STK-001` vẫn `In Progress` — không đóng được từ repo.
3. Các temp key `UNRESOLVED` trong register cần BA/BAS lấy từ Jira; `HN-MC-001`, `HN-HO-001`
   đang là `CANDIDATE`, không được dùng làm dependency gate cho tới khi xác nhận.
4. `WWHN-108` được review nêu trong `DEV230-P1-01` — BAS xác nhận lại canonical status khi
   đóng, repo không có nguồn nào cho ticket này.
