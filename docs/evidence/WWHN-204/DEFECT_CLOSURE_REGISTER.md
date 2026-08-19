# WWHN-204 — Register đóng P0/P1/P2 (đánh giá 61/100 → hồ sơ đóng)

Nhánh: **`m1`** · Repo BE: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` ·
Verifier một lệnh: `node scripts/verify-wwhn-204-closure.mjs`

---

## 1. Trạng thái từng mục của register

| ID | Sev | Yêu cầu | Trạng thái | Bằng chứng |
|---|---|---|---|---|
| **DEV204-P1-01** | P1 | Xác nhận `m1` là candidate branch **hoặc** port sang `m1-be-2`; pin exact commit/PR | **ĐÓNG (DEV) — chờ PM/Tech Lead ký** | `BRANCH_CANDIDATE_PIN.md`; `INV-BRANCH-01..04` trong `WWHN-204_PINS.json` |
| **DEV204-P1-02** | P1 | QA1 thực thi ledger/balance/rebuild suite đúng candidate SHA | **SẴN SÀNG — chờ QA1 chạy** | `QA1_RUNBOOK.md` (7 mục, 6 mốc M1..M6); suite đã chạy sẵn 2 driver: `logs/sqlite-focused.log`, `logs/pg-suite.log` |
| **DEV204-P1-03** | P1 | Implement/formalize rebuild balance từ immutable ledger; sau intentional drift đưa projection về ledger | **ĐÓNG** | `InventoryProjectionRebuildService` + `--rebuild`; `DISP-INV-204`; 6 test; drift 99→10, `mismatch = 0` ở `logs/pg-append-only.log` |
| **DEV204-P1-04** | P1 | Chạy migration + append-only UPDATE/DELETE + reconciliation trên PostgreSQL mục tiêu | **ĐÓNG** | `PG_APPEND_ONLY_EVIDENCE.md`; `logs/pg-append-only.log` (16 bước, PG 16.14) |
| **DEV204-P1-05** | P1 | Formal disposition DEC-INV-001/technical baseline; resolve pin nguồn v3.9/Handoff; FE/QA cùng contract | **ĐÓNG (DEV) — chờ BA/BAS + Tech Lead ký** | `DISP-INV-204-projection-rebuild.md`; `SOURCE_BASELINE_PIN.md` |

## 2. Lỗi gốc đã sửa (không phải chỉ viết tài liệu)

### 2.1. Không có cơ chế đưa projection về ledger — WWHN-48 AC#2 hở

`inventory:reconcile` trước đây **chỉ phát hiện** lệch và ghi `reconciliation_incident`; không
có đường nào đưa `inventory_balances` về đúng `stock_movements`. Sau một intentional drift, hệ
thống ở lại trạng thái sai vô thời hạn, chỉ tích thêm incident.

Nguyên nhân khiến việc này bị treo lâu: yêu cầu "rebuild balance" **trông giống** thứ mà
`REQ-SRS-037` đã gỡ khỏi sản phẩm (stocktake / apply-adjustment / sửa tồn trực tiếp), nên các
ticket trước chọn park (`DISP-STK-236`, `DISP-STK-237`). `DISP-INV-204` tách bạch hai khái niệm
bị gộp làm một (hấp thụ chênh lệch thực tế **vs.** dựng lại bản sao dẫn xuất) rồi mới hiện thực.

**Đã sửa:** `Modules/Warehouse/app/Services/InventoryProjectionRebuildService.php` +
`inventory:reconcile --rebuild [--dry-run] [--reason=]`, ràng buộc R1..R6 (`DISP-INV-204` §4).

### 2.2. Append-only chỉ được cưỡng chế trên PostgreSQL — bất biến không kiểm chứng được ở CI

`2026_08_15_000100_enforce_stock_movements_append_only_trigger.php` mở đầu bằng
`if (DB::getDriverName() !== 'pgsql') return;`. Trên **sqlite** — driver mà toàn bộ test suite
và CI `current-baseline-doc-contract.yml` chạy — ledger chỉ còn hàng rào ở tầng Eloquent
(`StockMovement::booted()`), trong khi **mọi** đường ghi tồn thật (`CurrentBaselineStockService`,
`AlignedOutboundStockService`, `CurrentBaselineReversalService`) đều dùng `DB::table(...)`, tức
đi vòng qua Eloquent.

Hệ quả thật: bất biến *"Movement append-only"* của `DEC-INV-001` §7.1 **không thể chứng minh
bằng test trên driver mặc định** — một hồi quy phá vỡ tính bất biến sẽ lọt qua CI mà không ai
biết. Đây là cùng lớp lỗi mà chính migration `2026_08_15_000100` đã ghi trong docblock (trigger
của `2026_08_10_000770` bảo vệ nhầm bảng mirror thay vì bảng live).

**Đã sửa:** `2026_08_19_050100_enforce_stock_movements_append_only_on_sqlite.php` — cùng ngữ
nghĩa, cùng thông điệp `... is append-only under HN-WMS-ERD-DD-001`, chỉ gắn khi
`stock_movements` là bảng vật lý. Test `test_stock_movements_rejects_update_and_delete_at_database_level`
nay chạy đúng trên cả hai driver.

## 3. Kết quả chạy

| Hạng mục | Driver | Kết quả |
|---|---|---|
| Suite ledger/balance/reconcile/rebuild (7 class) | sqlite (driver CI) | **35 test / 733 assertion / 0 fail** |
| Suite ledger/balance/rebuild (4 class) | PostgreSQL 16.14 | **24 test / 637 assertion / 0 fail** |
| Bằng chứng DB thô 16 bước | PostgreSQL 16.14 | Toàn bộ mốc M1..M6 PASS |
| `vendor/bin/pint --test` (4 file PHP của ticket) | — | PASS, không cần auto-fix |
| `node scripts/verify-wwhn-204-closure.mjs` | — | PASS |

## 4. Bất biến sẽ làm đỏ build nếu ai đó phá

| Bất biến | Cưỡng chế ở |
|---|---|
| Rebuild không ghi `stock_movements` | Trigger DB (2 driver) + `ledgerFingerprint()` trước/sau + check tĩnh trong verifier |
| Rebuild không có route HTTP | `test_rebuild_is_not_exposed_as_any_http_route` + verifier quét `routes/*.php` |
| Bước phát hiện chạy trước rebuild (giữ dấu vết divergence) | Verifier so vị trí `option('rebuild')` sau `recordIncident` |
| Hai migration append-only dùng chung một thông điệp | Verifier |
| `REQ-SRS-037` còn là Must, `BRD v3.9` còn SUPERSEDED trong SRS | Verifier đọc thẳng SRS v1.12 — nếu baseline đổi thì hồ sơ này phải review lại chứ không im lặng đúng tiếp |

## 5. Exact SHA

| Mục | Giá trị |
|---|---|
| Repo BE | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`, nhánh `m1` |
| Commit hiện thực gốc của WWHN-204 (BE2, 2026-08-15) | `7a78477424cc5b4e0685a0fa702dd085ee9f882e` |
| Commit đóng P1 — code + test + verifier + script | `da1c5aff2993982b532d7303f840b210048226e2` |
| **Commit code CUỐI CÙNG — siết concurrency + cổng CI 2 job** | **`114cf34d6323c85d46ecab2bfa6e03d92b9596a1`** |
| Commit evidence/docs | `1a9aff1c34665767d1d43a49c0c6dd1709c4a226` (chỉ chạm `docs/` + script evidence) |

> **Đính chính attribution — 4 file docs nằm trong commit của ticket khác.**
> `docs/decision-records/DISP-INV-204-projection-rebuild.md`,
> `docs/evidence/WWHN-204/BRANCH_CANDIDATE_PIN.md`, `.../PG_APPEND_ONLY_EVIDENCE.md` và
> `.../SOURCE_BASELINE_PIN.md` được ghi bởi WWHN-204 nhưng lại **được commit bởi `a013c17`
> (`feat(WWHN-201): ...`)** — phiên làm việc song song trên `m1` chạy `git add -A` và cuốn theo
> file đang nằm trong working tree chung. Nội dung không bị sửa đổi (đối chiếu
> `git diff HEAD -- docs/evidence/WWHN-204` = rỗng). Không viết lại lịch sử nhánh dùng chung
> chỉ để đổi attribution; ghi lại ở đây để reviewer tra `git log` không kết luận nhầm là hồ sơ
> WWHN-204 chưa được commit.
>
> Cùng lý do đó, index dùng chung có lúc **stage sẵn lệnh xoá** `BASELINE_FAILURE_ATTRIBUTION.md`
> và `logs/sqlite-full-suite.log`; đã khôi phục. Ai làm tiếp trên `m1` nên commit theo index
> tạm (`GIT_INDEX_FILE=$PWD/.git/<ticket>.index git read-tree HEAD && git add -f <paths> && git commit`)
> thay vì dùng index chung.
| Ancestor pin (`origin/m1-be-2` HEAD) | `60816499066f9f493596e326dc41116ef32f5c4b` |
| FE tại thời điểm chốt | `6ed417e9291297a20505a45909102ed3349b2de0` — **không có thay đổi FE trong ticket này** |

> `m1` là nhánh dùng chung nhiều phiên. Điều kiện nghiệm thu **không** phải "HEAD bằng một hằng
> số", mà là `git merge-base --is-ancestor 114cf34d6323c85d46ecab2bfa6e03d92b9596a1 HEAD` trả
> `0` — xem `QA1_RUNBOOK.md` §0.

## 5.1. Lỗi đỏ ngoài phạm vi — đã quy trách nhiệm từng mục

Full suite tại thời điểm đóng: **1053 test / 1006 pass / 17 fail + 1 error / 29 skip**.
**0/18 thuộc WWHN-204** — chứng minh từng mục ở `BASELINE_FAILURE_ATTRIBUTION.md`, trong đó bằng
chứng cứng nhất là: file test **duy nhất** trong toàn repo có `UPDATE`/`DELETE` lên
`stock_movements` chính là test của WWHN-204 (và nó kỳ vọng bị chặn), nên trigger append-only mới
không thể làm đỏ test của ai khác. Bất biến này nay được verifier canh giữ.

Hai việc chặn người khác, đã chuyển cho chủ sở hữu: fatal error trong
`tests/Feature/Wwhn310Round2ClosureTest.php` (untracked) làm **chết cả full suite**, và
`2026_08_19_000050_repair_erd_mirror_projection_casts` có `down()` rỗng làm đỏ toàn bộ
`Wwhn300BackendGoLiveCheckTest`.

## 6. Còn lại — không thuộc quyền tự ký của DEV

| Ai | Việc | Ghi chú |
|---|---|---|
| PM / Tech Lead | Ký xác nhận `m1` là candidate branch (P1-01) | Chỉ cần đọc + duyệt `BRANCH_CANDIDATE_PIN.md`; không có việc code |
| BA/BAS | Ký `DISP-INV-204` + cập nhật ô Source & Traceability trên Jira `WWHN-24`/`WWHN-31`/`WWHN-48` từ v3.9 sang BRD v3.20 + SRS v1.12 (P1-05) | Biên tập traceability, **không** phải quyết định nghiệp vụ mới |
| QA1 | Chạy `QA1_RUNBOOK.md`, kết luận cho `WWHN-206` và `WWHN-48` AC#2 (P1-02) | Runbook tự chạy được, không cần DEV |
| DevOps | Xác nhận PG mục tiêu của môi trường triển khai cùng cấu hình đã dùng (PG 16.14) (P1-04) | Script `collect-wwhn204-pg-evidence.sh` chạy lại được trên môi trường của DevOps |
| FE1 | Xác nhận không phải sửa gì (P1-05) | Bằng chứng "0 endpoint / 0 permission / 0 response shape đổi" ở `SOURCE_BASELINE_PIN.md` §4 |
