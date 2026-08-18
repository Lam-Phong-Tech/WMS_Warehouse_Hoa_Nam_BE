# WWHN-300 — Comment xác nhận vòng 2 (sau review 58/100)

Chi tiết kỹ thuật: [`DEV300_P1_P2_CLOSURE.md`](./DEV300_P1_P2_CLOSURE.md).
Vòng 1: [`SIGNOFF_COMMENTS.md`](./SIGNOFF_COMMENTS.md).

Quy tắc giữ nguyên: **một vai trò một comment**, gộp đủ mọi phần phải xác nhận.
Trừ comment bàn giao của BE1, các vai trò dưới đây **chỉ đọc + duyệt, không phát sinh việc mới**.

---

## 1. BE1 — Quang Nguyễn Thanh (comment bàn giao, đăng trước)

> **[BE1] WWHN-300 — đóng DEV300-P1-01..P1-04 + P2-01**
>
> Repo `WMS_Warehouse_Hoa_Nam_BE`, branch `m1`. Nguyên tắc xuyên suốt bản sửa: **fail-closed** —
> một probe không kết luận được (thiếu bảng, driver không đọc được index, thiếu hợp đồng đã pin)
> là **FAIL**, không phải PASS.
>
> - **P1-01** — thêm dimension `security.account_hygiene`: dò mật khẩu mặc định bằng `Hash::check`
>   trên mọi tài khoản ACTIVE, chặn tài khoản/role fixture (`test-*`, `staff01`…, `NO_PERMISSION`).
>   `AuthDatabaseSeeder` nay **từ chối chạy** ngoài dev nếu thiếu `WMS_SEED_ADMIN_PASSWORD`, và
>   **không tạo fixture nào**. Evidence chỉ ghi username vi phạm, không bao giờ ghi mật khẩu.
> - **P1-02** — `--allow-debug` chỉ còn hiệu lực trên allowlist môi trường non-prod
>   (`golive.debug_override_environments`); ngoài đó `APP_DEBUG=true` là FAIL **không override được**.
> - **P1-03** — `core.idempotency_store` so **đúng tập cột** unique (`business_key`;
>   `idem_key+operation+user_id`) trên cả pgsql/sqlite; thiếu `audit_outbox`/`reconciliation_incident`
>   là FAIL trừ khi có disposition tường minh; bề mặt `/api/v1` so với **ma trận permission đã pin**
>   231 route (`database/golive/route_permission_contract.php`).
> - **P1-04** — `migrations.applied` so **hai chiều** (thêm `orphaned_in_database`);
>   `migrations.reversible` so với **allowlist 75 mục có thẩm quyền**
>   (`database/golive/fail_forward_allowlist.php`), fail-forward mới = FAIL; collector `diff`
>   manifest migration trước/sau roundtrip.
> - **P2-01** — evidence index: `docs/evidence/WWHN-300/DEV300_P1_P2_CLOSURE.md`.
>
> Bằng chứng: `Wwhn300BackendGoLiveCheckTest` (18) + `Wwhn300ProductionSafeSeedingTest` (6) +
> `HoaNamErdSchemaDropRelationTest` — **28/28 PASS**, trong đó **13 case negative /
> fault-injection**: gỡ unique index, xóa bảng audit/reconcile, gắn sai permission cho
> `post-issue`, mất hợp đồng đã pin, `--allow-debug` trên production — mọi case đều phải exit 1.
>
> **Không thay đổi API contract, payload, error code hay permission runtime** — FE/QA không phải
> sửa gì. Ghi nhận thực địa: gate đã **bắt sống** thay đổi route PV do phiên làm việc song song
> vừa đẩy lên `m1` (5 route mới + 1 permission bị đổi); đối chiếu Annex C xong mới pin lại ma trận.
>
> Còn lại: **P1-05** phần quản trị repo (branch protection / chạy workflow trên SHA cuối — cần
> quyền admin) và **P1-06** (cutover → data → reconcile → smoke → Go/No-Go) vẫn phụ thuộc
> WWHN-298/299/301/302.

---

## 2. Tech Lead — xác nhận

> **[Tech] Xác nhận WWHN-300 — DEV300-P1-02 / P1-03 / P1-04**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. **Nguyên tắc fail-closed** của `wms:golive-check`: probe không kết luận được là FAIL; miễn trừ
>    duy nhất là disposition tường minh trong `config/golive.php → governed_na`, và disposition đó
>    đi thẳng vào evidence.
> 2. **Ma trận route→permission đã pin** (231 route): tôi duyệt nội dung ma trận đúng SRS v1.12 §8.1 —
>    `post-receipt→inbound.post`, `post-issue→outbound.post`, `reversals→inventory.reversal.post`,
>    `rfid→physical_code.assign_rfid`, `packing_label.*`, `report.export`, IAM `iam.*`,
>    PV public GET `PUBLIC_ANONYMOUS`; dạng `A|B` là alias canonical/legacy theo Annex C. Từ nay mọi
>    thay đổi bề mặt bảo mật phải xuất hiện trong diff của file này và phải có review.
> 3. **Allowlist fail-forward 75 mục**: tôi nhận thẩm quyền cho tập này; một migration fail-forward
>    **mới** sẽ làm đỏ gate cho tới khi có disposition — đúng như mong muốn.
> 4. **So migration hai chiều** (`orphaned_in_database`) và bất biến unique của `idempotency_keys`
>    khớp BR-IDEMP-01..03 / SRS §8.2.
> 5. `--allow-debug` chỉ hợp lệ trên allowlist non-prod: không còn đường ép gate xanh trên production.
>
> Không có thay đổi kiến trúc hay contract cần CR.

