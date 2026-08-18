# WWHN-236 — NO-CODE / DEFERRED handoff

Đóng `DEV236-P1-04`. Owner: DEV → Tech Lead / QA1.

| Field | Value |
|---|---|
| Task | `WWHN-236` — Apply Adjustment (child of `WWHN-60`, QA gate `WWHN-238`) |
| Handoff type | **NO-CODE / DEFERRED** |
| Disposition | `DISP-STK-236` — `docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md` |
| Controlling source | `REQ-SRS-037` (Must) — `HN_SRS_FRD_v1.12_FULL.md`, adopt tại `WWHN-334` |
| Canonical branch | `m1` (BE + FE) |
| Trạng thái task | **KHÔNG** phải implementation-complete. Không có capability nào được bàn giao. |

---

## 1. Vì sao NO-CODE

`REQ-SRS-037` (priority `Must`) phát biểu: *"Không expose Stocktake/Variance, menu/API/permission/
entity/report hoặc action sửa tồn trực tiếp."* `AC-SRS-037` bổ sung: *"deployment v1.12 không chứa
capability legacy; reconciliation chỉ tạo incident, sửa sai dùng reversal theo WWHN-26."*

Implement Apply Adjustment sẽ **vi phạm trực tiếp** controlling source. Vì vậy hành động đúng của
DEV không phải là viết code rồi xin miễn trừ, mà là chốt disposition và dựng hàng rào.

Chi tiết xếp hạng authority và ba nguồn mâu thuẫn (DEC-STK-001 PROPOSED, WWHN-180 OpenAPI v1.7.0,
BRD/Handoff v3.9) nằm ở `DISP-STK-236` §1–§3.

---

## 2. Code delta của task này

### Capability: ZERO

Không route, controller, service, migration, model, permission, seeder, màn hình hay report nào
mang ngữ nghĩa stocktake / variance / adjustment được thêm.

### Hàng rào (guard): có — và đây là toàn bộ delta

| # | File | Vai trò |
|---|---|---|
| 1 | `docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md` | Disposition — chốt một authority |
| 2 | `docs/contracts/HoaNam_Current_Baseline_Contract.json` | Bổ sung `adjustment`, `variance` vào `forbidden_path_fragments` |
| 3 | `scripts/verify-wwhn236-adjustment-disposition.mjs` | Verifier fail-closed, 11 check |
| 4 | `tests/Feature/Wwhn236DeferredAdjustmentGateTest.php` | 7 test khoá route / permission / schema / constraint / contract |
| 5 | `scripts/build-wwhn236-source-register.mjs` | Sinh source register đo lại được (DEV236-P1-02) |
| 6 | FE `scripts/verify-wwhn-236-closure.mjs` | Gate FE, 7 check |
| 7 | FE `src/test/wwhn-236-adjustment-deferral-gate.test.ts` | 4 test khoá route / permission / endpoint FE |
| 8 | `docs/evidence/WWHN-236/**` | Evidence pack (tài liệu này, source register, contract lock, QA disposition, log) |

**Lỗ hổng thật đã bịt (mục 2):** trước task này `forbidden_path_fragments` chỉ chứa `"stocktake"`.
Một route sửa tồn đặt tên khác — ví dụ `POST /api/v1/inventory/apply-adjustment` — **lọt qua toàn bộ
gate hiện có**, kể cả `DocumentationBaselineContractTest`. `REQ-SRS-037` cấm mọi action sửa tồn trực
tiếp chứ không riêng action mang chữ `stocktake`, nên gate phải phản ánh đúng phạm vi đó.

---

## 3. Bằng chứng phủ định — bề mặt bằng zero ở mọi tầng đang sống

Chạy lại bất cứ lúc nào:

```bash
node scripts/verify-wwhn236-adjustment-disposition.mjs --emit-lock
```

| Tầng | Kết quả | Nguồn kiểm chứng |
|---|---|---|
| Route (source, 11 file) | zero URI chứa `stocktake` / `adjustment` / `variance` | verifier `ROUTE-SOURCE` |
| Route (booted router) | zero | test `test_no_adjustment_stocktake_or_variance_route_is_registered` |
| Runtime OpenAPI | zero path (`api-docs.yaml` + `api-docs.json`) | verifier `OPENAPI-RUNTIME` |
| Endpoint-permission matrix | zero | verifier `PERMISSION-MATRIX` |
| Permission catalog (DB) | zero với `%stocktake%`, `%adjustment%`, `%variance%` | test `test_permission_catalog_has_no_stocktake_or_adjustment_permission` |
| Schema | 4 bảng `stocktake_*` vắng; `stock_movements.stocktake_variance_id` vắng | test `test_excluded_stocktake_tables_are_absent_from_the_final_schema` |
| Migration | mọi `Schema::create` bảng `stocktake_*` đều có drop ở migration sau ⇒ trạng thái cuối zero | verifier `MIGRATION-TABLES` |
| DB constraint (PostgreSQL) | `chk_stock_movement_type_current` chỉ cho `OPENING/RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL`. Paired probe trên PostgreSQL: `REVERSAL` qua được CHECK, `ADJUST_IN` bị chính CHECK từ chối trước cả FK | `logs/pg-direct-constraint-probe.log` (truy vấn trực tiếp) + test `test_movement_type_check_constraint_rejects_direct_stock_adjustment` |
| Baseline contract | fragments + excluded tables + movement types khớp canonical | verifier `BASELINE-*` |
| Spec contract (18 file) | zero, ngoài artifact historical đã có disposition | verifier `SPEC-SURFACE` |

