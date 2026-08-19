# DISP-REL-273 — Tiêu chí phát hành cho câu chữ "kiểm kê" trong AC của WWHN-72

## Document control

| Field | Value |
|---|---|
| Disposition ID | `DISP-REL-273` |
| Jira task | `WWHN-273` (child of `WWHN-72`; anh em: `WWHN-272` DevOps, `WWHN-274` FE, `WWHN-275` QA) |
| Register closed | `DEV273-P1-05` (SOURCE CONFLICT) |
| Version | `1.0.0` |
| Status | **PROPOSED — chờ approver-authored disposition trên Jira** |
| Proposed by | BE1 — WWHN-273 |
| Proposed date | `2026-08-19` |
| Required approvers | BA Main + PM/PO + Process Owner (đổi câu chữ AC của `WWHN-72`); Tech Lead + QA1 (đối chiếu cách kiểm) |
| Effective date | `NULL — chỉ có sau formal sign-off trên WWHN-72` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Canonical branch | `m1` |
| Machine gate | `.github/workflows/wwhn-273-backend-release-signoff.yml` → bước `Served runtime smoke (... no-Stocktake absence)` |
| Test lock | `tests/Feature/Wwhn273ReleaseSignoffContractTest.php` |
| Kế thừa | `DISP-STK-230` (scope), `DISP-STK-233` (count/recount), `DISP-STK-236` (apply-adjustment), `DISP-STK-237` (variance report) |
| Không thay | `WWHN-25 / DEC-STK-001` — vẫn `PROPOSED`, `Effective date = NULL` |

> Tài liệu này **không** tự tuyên bố APPROVED và **không** duyệt/huỷ `DEC-STK-001`. Nó chỉ trả lời
> đúng một câu hỏi phát hành: khi AC của task cha nhắc "kiểm kê", release gate phải kiểm cái gì?

---

## 1. Vấn đề gốc rễ

`WWHN-72` (`[BETA] Deploy, backup database và diễn tập rollback`) có AC yêu cầu
**"Smoke auth/master/inbound/outbound/inventory/kiểm kê pass"**.

MVP đang phát hành **không có Stocktake**. Hệ quả trực tiếp: AC đó **không thể pass theo nghĩa
"gọi API kiểm kê và nhận 200"**, và ba vai trò đọc nó theo ba cách khác nhau:

| Vai trò | Cách đọc trước disposition này | Kết quả |
|---|---|---|
| BE1 (`WWHN-273`) | Bỏ "kiểm kê" khỏi smoke, ghi chú "ngoài MVP" trong task doc | Gate không chứng minh gì về kiểm kê → reviewer coi là AC bị bỏ |
| QA1 (`WWHN-275`) | Chờ endpoint kiểm kê để smoke | Blocked vô thời hạn |
| Reviewer | Parent còn yêu cầu Stocktake smoke, Decision candidate lại NO-GO | Chấm `DEV273-P1-05 SOURCE CONFLICT` |

Đây **không** phải "chưa làm". Đây là **source conflict**: câu chữ AC của task cha mô tả một sản
phẩm khác với sản phẩm mà baseline đang có hiệu lực định nghĩa.

---

## 2. Xếp hạng authority (controlling → historical)

| Hạng | Nguồn | Nói gì | Trạng thái |
|---|---|---|---|
| **1 — CONTROLLING** | `REQ-SRS-037` (Must) — `docs/HN_SRS_FRD_v1.12_FULL.md` | "Không expose Stocktake/Variance, menu/API/permission/entity/report hoặc action sửa tồn trực tiếp" | Baseline đang có hiệu lực |
| **1 — CONTROLLING** | `AC-SRS-037` — cùng file | "**deployment v1.12 không chứa capability legacy**; reconciliation chỉ tạo incident, sửa sai dùng reversal theo WWHN-26" | Baseline đang có hiệu lực |
| **1 — CONTROLLING** | `TC-SRS-037` / `BR-NOSTK-01..03` | Test case tương ứng của chính AC-SRS-037 | Baseline đang có hiệu lực |
| 2 — hỗ trợ | `REQ-SRS-031` / `AC-SRS-031` | "không có stocktake report" | Baseline đang có hiệu lực |
| 2 — hỗ trợ | SRS §5.3 Camera Scan and No-Stocktake Control | "Không có Stocktake Session/Snapshot/Count/Variance/direct stock edit. Inventory authority dựa trên movement ledger… sai giao dịch dùng Reversal" | Baseline đang có hiệu lực |
| 3 — historical | `WWHN-72` AC3, câu "kiểm kê" | Yêu cầu smoke kiểm kê | **Câu chữ tồn đọng từ trước revision 04/08/2026** |
| 3 — historical | `WWHN-25` / `DEC-STK-001` (mô hình kiểm kê A) | Mô tả đầy đủ `stocktake_*`, `apply-adjustment`, `stocktake.adjust` | `PROPOSED`, `Effective date = NULL` — **chưa bao giờ được duyệt** |
| 3 — historical | `DEC-NO-STOCKTAKE-001` | No Stocktake trong MVP | `SUPERSEDED 04/08/2026`; nội dung đã hấp thu vào `REQ-SRS-037` |