---

## 3. Security / DevOps — Minh Tân Lê — xác nhận

> **[SEC/DO] Xác nhận WWHN-300 — DEV300-P1-01 / P1-02 / P1-05**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. **Account hygiene gate**: tài khoản ACTIVE còn mật khẩu mặc định, tài khoản/role fixture
>    (`test-*`, `staff01`…, `SKU_MANAGER`/`MASTER_MANAGER`/`NO_PERMISSION`) đều chặn cutover ngoài
>    dev. Evidence chỉ chứa username vi phạm, **không chứa giá trị mật khẩu** — chấp nhận được để
>    đính kèm hồ sơ release.
> 2. **Seeder production-safe**: ngoài dev, `db:seed` **fail nhanh** nếu thiếu
>    `WMS_SEED_ADMIN_PASSWORD`, từ chối giá trị trùng danh sách mặc định hoặc ngắn hơn 12 ký tự, và
>    không tạo fixture. `WMS_SEED_DEMO_ACCOUNTS=true` là lối thoát tường minh cho UAT và vẫn bị gate
>    báo đỏ.
> 3. **Bằng chứng xoay vòng credential** = `accounts_on_default_credential: []` + trường
>    `rotation_evidence` trong `golive-check.json` của lần chạy trên môi trường thật. Tôi nhận trách
>    nhiệm cấp `WMS_SEED_ADMIN_PASSWORD` qua secret store và xoay vòng credential trước Go/No-Go.
> 4. `--allow-debug` không override được ngoài allowlist non-prod; collector có bước chạy thật hồ sơ
>    `APP_ENV=production APP_DEBUG=true --allow-debug` **bắt buộc exit 1**.
> 5. **P1-05 phần thuộc tôi**: bật lại branch protection + required check cho `m1`, chạy workflow
>    `wwhn-300-backend-golive-check.yml` trên đúng SHA cuối, lưu artifact + `SHA256SUMS`. Nếu release
>    governance bắt buộc PR/review URL thì cần mở lại nhánh làm việc — quy ước hiện tại là chỉ làm
>    trên `m1`.

---

## 4. QA1 — xác nhận

> **[QA1] Xác nhận WWHN-300 — bằng chứng DEV300-P1-01..P1-04**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. Nhận đủ **28/28 PASS**, trong đó **13 case negative / fault-injection** — mỗi case dựng đúng
>    trạng thái nguy hiểm rồi khẳng định lệnh **exit 1**. Đây là thứ tôi cần để tin gate, thay vì chỉ
>    có case xanh.
> 2. Thông báo lỗi của test nêu **đúng dimension đỏ kèm detail**, nên CI đỏ đọc được ngay, không phải
>    chạy lại tay để biết lý do.
> 3. Evidence artifact: `golive-check.json`, `golive-check-after-roundtrip.json`,
>    `golive-check-production-profile.json`, manifest migration trước/sau + `diff`, log thô,
>    `SHA256SUMS`.
> 4. `wms:golive-check` dùng trực tiếp làm smoke gate trước/sau deploy; exit code 1 chặn pipeline.
> 5. **Chưa đóng**: DEV300-P1-06 — smoke/UAT trên môi trường thật vẫn chờ WWHN-298/299; tôi giữ mục
>    này OPEN phía QA.

---

## 5. BA/BAS — nguyen hung — xác nhận

> **[BA] Xác nhận WWHN-300 vòng 2**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. Các bản sửa DEV300-P1-01..P1-04 **không thay đổi** baseline nghiệp vụ, state model, contract API
>    hay error code — không phát sinh CR.
> 2. Ma trận permission đã pin phản ánh đúng §8.1 và Annex C; tài khoản/role fixture không được phép
>    tồn tại trên production là đúng BR-IAM-01..08.
> 3. Disposition **fail-forward 75 migration** (rollback dữ liệu = restore backup, thẩm quyền DevOps)
>    giữ nguyên như đã duyệt ở vòng 1.
> 4. Miễn trừ hạ tầng chỉ được phép qua `governed_na` có ghi ai duyệt / ngày / lý do — tôi đồng ý cơ
>    chế này thay cho việc probe im lặng trả `ok`.
> 5. DEV300-P1-06 vẫn phụ thuộc BA freeze (WWHN-301) — tôi giữ mục này OPEN phía mình.

---

## 6. PM / Approver — xác nhận

> **[PM] Xác nhận WWHN-300 — trạng thái Go/No-Go**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. DEV300-**P1-01, P1-02, P1-03, P1-04** đã đóng bằng code + test có bằng chứng negative;
>    DEV300-**P2-01** đóng bằng evidence index `DEV300_P1_P2_CLOSURE.md`.
> 2. DEV300-**P1-05** còn phần quản trị repo thuộc DevOps (branch protection, chạy workflow trên SHA
>    cuối, artifact digest).
> 3. DEV300-**P1-06** vẫn **BLOCKED** bởi WWHN-298/299/301/302 — do đó **WWHN-300 không tự mở được
>    cổng Go/No-Go**, nó chỉ cung cấp gate readiness cho bước đó.
> 4. Không thay đổi phạm vi, không phát sinh CR, không ảnh hưởng FE.
