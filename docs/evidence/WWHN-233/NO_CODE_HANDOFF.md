# WWHN-233 — NO-CODE / DEFERRED handoff

| Field | Value |
|---|---|
| Jira task | `WWHN-233` |
| Parent | `WWHN-59` |
| QA gate | `WWHN-235` |
| Decision gate | `WWHN-25` / `DEC-STK-001` (In Progress, PROPOSED) |
| Disposition | `DISP-STK-233` v1.0.0 — **DEFERRED / NO CODE** |
| Controlling source | `REQ-SRS-037`, `HN_SRS_FRD_v1.12_FULL.md` (adopt tại `WWHN-334`) |
| Branch | `m1` |
| Handoff type | **NO-CODE** |
| Exact SHA | `docs/evidence/WWHN-233/SOURCE_REGISTER.json` → `repository.be_commit_sha` |

Đóng `DEV233-P1-02`.

---

## 1. Vì sao "0 commit" là kết quả đúng, không phải thiếu sót

Register ghi: *"0 attachment, không PR/SHA; search WWHN-233 = 0 commit"*. Với disposition DEFERRED,
đó là **kết quả mong đợi** — không phải bằng chứng của việc bỏ bê. Điều bị thiếu là **bản ghi tường
minh** rằng đó là chủ ý, chứ không phải để người đọc tự suy đoán.

Nếu WWHN-233 được implement, nó sẽ vi phạm `REQ-SRS-037` (Must) — một requirement đã có hiệu lực.
Không có cách nào vừa PASS AC gốc của `WWHN-59` vừa PASS `REQ-SRS-037`; xem `DISP-STK-233` §1.

---

## 2. Nội dung handoff

| Hạng mục | Giá trị |
|---|---|
| Route / controller / service cho capability | **0** |
| Migration tạo bảng kiểm kê | **0** |
| Permission kiểm kê | **0** |
| Màn hình / menu / report | **0** |
| Endpoint sửa tồn trực tiếp | **0** |
| Thay đổi cho *hàng rào* | 4 file (xem §3) |

Bốn bảng `stocktake_*` được migration `2026_01_01_000044` tạo trong lịch sử tiến hoá schema và bị
`2026_08_09_000400` / `2026_08_09_000420` / `2026_08_10_000600` drop. **End-state schema không có
bảng nào trong bốn bảng đó.** Chain migration được giữ nguyên có chủ đích: xoá một migration đã chạy
sẽ phá `migrate:rollback` (xem `WWHN-300`). Nghĩa vụ được kiểm chứng ở **end-state**, không ở lịch
sử — `Wwhn233CountDeferredGateTest::test_no_stocktake_table_survives_migration`.

---

## 3. Thay đổi hàng rào trong task này

| File | Vai trò |
|---|---|
| `docs/decision-records/DISP-STK-233-COUNT-RECOUNT-DEFERRED.md` | Disposition |
| `docs/contracts/HoaNam_Current_Baseline_Contract.json` | `+recount`, `+cycle-count`, `+physical-count` |
| `scripts/verify-wwhn233-count-disposition.mjs` | Verifier fail-closed + `ANTI-DANGLING` |
| `tests/Feature/Wwhn233CountDeferredGateTest.php` | Khoá route/permission/schema/constraint thật |

FE: `scripts/verify-wwhn-233-closure.mjs`, `src/test/wwhn-233-count-deferral-gate.test.ts`, refresh
digest trong `docs/api-evidence/STOCKTAKE_GOVERNANCE_STATUS.json`.

Không file nào ở trên thêm capability. Tất cả chỉ **chặn** capability tái xuất hiện.

---

## 4. Ownership (đóng `DEV233-P2-01`)

| Vai trò | Giá trị |
|---|---|
| Owner narrative trong Description Jira | **Duy Khánh** — `historical placeholder`, không phải trách nhiệm hiện hành |
| Live assignee / current DEV owner | **Quang** |
| Hành động | PM/BAS đồng bộ Description trên Jira: giữ tên cũ trong phần trace lịch sử kèm nhãn `historical placeholder`, phần responsibility trỏ current DEV owner |

Repository không phải nơi lưu assignee; bản ghi này chỉ để trace không bị mất khi Description được sửa.

---

## 5. Điều kiện re-open

Bốn điều kiện ở `DISP-STK-233` §2, giống hệt nhánh `WWHN-236` để hai nhánh mở cùng lúc:

1. Approved Change Request nâng phạm vi MVP, **và**
2. `WWHN-25` ghi `GO` / `CONDITIONAL GO` với effective date, **và**
3. SRS rebaseline — `REQ-SRS-037` được sửa/gỡ ở một version SRS mới có số hiệu và ngày, **và**
4. Chỉ khi đủ 1–3 mới mở DEV.

Khi đó `DEV233-P1-04`, `P1-05`, `P1-06` quay lại **đúng nguyên văn** yêu cầu ở register và phải đóng
bằng evidence dương (OpenAPI/Postman tech-approved; race/retry/rollback/reconcile report chạy thật),
không phải bằng tài liệu này.
