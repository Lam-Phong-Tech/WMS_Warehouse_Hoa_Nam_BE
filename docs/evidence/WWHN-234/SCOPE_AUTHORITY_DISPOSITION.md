# WWHN-234 · DEV234-P1-01 — Scope authority của Stocktake

| Field | Value |
|---|---|
| Register item | `DEV234-P1-01` (P1) |
| Xung đột phải đóng | `WWHN-59` Source/AC ↔ `WWHN-25` ↔ OpenAPI `2.0.0` |
| Owner | BA Main + PM/PO |
| Reviewer | Tech Lead + QA |
| Gate | G1 / Dev Ready |
| Trạng thái tài liệu | **DECISION-READY — CHƯA PHÊ DUYỆT** |
| Người soạn | DEV (WWHN-234) |
| Ngày soạn | 2026-08-18 |

> Tài liệu này **không phải** một disposition đã được phê duyệt. DEV không có thẩm quyền
> chốt scope. Đây là hồ sơ dữ kiện đã kiểm chứng + bản disposition soạn sẵn để BA Main và
> PM/PO ghi kết quả. Mục §5 là phần approver tự điền; mọi ô còn trống nghĩa là gate G1
> **chưa** đóng.

---

## 1. Vì sao câu hỏi này vẫn còn mở

Ba nguồn cùng nói về Stocktake nhưng ở ba tầng thẩm quyền khác nhau, và trước nay chúng bị
trích dẫn lẫn lộn:

| Nguồn | Nói gì | Tầng thẩm quyền | Trạng thái |
|---|---|---|---|
| SRS/FRD v1.12 `REQ-SRS-037` (Must) | "Không expose Stocktake/Variance, menu/API/permission/entity/report hoặc action sửa tồn trực tiếp" | **Requirement baseline** | Đã adopt qua `WWHN-334` |
| SRS v1.12 §0.3.1 P0-04 | "WWHN-25/26/27 vẫn FORMAL APPROVAL PENDING; SRS v1.12 adoption không thay formal decision gates" | Governance note | — |
| `DEC-NO-STOCKTAKE-001` | "No Stocktake Session/Snapshot/Count/Variance or direct stock-edit capability in current MVP" | Decision Record | `formal Decision: WWHN-25 only` |
| `DEC-STK-001` (`docs/decision-records/DEC-STK-001.md`) | Thiết kế **đầy đủ** mô hình kiểm kê Option A: 9 endpoint, 5 bảng, RBAC `stocktake.adjust` | Decision Record draft | **PROPOSED / NO EFFECTIVE DATE** |

Nguồn gốc của tình trạng "32/100": `DEC-STK-001` mô tả một hệ thống kiểm kê hoàn chỉnh và
`WWHN-59` Source/AC vẫn dẫn nó, trong khi requirement baseline đang có hiệu lực lại cấm
đúng hệ thống đó. Vì không ai chốt bên nào thắng, mỗi vòng review lại phải hỏi lại — và
task DEV/QA con (`WWHN-234`, `WWHN-235`) không có cách nào chứng minh mình đã làm đúng.

**Hai mệnh đề này không mâu thuẫn nhau**, và đó chính là chỗ các vòng trước hiểu sai:

- `REQ-SRS-037` là **Must đã có hiệu lực** → deployment hiện tại **không được** có Stocktake.
- `WWHN-25` PENDING là gate của **Decision Record**, quyết định Stocktake có được đưa lại
  vào Beta hay không, và theo hình thức nào.

Nói cách khác: *trạng thái mặc định hôm nay là OUT OF SCOPE*; `WWHN-25` là con đường duy
nhất để đảo trạng thái đó, và nó chưa được đi.

## 2. Dữ kiện runtime đã kiểm chứng (không phải suy luận từ tài liệu)

Đo trên baseline được pin ở `REVIEW_IDENTITY_PIN.json`:

| # | Mệnh đề | Cách đo | Kết quả |
|---|---|---|---|
| R1 | Không có route Stocktake | `php artisan route:list --json` | **0/239** route khớp `stocktake` |
| R2 | Không có permission Stocktake | grep `database/seeders`, `Modules/*/database/seeders` | 0 hit |
| R3 | Không có controller/service Stocktake | `ls Modules/Warehouse/app/Http/Controllers`, `.../Services` | 0 file |
| R4 | Bảng `stocktake_*` và `safe_stock_configs` đã bị drop | `Modules/Warehouse/database/migrations/2026_08_10_000600_enforce_current_erd_business_contract.php:39-49` | 6 bảng bị drop |
| R5 | OpenAPI `2.0.0` không có operation Stocktake | quét 231 operationId trong `storage/api-docs/api-docs.json` | 0 operation |
| R6 | FE không có route/permission/màn hình Stocktake | `src/app/router/access.test.ts:71-77`, `src/shared/constants/routes.ts:31`, `src/shared/constants/wms-permissions.ts:71` | guard test fail-closed đang xanh |

