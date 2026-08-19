# WWHN-238 — QA disposition (DEV236-P1-07)

| Field | Value |
|---|---|
| Ticket | `WWHN-238` — QA1 independent exact-build test cho Apply Adjustment |
| Disposition | **DEFERRED / N/A cho MVP v1.12** |
| Trạng thái **không** được dùng | `Blocked`, `Pending`, `In Progress`, `Failed` |
| Căn cứ | `REQ-SRS-037` (Must) — `HN_SRS_FRD_v1.12_FULL.md`, adopt tại `WWHN-334` |
| Disposition cha | `DISP-STK-236` |
| Owner → Reviewer | QA1 + BA/PM → Tech Lead |

---

## 1. Vì sao không phải `Blocked`

`Blocked` nghĩa là *"sẽ test, đang chờ thứ gì đó"*. Điều đó sai ở đây: không có gì đang được chờ.
Capability mà `WWHN-238` định kiểm thử đã bị controlling source **loại khỏi phạm vi**, nên không tồn
tại build nào để QA1 test, bây giờ hay sau này trong v1.12.

Để `WWHN-238` ở `Blocked` là nguyên nhân khiến vòng rà soát nào cũng phải mở lại nó, hỏi lại cùng câu
hỏi và không đóng được — đúng cái mà register `DEV236-P1-07` yêu cầu chấm dứt
(*"không để Blocked/Pending mơ hồ"*).

`DEFERRED / N/A` có ngữ nghĩa chính xác: **không thực hiện trong scope này**, và điều kiện mở lại đã
được viết ra, kiểm chứng được, không phụ thuộc trí nhớ ai.

---

## 2. Phạm vi QA thực tế của WWHN-236 ở MVP v1.12

QA1 **không** chạy exact-build test dương cho Apply Adjustment. Thay vào đó QA1 xác nhận **negative
gate** — capability không tồn tại ở mọi tầng đang sống:

| # | QA1 xác nhận | Cách chạy | Kỳ vọng |
|---|---|---|---|
| 1 | Không API sửa tồn trực tiếp | `node scripts/verify-wwhn236-adjustment-disposition.mjs` | exit `0`, 11/11 PASS |
| 2 | Route / permission / schema / constraint bằng zero | `php artisan test --filter=Wwhn236DeferredAdjustmentGateTest` | 7/7 pass |
| 3 | Constraint DB thật chặn `ADJUST_*` | `php vendor/bin/phpunit --filter=Wwhn236DeferredAdjustmentGateTest` **trên PostgreSQL 16 thật** (migration blocker đã được `WWHN-289` sửa 2026-08-19) — `docs/evidence/WWHN-236/logs/be-phpunit-pgsql.log`; đối chiếu độc lập bằng truy vấn trực tiếp — `docs/evidence/WWHN-236/logs/pg-direct-constraint-probe.log` | 7/7 test PASS trên pgsql; `REVERSAL` qua CHECK, `ADJUST_IN` bị `chk_stock_movement_type_current` chặn |
| 4 | FE không có route / menu / permission kiểm kê | `npm run wwhn-236:verify` (FE) | exit `0` |
| 5 | Gate fail-closed, không phải chỉ happy path | negative probe A/B, `docs/evidence/WWHN-236/logs/` | exit `1` khi vi phạm |
| 6 | Đường sửa sai hợp lệ còn sống | `test_canonical_reversal_correction_path_stays_available` | 3 route reverse tồn tại |

Đó là **toàn bộ** phạm vi QA của WWHN-236 ở MVP này. Không có test case dương nào bị bỏ sót, vì không
có hành vi dương nào tồn tại để bỏ sót.

---

## 3. Test case dương được bảo lưu cho lần re-enable

Nếu và chỉ nếu bốn điều kiện re-enable ở `DISP-STK-236` §2 được thoả, `WWHN-238` được mở lại **nguyên
phạm vi ban đầu** theo `DEV236-P1-06`, và phải đóng bằng evidence dương chạy trên PostgreSQL:

