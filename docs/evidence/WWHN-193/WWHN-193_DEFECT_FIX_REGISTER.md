# WWHN-193 — Register đóng P1/P2 vòng review 72/100

Nguồn: review Wuangg 72/100, register `DEV193-P1-01R..05R` + `EXT-DEP-01R` +
`DEV193-P2-OLD-DEADCODE`.

Nguyên tắc: mục nào thuộc DEV thì đóng bằng code + test + số liệu thô; mục nào thuộc QA1/BA
thì giữ **FAIL-CLOSED / NEEDS OWNER DISPOSITION** — DEV không tự ký thay.

---

## DEV193-P1-01R · Source/candidate conflict (m1-be-2 vs m1) — ĐÃ ĐÓNG (DEV phần việc)

**Phát hiện:** candidate được nhắc trên `m1-be-2` (`c14281…`) trong khi test WWHN-193 chỉ có
trên `m1`.

**Đo đạc thay vì phỏng đoán:**

```
git merge-base --is-ancestor origin/m1-be-2 m1   → true
origin/m1-be-2 = 60816499066f9f493596e326dc41116ef32f5c4b
m1 đi trước m1-be-2 719 commit tại thời điểm pin
```

**Disposition:** RE-PIN sang `m1`, **không port ngược**. `m1` đã chứa toàn bộ nội dung của
`m1-be-2` cộng thêm test; port sẽ tạo bản sao thứ hai của cùng một thay đổi trên một nhánh đã
lỗi thời và làm chuỗi bằng chứng phân đôi thêm lần nữa. Từ đây mọi artifact WWHN-193 chỉ trích
dẫn SHA trên `m1`.

**Về "pin full SHA":** một commit không thể chứa hash của chính nó — đó chính là lý do bản pin
trước ghi tay một SHA rồi commit tiếp, khiến SHA trong file không bao giờ là SHA của revision
đang xét. Cách làm ở vòng này:

- `WWHN-193_PINS.json` pin **nội dung**: `base` + digest sha256 từng file thuộc ticket.
- Cổng `wwhn-193-putaway-closure.yml` chạy `scripts/verify-wwhn193-closure.mjs --expect-sha <SHA>`;
  verifier tự đối chiếu `--expect-sha` với `git rev-parse HEAD` và kiểm range invariant.
- Comment Jira mang đúng SHA đó → SHA chính xác được khẳng định ở nơi có thể khẳng định được.

**Còn lại (không thuộc DEV):** Tech Lead + QA1 duyệt disposition này.

---

## DEV193-P1-02R · QA1 chạy trên deployed candidate — FAIL-CLOSED / NEEDS OWNER DISPOSITION

Thuộc QA1 trên **WWHN-194**. DEV **không** tự ký. Phần DEV chuẩn bị sẵn:

- `WWHN-193_QA1_HANDOFF.md` — kịch bản, lệnh chạy, số liệu kỳ vọng, artifact cần đính kèm.
- Cổng CI chạy lại được tại đúng revision, sinh artifact có digest.

---

## DEV193-P1-03R · Formal retry/recovery contract — ĐÃ ĐÓNG

**Phát hiện:** "Retry hiện trả controlled 409 nhưng chưa formal recovery semantics."

**Đã chốt:** `DEC-PTW-RETRY-001` (xem `docs/decision-records/`). Hai tầng:

1. `Idempotency-Key` **tuỳ chọn** (giữ nguyên Annex C) → retry cùng key trả `200` + snapshot cũ
   + `data.replayed = true` + `Idempotent-Replay: true`; khác payload → `409 IDEMPOTENCY_CONFLICT`;
   đụng lease đang chạy → `409 CONCURRENCY_CONFLICT`.
2. Không key → vẫn `409 STATE_CONFLICT`, nhưng `error.details.recovery` mang
   `outcome` / `applied_by_caller` / `safe_to_treat_as_success` / `next_action`.

**Test bắt buộc theo register:**

| Yêu cầu register | Test |
|---|---|
| timeout-after-commit | `Wwhn193PutawayRetryRecoveryContractTest::test_confirm_retry_with_same_idempotency_key_replays_committed_result` |
| same operation concurrent | `::test_concurrent_retry_on_live_lease_returns_concurrency_conflict` |
| FE/BA biết cách recover | `DEC-PTW-RETRY-001` §3 + `putawayService.ts` + `putawayService.test.ts` |

Số liệu thô: `build/evidence/wwhn-193/retry-recovery-proof.json`.

---