**Neo dùng để phát hành là `REQ-SRS-037` + `AC-SRS-037`**, không phải `DEC-NO-STOCKTAKE-001` (đã
SUPERSEDED) và không phải `DEC-STK-001` (chưa duyệt). Đây cùng một neo mà `DISP-STK-230/233/236/237`
đã dùng — disposition này không mở thêm authority mới.

---

## 3. Disposition

**"Kiểm kê" trong AC của `WWHN-72` được thực thi ở release gate dưới dạng kiểm chứng ABSENCE theo
`AC-SRS-037`, không phải dưới dạng gọi API Stocktake.**

Căn cứ trực tiếp: `AC-SRS-037` viết tiêu chí phát hành bằng chính chữ *deployment* —
"**deployment v1.12 không chứa capability legacy**". Nói cách khác baseline **có** một tiêu chí
kiểm kê áp cho bản phát hành; tiêu chí đó là *không tồn tại bề mặt kiểm kê*. Vì vậy AC3 không bị bỏ
— nó được kiểm theo đúng nghĩa mà baseline định nghĩa.

Ba điều **không** đi kèm disposition này:

1. **Không** duyệt và **không** huỷ `DEC-STK-001`. Nó vẫn là bản nháp hợp lệ cho một release sau.
2. **Không** cho phép BE/FE tự sinh màn hình/API/permission kiểm kê để "làm cho AC pass".
3. **Không** thay `DISP-STK-230/233/236/237`. Disposition này chỉ thêm tầng *runtime đã deploy*.

---

## 4. Hình thức chạy được của tiêu chí

Trước disposition này, absence chỉ được chứng minh **trong source và in-process**. Bản phát hành thì
được kiểm qua HTTP, nên một route bị bật lại ở tầng cấu hình vẫn có thể lọt.

| Tầng | Nơi kiểm | Khẳng định |
|---|---|---|
| Source / route / permission / bảng | `tests/Feature/Wwhn230StocktakeScopeDispositionTest.php` | Không route, không `stocktake.*` permission, không bảng `stocktake_*`, không cột `stocktake_variance_id` |
| Hành động sửa tồn | `tests/Feature/Wwhn236DeferredAdjustmentGateTest.php` + `scripts/verify-wwhn236-adjustment-disposition.mjs` | `apply-adjustment` = zero surface |
| Truy vấn/báo cáo variance | `tests/Feature/Wwhn237VarianceReportDeferredGateTest.php` | Không report variance kiểm kê |
| **Runtime đã deploy (mới)** | `.github/workflows/wwhn-273-backend-release-signoff.yml` → `stocktake-absence.txt` | `GET /api/v1/stocktake-sessions`, `/api/v1/inventory-snapshots`, `/api/v1/stock-adjustments`, `/api/v1/reports/stocktake-variance` và `POST …/apply-adjustment` đều **404** trên runtime đã `serve`, **probe kèm token hợp lệ** |

Vì sao probe **có token**: 404 khi không có token có thể chỉ là hệ quả của default-deny. Probe kèm
token chứng minh đúng điều `AC-SRS-037` nói — *capability không tồn tại* — chứ không phải *đang bị
chặn quyền*. Kỳ vọng đặt đúng 404 (không phải "khác 200") nên một catch-all/SPA fallback trả 200 hay
một route trả 403 cũng làm gate đỏ.

### 4.1. Bằng chứng đo được — và vì sao "không token" là cách kiểm SAI

Đo tại chỗ 2026-08-19 (sqlite `:memory:`, DB đã seed, identity `admin@gmail.com` — chính identity mà
release smoke dùng):

| Probe | Không token | **Có token** |
|---|---|---|
| `GET /api/v1/stocktake-sessions` | 404 | **404** |
| `GET /api/v1/inventory-snapshots` | 404 | **404** |
| `GET /api/v1/stock-adjustments` | 404 | **404** |
| `GET /api/v1/reports/stocktake-variance` | **401** | **404** |
| `POST /api/v1/stocktake-sessions/{uuid}/apply-adjustment` | 404 | **404** |
| `GET /api/v1/reports/hub` (control — chứng minh token thật) | 401 | **200** |

Dòng đáng chú ý là `reports/stocktake-variance`. Nó **không** 404 khi thiếu token, vì tồn tại route
wildcard `GET api/v1/reports/{report_code}` (`OperationalReportController@byCode`) đứng sau
`auth:api` + `permission:report.view`. Chuỗi phản hồi vì thế là: thiếu token → 401 (middleware),
có token → vào controller → `assertKnownReportCode()` abort `404 RESOURCE_NOT_FOUND` vì
`stocktake-variance` không nằm trong danh mục report.