- lock / CAS trên session và balance;
- same-key replay trả đúng response đã commit; same-key + different payload trả `409`;
- atomic movement / balance / session — commit hoặc rollback trọn vẹn;
- ledger append-only cho `ADJUST_IN` / `ADJUST_OUT`;
- stale version bị reject;
- không double-effect sau retry / race / timeout-after-commit;
- reconcile `mismatch_count = 0` sau apply.

Không mục nào trong danh sách đó được coi là đã đóng bởi tài liệu này.

---

## 4. Điều QA1 và BA/PM cần ký

Chỉ đọc + duyệt:

- [ ] `WWHN-238` chuyển sang **DEFERRED / N/A cho MVP v1.12**, gỡ khỏi `Blocked`/`Pending`.
- [ ] Phạm vi QA của WWHN-236 ở v1.12 là **negative gate** ở §2, đã chạy và pass.
- [ ] Danh sách test case dương ở §3 được bảo lưu nguyên vẹn cho lần re-enable, không bị coi là đã đóng.
- [ ] Điều kiện mở lại là bộ 4 điều kiện ở `DISP-STK-236` §2 — chung cho cả chuỗi
      `WWHN-60/233/234/235/236/238` và `HN-STK-002`.

---

## 5. QA1 chạy lại tại exact SHA (`DEV236-P1-07R3`)

Register R3 đòi QA1 **soi độc lập**: checkout đúng exact SHA đã pin, chạy negative gate, rồi ghi
`PASS` / `FAIL` / `DEFERRED-N/A` **trực tiếp trên Jira** kèm SHA / env / PostgreSQL version / command /
raw log. Để QA không phải tự ghép lệnh và tự nhớ ghi env, dùng đúng một lệnh:

```bash
node scripts/qa-wwhn236-exact-sha-gate.mjs
```

Khi QA đã checkout sạch đúng SHA thì thêm `--strict-head` để khoá luôn cả HEAD:

```bash
node scripts/qa-wwhn236-exact-sha-gate.mjs --strict-head
```

Lệnh này tự ghi vào raw log: OS, Node, Git, PHP, `DB_CONNECTION`, PostgreSQL version, branch, HEAD của
cả hai repo, candidate SHA đã pin, trạng thái approval — rồi chạy tuần tự:

| # | Bước | Nội dung |
|---|---|---|
| 1 | `IDENTITY` | Candidate SHA là ancestor của HEAD; **blob** của từng governed file khớp bản đã pin; bản trên đĩa khớp bản đã commit |
| 2 | `BE verifier` | `node scripts/verify-wwhn236-adjustment-disposition.mjs` |
| 3 | `BE phpunit` | `php vendor/bin/phpunit --filter Wwhn236DeferredAdjustmentGateTest` |
| 4 | `FE verifier` | `node scripts/verify-wwhn-236-closure.mjs` |

Raw log: `docs/evidence/WWHN-236/logs/qa-rerun-<be-sha7>.log`.

> **Vì sao mặc định không bắt `HEAD == SHA đã pin`.** `m1` có nhiều phiên làm việc song song nên HEAD
> chạy tiếp ngay sau khi task này commit. Mệnh đề QA cần chứng minh không phải "HEAD đứng yên" mà là
> "tập artifact của WWHN-236 không đổi kể từ SHA đã pin" — bước 1 kiểm đúng mệnh đề đó bằng blob sha,
> nên bằng chứng không mục khi phiên khác commit. `--strict-head` dành cho lần QA checkout sạch.

### Ô QA1 phải điền trên `WWHN-238`

| Trường | Lấy từ đâu |
|---|---|
| Kết quả | Dòng `KẾT QUẢ QA (WWHN-238):` cuối raw log |
| Exact SHA (BE / FE) | Dòng `BE candidate (pin)` / `FE candidate (pin)` |
| Env | Các dòng `OS` / `Node` / `PHP` / `PostgreSQL` / `DB driver (.env)` |
| Command | Các dòng bắt đầu bằng `$` trong raw log |
| Raw log | `docs/evidence/WWHN-236/logs/qa-rerun-<sha>.log` |
| Trạng thái ticket | `DEFERRED / N/A cho MVP v1.12` — **không** `Blocked`, **không** `Pending` |
