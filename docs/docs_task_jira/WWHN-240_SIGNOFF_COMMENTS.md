# WWHN-240 — Bộ comment xác nhận (sign-off) để dán lên Jira

Nguyên tắc: **mỗi vai trò đúng MỘT comment**, nội dung là *đọc + duyệt*, không phát sinh
việc mới. Bằng chứng chi tiết ở `docs/evidence/WWHN-240/BE_CHANGE_MANIFEST.md` và
`docs/docs_task_jira/WWHN-240.md`.

Exact SHA phải xuất hiện trong mọi comment:
**BE `9640091cf780fce1ef54102f6955971a57c5678b`** (ancestor bắt buộc
`c855851b37920237d110f7dffe5dbbad509aebce`) · **FE `79474b8c6cd873f26bc6b42ca1bd9af2171ce7cf`** · nhánh `m1`.

---

## 1. DEV — comment của bạn (người thực hiện) · dán vào **WWHN-240**

> ✅ **DEV — WWHN-240 hoàn tất: đóng DEV240-P1-02A/02B/02C, P1-03, P1-05, P2-01**
>
> Exact SHA: BE `9640091cf780fce1ef54102f6955971a57c5678b` (ancestor bắt buộc
> `c855851b37920237d110f7dffe5dbbad509aebce` — commit WWHN-254 chạy song song trên `m1` đã
> gộp nhầm phần `buildXnt` của WWHN-240), FE `79474b8c6cd873f26bc6b42ca1bd9af2171ce7cf`,
> nhánh `m1`.
>
> Ba lỗi gốc đã đóng:
> 1. **XNT gộp hai chiều reversal vào một cột** — đảo-nhập (delta âm) và đảo-xuất (delta
>    dương) triệt tiêu nhau, nên một kỳ đảo hết nhập và đảo hết xuất hiển thị y hệt kỳ không
>    có reversal. Nay tách theo `movement_type` của giao dịch **gốc** (không suy theo dấu
>    delta, vì đảo ISSUE và đảo COMPONENT_ISSUE đều dương), phát hành đủ ba tầng
>    gross / reversal / net kèm `formula_net` + `column_semantics`.
> 2. **`trace-source` chỉ trả một reversal** (`->first()`) — mọi lần đảo từng phần thứ hai
>    trở đi biến mất. Nay trả `correction_chain` đầy đủ (đi ngược `original_movement_id` về
>    gốc theo BR-REV-08 rồi duyệt xuôi) + `net_effect` tính từ ledger.
> 3. **OpenAPI trace chưa từng được publish** vì `StockMovementController.php` không nằm
>    trong `config/l5-swagger.php → annotations[]` (cùng lớp lỗi WWHN-184). Đã thêm vào scan
>    và khoá schema `XntReport` / `CorrectionChain` / `ReversalNetEffect` với
>    required/nullable/type/example, bổ sung **403** cho cả trace lẫn báo cáo.
>
> Tương thích ngược giữ nguyên: `data.formula`, `receipt`/`issue`/`component_issue`,
> `reversal_net`, `original_movement`, `reversal_movement`.
>
> Test: `Wwhn240ReversalNetEffectContractTest` **14/14, 115 assertion**; hồi quy nhóm báo
> cáo/tồn kho 136/138 (2 đỏ `SchemaApiModulesTest` về `pv_product_profile` là đỏ sẵn có trên
> `m1`, không chạm file nào của WWHN-240). FE **578/578**, `tsc --noEmit` sạch.
>
> Còn lại ngoài quyền DEV: **DEV240-P1-01** cần Decision Owner ra kết quả formal cho
> `DEC-REV-001` (đã soạn sẵn disposition + 4 đính chính contract, nội dung nghiệp vụ không
> đổi nên **không kéo theo sửa code**), và **DEV240-P1-04** thuộc QA1 tại WWHN-241 (runbook
> 20 TC đã bàn giao).

---

## 2. Decision Owner (PM/PO) — đóng **DEV240-P1-01** · dán vào **WWHN-26**

