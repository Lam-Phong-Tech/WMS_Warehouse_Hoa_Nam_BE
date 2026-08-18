# WWHN-300 — Comment bàn giao và xác nhận

Mỗi vai trò **một comment duy nhất**, gộp đủ các phần cần xác nhận. Các vai trò ngoài BE1
chỉ cần đọc + duyệt, không phát sinh việc mới.

---

## 1. BE1 — Quang Nguyễn Thanh (comment bàn giao, đăng trước)

> **[BE1] Backend GoLive check — hoàn tất, kèm 1 P0 đã đóng**
>
> Repo `WMS_Warehouse_Hoa_Nam_BE`, branch `m1`, driver kiểm chứng PostgreSQL 16 + SQLite.
>
> **Output "Backend GoLive check"**: lệnh `php artisan wms:golive-check` (9 dimension:
> migration/schema/seed/security/route default-deny/idempotency/audit/reconciliation),
> exit code 1 khi FAIL, `--json` xuất evidence machine-readable.
>
> **P0 đã phát hiện và đóng — BE1-GL-P0-01:** `migrate:rollback` dừng ở migration 94/139
> (`SQLSTATE[42809]`), và sau đó `migrate` forward **cũng fail** → database không phục vụ được,
> không migrate tiếp được, chỉ restore backup mới cứu. Nghĩa là AC "rollback theo authority" của
> WWHN-80 trước đây **không thực thi được**. Root cause: các bước fail-forward
> (`2026_08_10_000750`, `2026_08_10_000791`) đổi chiều bảng/view nhưng `down()` là no-op, nên mọi
> `down()` cũ hơn vỡ khi gặp hình dạng schema mới.
> Sau fix: rollback **140/140 exit 0**, chỉ còn bảng `migrations`; `migrate` + `db:seed` lại sạch.
>
> P1/P2 và disposition đầy đủ: `docs/evidence/WWHN-300/P0_P1_P2_ACTION_REGISTER.md`.
> Ranh giới rollback (schema vs dữ liệu): `docs/evidence/WWHN-300/CUTOVER_ROLLBACK_RUNBOOK.md`.
> Evidence + CI exact-SHA: `.github/workflows/wwhn-300-backend-golive-check.yml`,
> `scripts/collect-wwhn300-golive-evidence.sh`.
>
> **Không có thay đổi API contract, payload, error code hay permission** — FE1/QA không phải sửa gì.
>
> Còn phụ thuộc: WWHN-298 (DevOps) chưa cấp environment/deploy log nên check chưa chạy được trên
> UAT/production; WWHN-299 (BE2) sở hữu ngưỡng reconciliation của lần nạp dữ liệu cuối.

---

## 2. BA/BAS — nguyen hung (xác nhận)

> **[BA] Xác nhận WWHN-300**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. Output "Backend GoLive check" đúng AC của WWHN-300 và không mở rộng scope ngoài WWHN-80.
> 2. Disposition của P0 BE1-GL-P0-01 (đã đóng) và P1/P2 trong
>    `docs/evidence/WWHN-300/P0_P1_P2_ACTION_REGISTER.md`.
> 3. Ranh giới rollback: schema unwind bằng `migrate:rollback`, dữ liệu chỉ khôi phục bằng
>    restore backup — thống nhất với SRS v1.12 §13.12 và BR-REV (không sửa tồn trực tiếp).
> 4. `core.reconciliation_backlog` chặn readiness khi còn incident OPEN — đúng SRS §7.5.
>
> Không có thay đổi baseline/contract nào cần CR. Còn P2 BE1-GL-P2-03 (`/api/documentation`
> không middleware) giữ trạng thái OPEN với owner DevOps.

---

## 3. DevOps/DO — Minh Tân Lê (xác nhận)

> **[DO] Xác nhận WWHN-300**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. Phần backend của runbook cutover/rollback (`CUTOVER_ROLLBACK_RUNBOOK.md`) — nhận đủ lệnh
>    pre-cutover, migrate, rollback, smoke `/api/v1/health`.
> 2. `php artisan wms:golive-check` dùng được làm gate Go/No-Go (exit code 1 khi FAIL, `--json`
>    làm evidence đính kèm).
> 3. Ranh giới thẩm quyền: `migrate:rollback` chỉ cho schema rollback; sau khi có dữ liệu nghiệp vụ
>    thì rollback = restore backup thuộc thẩm quyền DevOps.
> 4. Nhận owner cho P2 BE1-GL-P2-03 (chặn `/api/documentation` ở môi trường ngoài dev).
>
> Blocker phía tôi: WWHN-298 chưa xong environment URL/deploy log/backup-restore evidence, nên
> chưa chạy được check trên UAT/production.

---

## 4. BE2 — Duy Khánh Nguyễn (xác nhận)

> **[BE2] Xác nhận WWHN-300**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. Thay đổi chỉ nằm ở `down()` của migration + helper `HoaNamErdSchema` + lệnh check mới —
>    **không đụng** ranh giới giao dịch, schema forward, truy vấn báo cáo hay audit.
> 2. `migrate` forward vẫn cho đúng 140 migration và schema như trước (round-trip evidence).
> 3. Không chồng lấn WWHN-299: `wms:golive-check` cố ý không kiểm tra số lượng bản ghi nghiệp vụ,
>    ngưỡng reconciliation của lần nạp dữ liệu cuối vẫn thuộc ticket của tôi.

---

## 5. FE1 (xác nhận)

> **[FE1] Xác nhận WWHN-300**
>
> Tôi xác nhận đã đọc và duyệt: WWHN-300 **không thay đổi** API contract, endpoint, payload,
> validation/error code hay permission — FE không phải sửa gì. Route surface `/api/v1` được gác
> bằng dimension `security.route_default_deny`, danh sách endpoint public giữ nguyên
> (health, login/refresh, PV public GET).

---

## 6. QA (QA1/QA2) — xác nhận

> **[QA] Xác nhận WWHN-300**
>
> Tôi xác nhận đã đọc và duyệt:
> 1. Nhận đủ evidence: round-trip migration (fresh → rollback → forward → seed), `golive-check.json`,
>    log thô và `SHA256SUMS`.
> 2. Test regression: `Wwhn300BackendGoLiveCheckTest` + `HoaNamErdSchemaDropRelationTest` (8/8 PASS),
>    gác bằng CI exact-SHA `wwhn-300-backend-golive-check.yml`.
> 3. `php artisan wms:golive-check` chạy được trên DEV/UAT trước và sau deploy, output JSON dùng
>    trực tiếp làm evidence smoke/regression.
> 4. Không còn P0/P1 thuộc vai trò BE1 chưa có disposition.
