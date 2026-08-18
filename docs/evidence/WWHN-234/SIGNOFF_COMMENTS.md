# WWHN-234 — Comment xác nhận / sign-off theo vai trò

Mỗi vai trò **một comment duy nhất**, phủ hết phần mình phải ký trong register. Dán nguyên
văn lên Jira, điền tên/ngày rồi post.

Neo dùng chung cho mọi comment:
- BE `696868ce44ccccca51884c3c1ac7e9f41fd405b8` · FE `48f7ff096ee6c570ff3a27c93f1d47b4e3b8595e`
- Evidence: `docs/evidence/WWHN-234/` · Verifier: `node scripts/verify-wwhn234-closure.mjs` → **24/24 PASS**

---

## 1. BA Main — post lên `WWHN-59` (và link sang `WWHN-234`, `WWHN-235`, `WWHN-25`)

> **Xác nhận DEV234-P1-01 — scope authority của Stocktake.**
>
> Tôi xác nhận `WWHN-234` **kế thừa `DISP-STK-233`** (nhánh `WWHN-59 / 233 / 235`) và
> **không** phát hành disposition mới. Hai văn bản cùng trả lời một câu hỏi cho cùng một
> nhánh ticket chính là cơ chế đã khiến phát hiện này lặp lại nhiều vòng.
>
> Tôi xác nhận impacted list của `DISP-STK-233` được bổ sung `WWHN-234`, thành:
> **WWHN-59, WWHN-233, WWHN-234, WWHN-235**.
>
> Căn cứ giữ nguyên như `DISP-STK-233` §2: `REQ-SRS-037` (Must) trong SRS/FRD v1.12 đã adopt
> tại `WWHN-334`. Đo lại tại SHA của WWHN-234: 0/239 route, 0 permission, 0 controller,
> 0 operation trong OpenAPI 2.0.0, 0 bảng `stocktake_*`. `WWHN-25` / `DEC-STK-001` giữ
> **PROPOSED / NO EFFECTIVE DATE**.
>
> Tôi cũng xác nhận đã đọc `CONCURRENCY_STATE_REVIEW.md` và ghi nhận finding **F-07**:
> SRS §13.3 thiếu `TC-P1-017..024`, nhóm `COMPONENT_ISSUE_POST` chưa có hàng nào trong ma
> trận TC dù là action đổi tồn. Tôi mở mục này cho bản SRS kế tiếp.
>
> DISP-STK-233 version: 1.0.0 · Effective date: ______ · BA Main: ______ · Ngày: ______

---

## 2. PM/PO — post lên `WWHN-59`

> **Xác nhận DEV234-P1-01 — phê duyệt scope.**
>
> Tôi xác nhận `WWHN-234` nằm dưới `DISP-STK-233` — Stocktake **DEFERRED / NO CODE**, đúng
> như trạng thái deployment hiện tại. Không có CR nào đưa Stocktake trở lại.
>
> Tôi xác nhận điều kiện đảo trạng thái là bốn điều kiện của `DISP-STK-233` §2, áp dụng
> nguyên văn cho `WWHN-234`: approved CR, `WWHN-25` ký `GO`/`CONDITIONAL GO` kèm effective
> date, SRS rebaseline hạ `REQ-SRS-037` khỏi `Must`, và chỉ khi đủ ba điều đó mới mở DEV.
> Không chấp nhận đường tắt.
>
> Tôi xác nhận `WWHN-235` phần Stocktake giữ nguyên **DEFERRED / N/A** theo
> `docs/evidence/WWHN-233/QA_DISPOSITION_WWHN-235.md`, không để `Blocked`/`Pending`, và
> không tạo văn bản disposition thứ hai.
>
> PM/PO: ______ · Ngày: ______

---

## 3. Tech Lead — post lên `WWHN-234`