## DEV193-P1-04R · Fail-closed audit trên PostgreSQL — ĐÃ ĐÓNG (DEV phần việc)

**Phát hiện:** "App-level rollback test đã có; PG target chưa chứng minh."

Đã chạy `Wwhn193PutawayAuditFailClosedTest` trên **PostgreSQL 16.14** (`hoa_nam_audit`), tiêm
lỗi ghi `audit_log` với `audit.write_policy = fail_closed`:

- HTTP `500`, `error.code = INTERNAL_SERVER_ERROR`, envelope **không** lộ trace/file/line/SQLSTATE.
- `db_before` == `db_after`: `putaway_events` vẫn `DRAFT` version 1; container vẫn
  `RECEIVED_PENDING_PUTAWAY`, `parent_container_id = NULL`, version 1.
- `audit_log` delta = 0; `audit_outbox` = 0 (rollback cuốn theo cả savepoint outbox).
- `stock_movements` = 0.

Artifact ghi kèm engine thật: `build/evidence/wwhn-193/audit-fail-closed-proof-pgsql.json`
(và bản `-sqlite.json` để đối chiếu hai driver). Tên file mang driver nên **không thể** ký nhầm
bằng chứng sqlite cho một cổng đòi PostgreSQL.

**Còn lại (không thuộc DEV):** QA1 + DevOps xác nhận trên môi trường của họ.

---

## DEV193-P1-05R · POST/PATCH /containers bypass — ĐÃ ĐÓNG

**Phát hiện:** "PATCH đã block; POST vẫn direct placement + CONFIRMED event; canonical store
không require posted receipt object."

Sửa cả ba nửa:

| Bề mặt | Trước | Sau |
|---|---|---|
| `POST /containers` với `parent_container_id` | Tạo container + INSERT `putaway_events` status `CONFIRMED` | `422 PUTAWAY_MUST_USE_TASK_FLOW`; không container, không event, không movement |
| `POST /containers` với `status` vòng đời | Nhận cả 7 trạng thái | `422 CONTAINER_STATUS_NOT_CREATABLE`; chỉ `EMPTY` được khai lúc tạo |
| `PATCH /containers/{id}` với `status` vòng đời | Nhận `STORED`/`RECEIVED_PENDING_PUTAWAY`/`ISSUED` | `422 CONTAINER_STATUS_LIFECYCLE_MANAGED`, state giữ nguyên |
| `POST /putaway-tasks` | `latestPostedReceipt()` chỉ là metadata tuỳ chọn | `assertPostedReceiptLinkage()` → `409 BOX_NOT_PUTAWAY_ELIGIBLE` + `reason = NO_POSTED_RECEIPT` |
| `POST /putaway-tasks/{id}/confirm` | Không kiểm lại linkage | `assertReceiptLinkageStillValid()` chặn cả DRAFT tồn dư |

Hai nửa bổ trợ nhau: kể cả khi trạng thái BOX bị đặt sai bằng đường khác (dữ liệu cũ, seeder,
migration), putaway vẫn dừng vì linkage chứng từ được kiểm ở **tầng dữ liệu**.

Dead code đi kèm đã xoá: `recordPutaway()` và `assertTrayUsable()` của
`CurrentBaselineContainerFlowController` (chỉ phục vụ đúng nhánh bypass vừa bị chặn).

Regression: `tests/Feature/Wwhn193ContainerPutawayBypassRegressionTest.php` (6 test) khoá cả
POST, PATCH và canonical store.

---

## EXT-DEP-01R · Chuỗi phụ thuộc WWHN-43/190/191/192 — ĐÃ ĐÓNG (DEV phần việc)

`tests/Feature/Wwhn193PutawayDependencyChainTest.php` chạy hết chuỗi trên **cùng một build**:

```
tạo phiếu nhập COMPONENT → đăng ký BOX → scan (stock-neutral, movements=0)
→ Post Receipt (movements=1, balance=7, stock_effect=INCREASE)
→ BOX = RECEIVED_PENDING_PUTAWAY + hiện trong /putaway-tasks kèm receipt_id
→ Putaway DRAFT (stock_document_id = phiếu vừa Post) → confirm (BOX=STORED)
→ movements vẫn 1, balance vẫn 7  → đối soát ledger vs projection: mismatch = 0
```

Kèm bài ngược: BOX đã scan nhưng phiếu **chưa** POSTED → `409 BOX_NOT_PUTAWAY_ELIGIBLE`,
`putaway_events = 0`, `movements = 0`. Bài ngược này là thứ làm chuỗi trên có ý nghĩa: Putaway
thực sự **phụ thuộc** Post Receipt chứ không chỉ tình cờ chạy sau.