Hệ quả cho cách kiểm:

- Probe **không token** sẽ đọc 401 và, nếu chỉ kiểm "khác 200", sẽ **PASS nhầm** — nó không phân biệt
  được "báo cáo kiểm kê không tồn tại" với "báo cáo kiểm kê tồn tại nhưng đang bị chặn quyền".
- Chỉ probe **có token** mới chứng minh được điều `AC-SRS-037` yêu cầu: bản phát hành **không chứa**
  capability, chứ không phải đang giấu nó sau một permission.
- Đây cũng là lý do kỳ vọng phải là **đúng 404**: nếu một ngày `stocktake-variance` được thêm vào danh
  mục report, `byCode` sẽ trả 200 và gate đỏ ngay.

---

## 5. Việc của QA1 (`WWHN-275`) — thay cho "chờ endpoint kiểm kê"

QA1 **không** cần gọi API Stocktake và **không** bị blocked bởi disposition này. Trên đúng candidate
đã deploy, QA1 xác minh **absence/disabled scope**:

- [ ] `GET /api/v1/stocktake-sessions` → `404`, kèm Bearer token hợp lệ.
- [ ] `GET /api/v1/inventory-snapshots` → `404`, kèm token.
- [ ] `GET /api/v1/stock-adjustments` → `404`, kèm token.
- [ ] `GET /api/v1/reports/stocktake-variance` → `404`, kèm token.
- [ ] `POST /api/v1/stocktake-sessions/{uuid}/apply-adjustment` → `404`, kèm token.
- [ ] UI: không có menu/route/nút nào mang nghĩa kiểm kê / điều chỉnh tồn (đối chiếu `WWHN-274`).
- [ ] Catalog permission trả về cho user không chứa `stocktake.*`.
- [ ] Đường sửa sai được xác minh là **Reversal**, không phải điều chỉnh tồn trực tiếp.

Kết quả ghi vào QA report của `WWHN-275` như *AC-SRS-037 absence verification PASS*, kèm build/SHA.

---

## 6. Việc cần approver làm (không tự đóng từ repo)

| Ai | Làm gì | Vì sao không thể tự đóng từ repo |
|---|---|---|
| BA Main + PM/PO + Process Owner | Sửa câu chữ AC3 của `WWHN-72`: "kiểm kê pass" → "AC-SRS-037 absence verification pass (không có bề mặt kiểm kê)" | Câu chữ AC thuộc Jira; BE không sửa AC của task cha |
| BA Main + PM/PO | Formal approve **hoặc** reject `DEC-STK-001` (`WWHN-25`) | Decision Record cần approver có thẩm quyền; repo chỉ giữ trạng thái `PROPOSED` |
| Tech Lead + QA1 | Duyệt cách kiểm ở §4 và checklist §5 là đủ cho release | Đây là thoả thuận về bằng chứng, không phải code |

---

## 7. Chống lặp lại

| Cách lỗi quay lại | Chặn bằng |
|---|---|
| Vòng rà soát sau lại trích `WWHN-72` AC3 và kết luận "thiếu smoke kiểm kê" | §2 xếp hạng authority tường minh; §4 chỉ ra chỗ AC3 đã được kiểm |
| Ai đó "làm cho AC pass" bằng cách thêm route/permission kiểm kê | Gate runtime §4 đỏ ngay khi bất kỳ path nào khác 404; cộng 3 test lock đã có |
| Ai đó gỡ bước absence khỏi workflow | `Wwhn273ReleaseSignoffContractTest` assert workflow còn bước đó |
| Ai đó trình bày `DEC-STK-001` như đã duyệt | `Wwhn230StocktakeScopeDispositionTest` + verifier WWHN-236 assert nó vẫn `PROPOSED` |
| Ai đó coi disposition này là APPROVED | Document control ghi `Status: PROPOSED`, `Effective date: NULL`; §6 liệt kê approver còn thiếu |

---

## 8. Nguồn

- `docs/HN_SRS_FRD_v1.12_FULL.md` — `REQ-SRS-037`, `AC-SRS-037`, `TC-SRS-037`, `REQ-SRS-031`, §5.3.
- `docs/decision-records/DEC-STK-001.md` — `PROPOSED`, `Effective date = NULL`.
- `docs/decision-records/DISP-STK-230-scope-disposition.md`, `DISP-STK-233-COUNT-RECOUNT-DEFERRED.md`,
  `DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md`, `DISP-STK-237-VARIANCE-REPORT-DEFERRED.md`.
- `docs/docs_task_jira/WWHN-273.md` — task doc và register của gate phát hành.