> **Xác nhận DEV234-P1-02 và DEV234-P1-04.**
>
> Tôi xác nhận đã đọc và duyệt `CONCURRENCY_STATE_REVIEW.md`: bản review có đủ chín mục mà
> register yêu cầu (state matrix, lock target/order, stale/version, duplicate, transaction
> boundary, rollback, partial, stock-effect boundary, permission/error/audit), mọi mệnh đề
> đều dẫn `file:line` trên SHA đã pin.
>
> Tôi xác nhận hai defect P1 đã sửa đúng hướng:
> - **F-01/F-02** — bốn lớp xung đột 409 của SRS §8.2 nay có đúng một nguồn phát
>   (`app/Support/ConflictCode.php`). Bỏ `VERSION_CONFLICT`, `CONTAINER_VERSION_CONFLICT`,
>   `REVERSAL_CONCURRENT_CONFLICT`, `REVERSAL_DEPENDENCY_EXISTS`.
> - **F-09** — Post Issue và Component Issue Post nay phát `data.replayed` như Post Receipt
>   (`TC-P1-013`). Trước đây chỉ 3/8 đường idempotent đổi tồn có cờ này.
>
> Tôi xác nhận `DEV234-P1-04`: đây **có** code change, danh sách file và lý do từng file
> nằm trong `REVIEW_IDENTITY_PIN.json`; pin dùng live invariant thay cho blob tuyệt đối vì
> `m1` là nhánh dùng chung.
>
> Tôi ghi nhận và chấp nhận 3 finding P2 còn OPEN: **F-04** (thứ tự khoá
> `inventory_balances` theo dòng chứng từ — đã có retry 3 lần hấp thụ), **F-05** (route
> Component Issue Post thiếu data-scope middleware — không khai thác được dưới MVP một kho
> theo `REQ-SRS-001`), **F-06** (lost update trên `label_snapshots.version` — stock-neutral).
> Ba mục này tách ticket riêng, không chặn `WWHN-234`.
>
> Tech Lead: ______ · Ngày: ______

---

## 4. QA Lead / QA1 — post lên `WWHN-235`

> **Xác nhận DEV234-P1-03 — nhận handoff.**
>
> Tôi xác nhận đã nhận `QA1_HANDOFF_WWHN-235.md` và `TC_P1_TRACEABILITY.json`, và đồng ý
> tách ticket thành hai phần:
> - **235-A** (`POST_RECEIPT`, `POST_ISSUE`, `POST_REVERSAL`, `MIGRATION_COMMIT`): **PHẢI
>   CHẠY** trên đúng `696868ce44ccccca51884c3c1ac7e9f41fd405b8`, kèm 6 truy vấn DB
>   verification ở §2.4 của handoff.
> - **235-B** (Stocktake): **DEFERRED / N/A** — giữ nguyên theo `DISP-STK-233` §8 và
>   `docs/evidence/WWHN-233/QA_DISPOSITION_WWHN-235.md`. Tôi chỉ trỏ tới văn bản đã có và
>   chạy negative gate (`verify-wwhn233-count-disposition.mjs`, `Wwhn233CountDeferredGate`,
>   FE `verify-wwhn-233-closure.mjs`); **không** viết disposition mới.
>
> Tôi xác nhận ánh xạ TC↔test: **30 COVERED / 1 PARTIAL / 1 GAP** trên 32 TC của SRS §13.3,
> và ghi nhận 3 khoảng trống phải đóng bằng test chứ không bằng lời: **G-01** `TC-P1-016`
> (POST_ISSUE rollback chưa có inject-failure), **G-02** `TC-P1-039` (MIGRATION_COMMIT
> concurrency), **G-03** `TC-P1-017..024` (không tồn tại — chuyển BA).
>
> Tôi xác nhận sẽ kiểm chứng ở tầng response, không chỉ tầng test: `412 STALE_VERSION`,
> `409 CONCURRENCY_CONFLICT`, `409 DOWNSTREAM_DEPENDENCY_EXISTS`, `409 IDEMPOTENCY_CONFLICT`,
> `428 PRECONDITION_REQUIRED`, và `data.replayed=true` trên **cả** Post Receipt lẫn Post Issue.
>
> QA1: ______ · Ngày: ______

---

## 5. BE1 (DEV) — post lên `WWHN-234`

> **Bàn giao DEV234-P1-02 / DEV234-P1-04.**
>
> Tôi xác nhận đã post bản Concurrency & State Review thật tại
> `docs/evidence/WWHN-234/CONCURRENCY_STATE_REVIEW.md`, pin exact SHA BE
> `696868ce44ccccca51884c3c1ac7e9f41fd405b8` và FE `48f7ff096ee6c570ff3a27c93f1d47b4e3b8595e`,
> OpenAPI `2.0.0`.
>
> Bằng chứng chạy thật (`EXECUTION_LOG.md`): BE **934 test / 906 pass / 28 skip / 0 fail**;
> FE **65 test pass** + **13 test** hợp đồng đồng thời; `tsc --noEmit` sạch; verifier
> `node scripts/verify-wwhn234-closure.mjs` → **24/24 PASS**.
>
> Tôi xác nhận task này **không** claim đã review runtime Stocktake — không có runtime để
> review; phạm vi review là 5 action đổi tồn thật của baseline.
>
> BE1: ______ · Ngày: ______