> **Giới hạn đã biết:** `php vendor/bin/phpunit --filter=Wwhn236DeferredAdjustmentGateTest` **không**
> chạy được trên pgsql, vì migration chain hiện tại fail khi migrate fresh trên PostgreSQL
> (`hn_erd_mirror_pv_industries`: `created_by` uuid vs text). Lỗi đó nằm ở bước migrate, trước khi
> test của WWHN-236 bắt đầu, và ngoài phạm vi task này. P1-06 vì vậy được chứng minh bằng truy vấn
> trực tiếp trên database đã migrate (`logs/pg-direct-constraint-probe.log`); trên sqlite cả 7 test
> đều pass.

### Gate đã được chứng minh là fail-closed

Không chỉ chạy happy path — hai negative probe đã được thực thi và xác nhận exit code 1:

| Probe | Vi phạm cố tình tạo ra | Exit code |
|---|---|---|
| A | Thêm route `POST api/v1/inventory/apply-adjustment` | `1` (chặn) |
| B | Thêm `ADJUST_IN` vào `movement_types` của baseline contract | `1` (chặn) |
| — | Khôi phục nguyên trạng | `0` |

Log: `docs/evidence/WWHN-236/logs/`.

---

## 4. Đường sửa sai hợp lệ (thay cho Apply Adjustment)

`WWHN-236` không để lại khoảng trống nghiệp vụ "không sửa được số tồn". Nó chuyển hành động đó sang
bề mặt canonical đã tồn tại và đã có lock/CAS/idempotency riêng:

| Business action | Endpoint | Permission |
|---|---|---|
| Điều chỉnh receipt đã post | `POST /api/v1/inbound-documents/{id}/reverse` | `inventory.reversal.post` |
| Điều chỉnh issue đã post | `POST /api/v1/outbound-documents/{id}/reverse` | `inventory.reversal.post` |
| Chứng từ điều chỉnh độc lập | `POST /api/v1/reversal-documents` | `inventory.reversal.post` |
| Phát hiện lệch tồn | `GET /api/v1/reports/inventory-reconciliation` → `reconciliation_incidents` | `report.view` |

Test `test_canonical_reversal_correction_path_stays_available` khoá chiều ngược lại: ba route reverse
**không được biến mất** trong lúc dọn dẹp bề mặt adjustment.

**Giới hạn cần Tech Lead / PM biết:** sửa sai bắt buộc truy về đúng chứng từ gốc. Nếu lệch tồn phát
sinh từ nguyên nhân vật lý không gắn với một chứng từ nào (mất hàng, hỏng, đếm sai), MVP v1.12 chỉ
ghi nhận được bằng `reconciliation_incidents` chứ **không** có đường đưa số tồn về khớp thực tế. Đây
là hệ quả có chủ đích của `REQ-SRS-037`, cần PM/PO xác nhận là chấp nhận được về vận hành.

---

## 5. Điều kiện re-enable

Không có đường tắt. Cả bốn điều kiện phải đủ (`DISP-STK-236` §2):

1. Approved **Change Request** nâng phạm vi MVP.
2. `WWHN-25` ghi `GO` / `CONDITIONAL GO` kèm effective date trên Jira.
3. SRS rebaseline: `REQ-SRS-037` được sửa/gỡ ở một version SRS mới có số hiệu và ngày.
4. Chỉ khi đó mới mở DEV; `DEV236-P1-05/06/07` trở thành gate của lần re-enable, phải đóng bằng
   evidence dương (contract lock, race/retry/rollback/reconcile trên PostgreSQL, QA1 exact-build).

Khi đủ, cả hai nhánh của `REQ-SRS-037` được rebaseline **cùng lúc**, không mở lẻ: nhánh adjustment
(`WWHN-60/236/238` + `HN-STK-002`, tài liệu này) và nhánh count/recount (`WWHN-59/233/235`,
`DISP-STK-233`).

---

## 6. Cái mà Tech Lead / QA1 cần ký

Chỉ đọc + duyệt, không phát sinh việc mới:

- [ ] Handoff này là **NO-CODE / DEFERRED**, `WWHN-236` không được đóng như implementation-complete.
- [ ] Delta code của task chỉ gồm hàng rào ở §2, không có capability.
- [ ] Bằng chứng phủ định ở §3 chạy được lại và fail-closed đã được chứng minh.
- [ ] Giới hạn nghiệp vụ ở §4 đã được ghi nhận.

Exact SHA của candidate được pin tại `docs/evidence/WWHN-236/SOURCE_REGISTER.json`
(`candidate_identity`) và `docs/evidence/WWHN-236/SHA256SUMS`.
