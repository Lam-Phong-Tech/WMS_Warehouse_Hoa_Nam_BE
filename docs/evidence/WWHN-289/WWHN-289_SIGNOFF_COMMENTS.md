# WWHN-289 — Comment xác nhận (mỗi vai trò đúng 1 comment)

Quy tắc: **một vai trò chỉ comment một lần**, nói rõ xác nhận **cái gì**, ngắn gọn, và **không để verdict dạng lựa chọn** — tức là không đặt hai kết luận trái nhau cạnh nhau bằng dấu gạch chéo hay ngoặc vuông để người đọc tự chọn. Reviewer coi kiểu đó là chưa ký (bài học WWHN-328).

Comment 1 do BE1 đăng trên **WWHN-289**. Comment 2–4 là xác nhận thuần (đọc + duyệt, không phát sinh việc mới) và đăng đúng ticket ghi ở tiêu đề.

---

## 1. BE1 — Quang Nguyễn Thanh · đăng trên WWHN-289

> **BE1 — Nộp bằng chứng cho DEV289-P1-02 / P1-03 / P1-04**
>
> Build đã pin: BE `m1` @ `8bdf9ad0a00461afe27e3e11a26e7fd13d68f9e6` · FE `m1` @ `404267083d2c0ca79507f7fc1fdf05170cdae48e` · nguồn pin duy nhất `docs/evidence/WWHN-289/WWHN-289_PINS.json`.
>
> **P1-02 — PR/SHA/diff scope/fix map:** register đầy đủ tại `docs/evidence/WWHN-289/WWHN-289_DEFECT_FIX_REGISTER.md`. Ba defect được sửa tận gốc, mỗi cái gắn với một điều khoản baseline:
> • `SEC289-01` luồng nhập kho **không có data scope** — chỉ luồng xuất và 6 endpoint đọc tồn có; user `scope_ref` kho khác vẫn Post Receipt được (vi phạm §8.1 enforcement standard, NFR-SRS-005). Fix: `EnforceInboundWarehouseScope` gắn 12 route nhập kho → 403 `DATA_SCOPE_DENIED`.
> • `SEC289-02` hệ thống **không có bất kỳ rate limit nào** — `/auth/login` dò mật khẩu không giới hạn; nhóm PV ẩn danh được Annex C khai `429` nhưng 429 không thể xảy ra. Fix: `config/rate_limit.php` + limiter PV/refresh + khoá login theo lần đăng nhập SAI (audit `LOGIN_THROTTLED`), 429 đúng envelope §8.2.
> • `SEC289-03` mã lỗi 409 idempotency là `IDEMPOTENCY_ERROR` — **ngoài danh sách §8.2**, trong khi AC-GWT-IDEMP-001 và TC-P1-006/014/030/038 gọi đích danh `IDEMPOTENCY_CONFLICT` và FE đã map sẵn mã này. Fix: `IDEMPOTENCY_CONFLICT` (khác payload) / `CONCURRENCY_CONFLICT` (tranh chấp lease).
>
> **P1-03 — stock invariant (AC #3 của WWHN-77):** 5 chiều chạy qua HTTP thật, số liệu thô do test sinh tại `build/evidence/wwhn-289/stock-invariant-proof.json`: retry 3× cùng key → **1 movement**; cùng key khác payload → **409 IDEMPOTENCY_CONFLICT**, movement/balance không đổi; tiêm lỗi ngay sau `INSERT stock_movements` → **500 không lộ trace**, rollback movement+balance, document giữ `SCANNING`, **retry sau sự cố vẫn Post được**; reversal trùng → 422, tồn không âm; đối soát ledger↔balance **`mismatch_count = 0`** (kiểm chéo thêm bằng SQL thô độc lập).
>
> **P1-04 — security trace:** `WWHN-289_SECURITY_TRACE.md`, 4 nhóm regression: ẩn danh → 401; thiếu quyền → 403; `scope_ref` ngoài phạm vi → 403 `DATA_SCOPE_DENIED` (scope đúng vẫn 200); replay refresh token đã xoay vòng → 401; không lộ mật khẩu/`password_hash`/trace ở response lẫn audit.
>
> **Test:** BE sqlite toàn suite **738 test / 712 pass / 0 fail / 0 error / 26 skip** (trước fix: 681/657 + 1 fail + 1 error). FE **522/522 pass**, typecheck sạch. CI exact-SHA `.github/workflows/wwhn-289-closure.yml` chạy lại đúng bộ này trên PostgreSQL 16.
>
> **Known gaps (không giấu):** (1) `DEV289-P1-01` vẫn **FAIL-CLOSED** — Jira chưa có defect UAT cụ thể; ba defect trên là DEV tự phát hiện khi đối chiếu docs, **không** được coi là kết quả triage của QA1/BA. (2) Ngưỡng rate limit là giá trị mặc định do DEV chọn, cần BA/DevOps chốt cho vận hành (đổi bằng env). (3) `GET /pv/products` chưa tồn tại ở runtime — gap coverage Annex C, ngoài phạm vi stock/bảo mật/core, không đóng ngầm ở đây.
>
> Comment này **không** tự nhận chữ ký QA1 và **không** đóng parent WWHN-77.

---

## 2. Tech Lead — xác nhận kỹ thuật · đăng trên WWHN-289

> **Tech Lead — Xác nhận (đọc + duyệt, không phát sinh việc mới)**
>
> Tôi xác nhận đúng 4 điểm sau, dựa trên `WWHN-289_PINS.json` và register:
> 1. **Nguồn canonical:** BE `m1` @ `8bdf9ad0a00461afe27e3e11a26e7fd13d68f9e6` là build duy nhất của WWHN-289; FE `m1` @ `404267083d2c0ca79507f7fc1fdf05170cdae48e` là bản tiêu thụ tương ứng. Không có nhánh nào khác được dùng làm evidence.
> 2. **Contract:** thay đổi mã lỗi 409 `IDEMPOTENCY_ERROR` → `IDEMPOTENCY_CONFLICT` / `CONCURRENCY_CONFLICT` là **đưa về đúng** SRS §8.2 và AC-GWT-IDEMP-001, không phải mở rộng contract; FE đã dùng sẵn `IDEMPOTENCY_CONFLICT` nên không có breaking change với client hợp lệ.
> 3. **Security design:** data scope luồng nhập (`EnforceInboundWarehouseScope`) đúng mô hình đang dùng cho luồng xuất/đọc tồn, fail-closed và không chặn nhầm SUPER_ADMIN / role không gắn `scope_ref`; khoá login đếm theo lần **thất bại** nên không phạt oan người dùng hợp lệ.
> 4. **Gate CI:** `.github/workflows/wwhn-289-closure.yml` chạy lại bộ chứng minh trên PostgreSQL 16 ở exact SHA và fail-closed nếu marker evidence bị gỡ.
>
> Tôi **không** xác nhận thay QA1 (retest) và BA (ngưỡng rate limit vận hành).

---

## 3. QA1 — DT Soi · đăng trên WWHN-291

> **QA1 — Xác nhận (đọc + duyệt)**
>
> Tôi xác nhận đã nhận đủ bàn giao của DEV cho WWHN-289 theo `WWHN-289_QA1_HANDOFF.md`: build đã pin (BE `8bdf9ad0a00461afe27e3e11a26e7fd13d68f9e6` / FE `404267083d2c0ca79507f7fc1fdf05170cdae48e`), lệnh chạy, môi trường DB tham chiếu, bảng kết quả kỳ vọng cho 12 kịch bản, và hai artifact số liệu thô `build/evidence/wwhn-289/*.json`.
>
> Tôi xác nhận phạm vi retest của QA1 là: 5 chiều stock invariant (retry / idempotency conflict / rollback / duplicate reversal / reconcile `mismatch = 0`) và 4 nhóm security regression (unauthorized / data-scope / replay / secret), chạy **trên đúng SHA đã pin**, không retest trên HEAD không pin.
>
> Tôi ghi nhận: `DEV289-P1-01` vẫn mở vì Jira chưa có defect UAT cụ thể; ba defect trong register là do DEV phát hiện, không được tính là kết quả triage của QA1. Kết luận của lần retest sẽ được tôi báo cáo riêng trên WWHN-291 kèm build SHA và evidence thô.

---

## 4. BA — nguyen hung · đăng trên WWHN-288

> **BA — Xác nhận (đọc + duyệt)**
>
> Tôi xác nhận đã đọc `WWHN-289_DEFECT_FIX_REGISTER.md` và xác nhận các business disposition sau:
> 1. Ba thay đổi của WWHN-289 (data scope luồng nhập, rate limit chống lạm dụng, chuẩn hoá mã lỗi 409) là **đưa hệ thống về đúng baseline SRS v1.12** (§8.1, §8.2, §9), **không** mở rộng phạm vi nghiệp vụ và không đổi luồng người dùng.
> 2. `DEV289-P1-06` — không có hạng mục nào của WWHN-289 cần waiver: cả ba đều là sai lệch so với điều khoản đã duyệt, nên xử lý là sửa, không phải miễn trừ.
> 3. Điểm còn cần tôi chốt cùng DevOps: **ngưỡng rate limit vận hành** (mặc định hiện tại: PV công khai 120 request/phút/IP; đăng nhập 10 lần sai/phút theo IP + định danh; refresh 30/phút/IP). Tôi xác nhận các ngưỡng mặc định này chấp nhận được cho UAT; giá trị cho production sẽ chốt theo môi trường thật, đổi bằng biến môi trường, không cần sửa code.
> 4. `DEV289-P1-01` — tôi xác nhận Jira hiện **chưa** có defect UAT cụ thể gắn cho WWHN-289; DEV không được suy diễn defect ID. Việc nạp defect (nếu có) thuộc WWHN-286/WWHN-76.