**Kết luận runtime: capability Stocktake không tồn tại trong baseline hiện tại ở cả sáu
tầng (route, permission, code, schema, contract, UI).** Đây là dữ kiện, không phải đề xuất.

## 3. Hệ quả bắt buộc theo từng nhánh disposition

### Nhánh A — DEFERRED / OUT OF SCOPE (khớp trạng thái runtime hiện tại)

1. `WWHN-234` **không được** claim runtime implementation/review của Stocktake. Review
   đồng thời/trạng thái phải giới hạn ở các action đổi tồn thực sự tồn tại — xem
   `CONCURRENCY_STATE_REVIEW.md` §3.
2. `WWHN-235` phải nhận disposition formal **N/A hoặc DEFERRED** đối với phần Stocktake,
   không được để `Blocked`/`Pending` mơ hồ. Phần TC đổi tồn còn lại vẫn phải chạy — xem
   `QA1_HANDOFF_WWHN-235.md`.
3. `DEC-STK-001` giữ nguyên `PROPOSED / NO EFFECTIVE DATE` và được đánh dấu **không thuộc
   Beta scope**; không repository file nào được diễn giải nó là approved.
4. Guard chống tái xuất hiện (route/permission/entity/report Stocktake) tiếp tục là gate
   fail-closed ở cả BE và FE.

### Nhánh B — IN SCOPE trở lại cho Beta

1. Phải có **approved CR/Decision** trên `WWHN-25` (`GO` hoặc `CONDITIONAL GO`) kèm
   effective date, trước khi bất kỳ dòng code nào được viết.
2. Phải cập nhật **trước DEV**: BA baseline (`REQ-SRS-037` phải được amend/supersede),
   OpenAPI (thêm operation + `info.version` mới), và bộ TC.
3. `REQ-SRS-037` hiện là **Must**; không thể vừa giữ nguyên nó vừa đưa Stocktake vào —
   đây là ràng buộc cứng, không phải khuyến nghị.

## 4. Danh sách ticket bị ảnh hưởng

Approver phải xác nhận danh sách này đầy đủ trước khi ký.

| Ticket | Vai trò trong chuỗi | Hệ quả ở nhánh A | Hệ quả ở nhánh B |
|---|---|---|---|
| `WWHN-25` | Formal Decision Record (DEC-STK-001) | Giữ PROPOSED, đánh dấu ngoài Beta | Phải ký GO/CONDITIONAL GO trước |
| `WWHN-26` | DEC-REV-001 Reversal-only | Không đổi — Reversal là cơ chế sửa sai duy nhất | Phải rà lại ranh giới với adjustment |
| `WWHN-58` | Stocktake story | DEFERRED | Re-open |
| `WWHN-59` | Story cha của 234/235 (Source/AC) | Source/AC phải gỡ tham chiếu Stocktake hoặc ghi rõ DEFERRED | Cập nhật AC theo CR |
| `WWHN-131` | BE1 draft implementation/evidence | Đóng theo trạng thái decision, không merge runtime | Nền cho implementation mới |
| `WWHN-132` | BA final decision | Ghi disposition | Ghi CR |
| `WWHN-133` | BAS traceability | Cập nhật ma trận | Cập nhật ma trận |
| `WWHN-230` | Stocktake core | DEFERRED | Re-open |
| `WWHN-234` | DEV Concurrency & State review (task này) | Giới hạn phạm vi theo §3 nhánh A | Mở rộng review sau khi có code |
| `WWHN-235` | QA1 independent test | Formal N/A/DEFERRED cho phần Stocktake | Test theo scope mới |
| `WWHN-334` | SRS v1.12 adoption | Không đổi | Phải amend `REQ-SRS-037` |

## 5. Ô phê duyệt — approver tự điền

```text
Disposition version : ______________________ (vd 1.0.0)
Chọn một           : [ ] A · DEFERRED / OUT OF SCOPE      [ ] B · IN SCOPE cho Beta
Effective date      : ______________________ (ISO-8601, hoặc NULL nếu chưa hiệu lực)
Nếu chọn B, CR/Decision ID: ______________________
Danh sách ticket ảnh hưởng đã xác nhận đầy đủ: [ ] Có   [ ] Không — bổ sung: __________

BA Main   : tên ____________  kết quả ____________  ngày __________
PM/PO     : tên ____________  kết quả ____________  ngày __________
Tech Lead : tên ____________  kết quả ____________  ngày __________  (reviewer)
QA        : tên ____________  kết quả ____________  ngày __________  (reviewer)
```

**Cho tới khi khối trên được điền và ghi lên Jira `WWHN-59`/`WWHN-25`, `DEV234-P1-01` vẫn
OPEN và gate G1 chưa đóng.** Các hạng mục `DEV234-P1-02/03/04` được soạn theo **nhánh A**
vì đó là trạng thái runtime đang tồn tại; nếu approver chọn nhánh B thì ba hạng mục đó phải
được mở rộng phạm vi, không phải viết lại từ đầu.