> ✅ **PM/PO — Formal outcome cho DEC-REV-001 (Reversal-only, Option B)**
>
> Tôi xác nhận đã đọc và **duyệt**:
> 1. `DEC-REV-001` — mọi sửa sai sau Post dùng Reversal append-only; movement gốc bất biến;
>    không có generic/direct stock-edit. Nội dung nghiệp vụ này giữ nguyên như đã adopt
>    trong SRS/FRD v1.12 §5.5 BR-REV-01..12.
> 2. Bốn đính chính contract tại `DEC-REV-001` §13 (route `/reversal-documents`, permission
>    `inventory.reversal.post`, cột `original_movement_id`/`correction_chain_id`, mã lỗi
>    theo catalog §8.2) — đây là đồng bộ về baseline v1.12, **không** phải thay đổi nghiệp
>    vụ, nên **không cần Change Request**.
> 3. `DISPOSITION-WWHN-239-REVERSAL-CANDIDATE.md` — WWHN-240 chỉ phụ thuộc BR-REV-01/05/08/11,
>    đều thuộc phần đã adopt; phê duyệt này không kéo theo sửa code WWHN-240.
>
> Kết quả: **APPROVED**. WWHN-239 pin accepted candidate tại BE
> `9640091cf780fce1ef54102f6955971a57c5678b` (ancestor `c855851b37920237d110f7dffe5dbbad509aebce`).

---

## 3. Tech Lead — đóng **DEV240-P1-02A/02B/02C + P1-03** · dán vào **WWHN-240**

> ✅ **Tech Lead sign-off — công thức net effect, trace chain và OpenAPI contract**
>
> Tôi xác nhận đã đọc và **duyệt** tại BE `9640091cf780fce1ef54102f6955971a57c5678b`
> (ancestor `c855851b37920237d110f7dffe5dbbad509aebce`), FE `79474b8c6cd873f26bc6b42ca1bd9af2171ce7cf`, nhánh `m1`:
>
> 1. **Công thức XNT** — phân loại reversal theo `movement_type` của giao dịch gốc, đi ngược
>    chain theo BR-REV-08; reversal không truy được gốc vào `reversal_unlinked_net` để
>    `closing` luôn khớp ledger. Bất biến `closing (NET) == closing (gross) == SUM(qty_delta)`
>    được assert ở mọi test.
> 2. **Ranh giới cột** — GROSS `*_gross`, REVERSAL `*_reversal`, NET `*_net`; tên cột cũ giữ
>    nguyên nghĩa làm bí danh; `formula` cũ không đổi chuỗi.
> 3. **`traceSource`** — trả `correction_chain` (role/depth/is_subject/actor/reason) và
>    `net_effect` `basis=LEDGER`; chain dựng bằng Eloquent nên global scope
>    `ScopesWarehouseData` vẫn chặn rò dữ liệu ngoài warehouse scope; trần độ sâu 32 báo cờ
>    `truncated` thay vì cắt ngầm.
> 4. **OpenAPI** — `StockMovementController.php` được đưa vào danh sách scan (nguyên nhân
>    gốc khiến contract trace chưa bao giờ publish); schema khoá bằng
>    required/nullable/type/example; 403 có mặt ở `trace-source`, `inventory-xnt`,
>    `inventory-xnt-enhanced`, `movement-trace`. Artifact:
>    `docs/evidence/WWHN-240/openapi-wwhn240-slice.json`
>    SHA-256 `5d258a2c19b29b8ed0606cb50fb93e0e4c6b57f85f96660417f9b255db7ebeb3`.
> 5. **DEV240-P1-03** — PR/commit, nhánh nguồn `m1`, final SHA và lưu ý attribution đã pin
>    đầy đủ trong `docs/evidence/WWHN-240/BE_CHANGE_MANIFEST.md` §1.
>
> Không có thay đổi business semantics. Không có breaking change với client hiện hành.

---

## 4. BA — xác nhận ý nghĩa nghiệp vụ · dán vào **WWHN-240**

> ✅ **BA sign-off — business meaning của gross / reversal / net**
>
> Tôi xác nhận đã đọc và **duyệt** định nghĩa cột báo cáo XNT tại
> `9640091cf780fce1ef54102f6955971a57c5678b`:
>
> 1. **GROSS** = lượng đã Post trong kỳ, **không** trừ reversal — đúng BR-REV-11 "không ẩn
>    hoặc sửa lịch sử".
> 2. **REVERSAL** = phần của cột gross tương ứng đã bị đảo, quy về cùng chiều dương; đảo-nhập
>    vào cột Nhập, đảo-xuất vào cột Xuất, đảo-xuất-linh-kiện vào cột Xuất LK — **không gộp
>    ba chiều làm một** như trước.
> 3. **NET** = gross − reversal, là con số dùng để đối soát kỳ; `closing` = tồn cuối kỳ.
> 4. Đảo rồi đảo ngược lại (BR-REV-08) làm lượng đã đảo **giảm** về 0 và nhập/xuất thuần trở
>    lại như ban đầu — đúng ý nghĩa nghiệp vụ.
> 5. `formula`, `formula_net` và `column_semantics` phát hành ngay trong payload nên người
>    đọc báo cáo không phải suy diễn từ tên cột.
>
> Xác nhận đúng BR-RPT-01..07 và §13.10 RPT-XNT-01/RPT-MOV-01. Không phát sinh yêu cầu mới.