Số liệu thô: `build/evidence/wwhn-193/dependency-chain-proof.json`.

**Còn lại (không thuộc DEV):** QA1 chạy lại trên RC.

---

## DEV193-P2-OLD-DEADCODE · Closed — GIỮ NGUYÊN

Không reopen. Kiểm lại ở vòng này: không route nào quay lại trỏ vào các method OpenAPI đã chết.
Dead code **mới** phát sinh từ P1-05R (`recordPutaway`/`assertTrayUsable`) đã xoá cùng lúc với
fix, không để lại lần hai.

---

## Phát hiện ngoài register (không thuộc WWHN-193, đã sửa vì cổng chạm phải)

`ERD-MIRROR-INBOUND-LINES` — lộ ra khi chạy bộ filter của cổng WWHN-193 trên **PostgreSQL**.

`PATCH /inbound-documents/{id}` trả `409` sau khi đã gỡ hết mã quét: `replaceDocumentLines()`
xoá rồi INSERT lại dòng với id mới, còn mirror `hn_erd_mirror_inbound_lines` chỉ upsert theo
`id` và **không xoá** row đã biến mất khỏi projection → hàng cũ vẫn giữ cùng
`(inbound_document_id, line_no)` → `23505` trên `ux_erd_inbound_lines_f891eba6` → abort
transaction. Hậu quả thật trên DB tham chiếu: **gỡ hết mã đã quét xong thì không sửa lại được
cấu trúc dòng phiếu nhập nữa**. Trên sqlite không lộ ra vì nhánh mirror chỉ chạy trên pgsql.

Đã sửa: `Modules/Warehouse/database/migrations/2026_08_19_130000_sync_inbound_lines_mirror_deletes.php`
(cùng khuôn với bản vá đã có cho `idempotency_records`). Bằng chứng:
`Flow02InboundPutawayAlignmentTest` trên pgsql 16.14 từ **15/16 → 16/16**.

**Bàn giao, không tự quyết:** gốc chung nằm ở `2026_08_10_000765` — generator mirror chỉ gắn
mệnh đề cleanup cho **allowlist 4 bảng** (`user_roles`, `role_permissions`,
`pv_product_industries`, `pv_product_tags`), mọi mirror còn lại đều insert/update-only. Rà soát
cả lớp này (mirror nào cần DELETE-sync, mirror append-only nào phải giữ nguyên) thuộc luồng ERD
nhóm **WWHN-31 / WWHN-310**; WWHN-193 chỉ vá đúng bảng đã chứng minh được là đang hỏng.

---

## Số liệu chạy tại revision này

Bộ filter của cổng (5 file WWHN-193 + 5 file bị contract P1-05R chạm tới):

| Driver | Kết quả |
|---|---|
| sqlite `:memory:` | **80 test / 79 pass / 0 fail / 0 error / 1 skip / 589 assertion** |
| PostgreSQL 16.14 | **80 test / 80 pass / 0 fail / 0 error / 591 assertion** |

Full suite **không đo được tại revision này**: `vendor/` bị một phiên song song cài lại nhiều
lần trong ngày (mất `autoload.php`, mất cả package `l5-swagger` giữa chừng) nên mọi lần chạy
toàn bộ đều chết ở bootstrap. Số liệu chính thức lấy từ cổng CI chạy trên môi trường sạch.

---

## Bảng trạng thái

| ID | Chủ sở hữu | Trạng thái sau vòng này |
|---|---|---|
| DEV193-P1-01R | DEV → Tech Lead + QA1 | Phần DEV **DONE**; chờ duyệt disposition |
| DEV193-P1-02R | QA1 (WWHN-194) | **FAIL-CLOSED / NEEDS OWNER DISPOSITION** |
| DEV193-P1-03R | DEV + QA1 → Tech Lead + BA | Phần DEV **DONE** (DEC-PTW-RETRY-001) |
| DEV193-P1-04R | DEV + DevOps → QA1 | Phần DEV **DONE** trên PostgreSQL 16.14 |
| DEV193-P1-05R | DEV → Tech Lead + BA + QA1 | **DONE** |
| EXT-DEP-01R | DEV/QA1 | Phần DEV **DONE**; chờ QA1 trên RC |
| DEV193-P2-OLD-DEADCODE | — | **CLOSED**, không reopen |