---

## 5. FE — xác nhận consumer mapping · dán vào **WWHN-240**

> ✅ **FE sign-off — consume đúng response tại cùng một version**
>
> Tôi xác nhận đã đọc và **duyệt**, FE `79474b8c6cd873f26bc6b42ca1bd9af2171ce7cf` ghép với
> BE `9640091cf780fce1ef54102f6955971a57c5678b`:
>
> 1. **SCR-RPT-01 · tab XNT** hiển thị ba nhóm cột Nhập / Xuất / Xuất LK, mỗi nhóm
>    `Gross · Đã đảo · Thuần`, cộng `Reversal net` và `Cuối kỳ`; có fallback về cột gross cũ
>    nếu ghép với backend chưa cập nhật.
> 2. **TRACE-01** hiển thị bảng **Correction chain** (vai trò Gốc/Đảo, movement, Δ tồn, thời
>    điểm, người thực hiện, lý do) và khối **Net effect** (SL gốc, đã đảo, còn đảo được, tác
>    động ròng, trạng thái đảo), kèm cảnh báo khi `truncated=true`.
> 3. Đã map `correction_chain` + `net_effect` trong `inventoryService`; các khoá cũ
>    `original_movement`/`reversal_movement` vẫn được BE giữ nên không có màn hình nào vỡ.
> 4. 403 `ACCESS_DENIED` của trace/báo cáo dùng chung đường xử lý lỗi sẵn có.
>
> Test FE: **578/578 pass**, `tsc --noEmit` sạch. Không còn yêu cầu contract nào chờ BE.

---

## 6. QA — xác nhận testability và cùng candidate · dán vào **WWHN-241**

> ✅ **QA sign-off — nhận build cố định và runbook WWHN-241**
>
> Tôi xác nhận đã đọc và **duyệt**:
> 1. Build cố định để test: BE `9640091cf780fce1ef54102f6955971a57c5678b` (ancestor
>    `c855851b37920237d110f7dffe5dbbad509aebce`), FE `79474b8c6cd873f26bc6b42ca1bd9af2171ce7cf`
>    — **cùng candidate** mà DEV và FE đã xác nhận, không phải bản khác.
> 2. Runbook `docs/evidence/WWHN-240/QA1_RUNBOOK_WWHN-241.md`: 20 TC phủ original/reversal/net
>    của XNT, trace chain, RBAC/data-scope, replay cùng Idempotency-Key, double reverse,
>    vượt `reversible_qty`, concurrency và rollback.
> 3. Bốn bất biến bắt buộc kiểm ở mọi TC báo cáo (closing theo NET = closing theo gross =
>    SUM(qty_delta) của ledger; `reversal_net` tái lập được từ 4 cột con).
> 4. Bộ test tự động kèm theo `Wwhn240ReversalNetEffectContractTest` **14/14** — dùng làm
>    smoke trước khi chạy tay.
>
> Ghi nhận đỏ ngoài phạm vi: 2 test `SchemaApiModulesTest` (`pv_product_profile` unique) đỏ
> sẵn có trên `m1`, không liên quan WWHN-240, theo dõi riêng.

---

## 7. BAS / PM — đóng **DEV240-P2-01** · dán vào **WWHN-240**

> ✅ **BAS/PM — đính chính trace metadata, không đổi business trace key**
>
> Tôi xác nhận đã đọc và **duyệt**:
> 1. Wording "Duy Khánh / BE2" trong Description là **dấu vết lịch sử phân công**; ownership
>    thực thi hiện hành là nhánh `m1`, phát hành tại BE `9640091cf780fce1ef54102f6955971a57c5678b`
>    và FE `79474b8c6cd873f26bc6b42ca1bd9af2171ce7cf`.
> 2. **Business trace key giữ nguyên**: `WWHN-26 → DEC-REV-001 → WWHN-239 → WWHN-240 → WWHN-241`.
> 3. Đính chính bằng comment này, **không** sửa đè Description gốc để giữ khả năng đối chiếu
>    ngược với các task cùng đợt.
>
> Không phát sinh việc mới.
