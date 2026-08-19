# WWHN-233 — Comment xác nhận theo vai trò

Mỗi vai trò **một** comment duy nhất, dán nguyên văn vào Jira. Không cần làm việc mới:
chỉ đọc artifact được trỏ tới và xác nhận.

**Exact identity dùng cho mọi comment:**

```text
BE commit SHA: bf06fea0bf228f7fe0b119525eeb6e099bb94c9d
FE commit SHA: 3bad47deb266b2cc276fa9844b1cf00e9b50348c
Branch: m1
Evidence: BE docs/evidence/WWHN-233/SHA256SUMS
Disposition: DISP-STK-233 v1.0.0 — DEFERRED / NO CODE (PROPOSED, no effective date)
Controlling source: REQ-SRS-037 — HN_SRS_FRD_v1.12_FULL.md (adopt tại WWHN-334)
```

> **Cách đọc hai SHA trên.** Chúng là *commit cuối cùng chạm artifact của WWHN-233* ở mỗi
> repo, không phải `HEAD` của `m1`. Bốn nhánh Stocktake (230/233/236/237) cùng commit vào
> `m1` nên `HEAD` thường là commit của task khác; pin theo `HEAD` sẽ trỏ nhầm. Vì cùng lý
> do đó, `bf06fea` mang nhãn `docs(WWHN-299)` — phiên song song đã gộp hai file gate của
> WWHN-233 vào commit của họ. Nội dung nguyên vẹn và verifier xác nhận tại chính SHA này;
> lịch sử `m1` không được viết lại để "làm đẹp" nhãn. Bản ghi máy đọc được:
> `docs/evidence/WWHN-233/SOURCE_REGISTER.json` → `repository`.

---

## 1. BA Main + PM/PO — comment trên `WWHN-233` (đóng `DEV233-P1-01`)

```text
[BA Main + PM/PO] XÁC NHẬN DISPOSITION — WWHN-233

Tôi xác nhận 3 điểm:

1. Controlling source cho phạm vi kiểm kê là REQ-SRS-037 (Must) trong SRS/FRD v1.12,
   adopt tại WWHN-334. DEC-STK-001 (WWHN-25) vẫn PROPOSED / no effective date và
   KHÔNG được dùng làm căn cứ triển khai.
2. WWHN-59 và WWHN-233 = DEFERRED — NO CODE. Không implement count / recount /
   partial / stale. AC nào của WWHN-59 còn yêu cầu các hành vi đó không còn hiệu lực
   và sẽ được BA rebaseline.
3. Điều kiện re-enable duy nhất là bộ 4 điều kiện tại DISP-STK-233 §2 (CR được duyệt
   + WWHN-25 GO/CONDITIONAL GO có effective date + SRS rebaseline REQ-SRS-037 + chỉ
   khi đủ 3 điều kiện mới mở DEV), dùng chung với nhánh WWHN-60/236.

Tôi đã đọc: docs/decision-records/DISP-STK-233-COUNT-RECOUNT-DEFERRED.md
Hệ quả vận hành đã nắm: MVP không có kiểm kê vật lý; lệch tồn chỉ phát hiện qua
reconciliation report và chỉ sửa bằng Reversal append-only. Chấp nhận được.

Disposition: DEFERRED
Effective date: NULL (chờ formal sign-off)
```

---

## 2. Tech Lead — comment trên `WWHN-233` (đóng `DEV233-P1-02` + `DEV233-P1-04`)

```text
[Tech Lead] XÁC NHẬN NO-CODE HANDOFF + CONTRACT NEGATIVE LOCK — WWHN-233

Tôi xác nhận 3 điểm:

1. "0 attachment, 0 PR/SHA, search WWHN-233 = 0 commit" là kết quả ĐÚNG của một
   disposition DEFERRED, không phải thiếu sót. Handoff được ghi tường minh tại
   docs/evidence/WWHN-233/NO_CODE_HANDOFF.md, pin đúng BE/FE SHA ở trên.
2. Thay đổi trong task này chỉ là HÀNG RÀO, không phải capability: 0 route,
   0 controller, 0 service, 0 migration, 0 permission, 0 màn hình, 0 report.
3. DEV233-P1-04 là nghĩa vụ PHỦ ĐỊNH ở MVP này: runtime OpenAPI (84 path), baseline
   contract, permission catalog đều zero bề mặt count/recount/variance/adjustment.
   Artifact historical (WWHN-180 OpenAPI/Postman, WWHN-131) giữ nguyên digest, không
   bị sửa cho khớp. Khi re-enable, mục này quay lại đúng nguyên văn register và phải
   đóng bằng OpenAPI + Postman + schema được tech-approve.

Tôi đã đọc: NO_CODE_HANDOFF.md, CONTRACT_NEGATIVE_LOCK.json,
scripts/verify-wwhn233-count-disposition.mjs (PASS), workflow gate.
```

---

## 3. BAS + BA — comment trên `WWHN-233` (đóng `DEV233-P1-03`)

```text
[BAS + BA] XÁC NHẬN SOURCE REGISTER — WWHN-233

Tôi xác nhận 3 điểm:

1. WWHN-59 và các task con KHÔNG còn dùng BRD/Handoff v3.9 làm baseline. Nguồn hiện
   hành: BRD v3.20 (WWHN-333) + SRS/FRD v1.12 (WWHN-334). SRS v1.12 đã đánh dấu
   BRD v3.9 là HISTORICAL/SUPERSEDED và gỡ Handoff v3.10 reference.
2. ID lịch sử (BRD v3.9, DEC-STK-001, WWHN-180 OpenAPI, WWHN-131 Postman/test-case)
   chỉ giữ để trace/tombstone, không được trích làm controlling contract.
3. Source register có exact version + digest tại
   docs/evidence/WWHN-233/SOURCE_REGISTER.json. Digest được verifier TÍNH LẠI mỗi
   lần chạy thay vì so với hằng số chép tay, nên không lặp lại lỗi pin rot.
   Một pin rot có thật đã được sửa trong task này: STOCKTAKE_GOVERNANCE_STATUS.json
   ghi ERD sha256 c40ce8d6... trong khi file thật là 30badd14... (ERD đổi ở commit
   f4b8404). Anchor §0.1 vẫn nguyên vẹn — chỉ hash bị stale.

Tôi đã đọc: SOURCE_REGISTER.json, DISP-STK-233 §3.
```

---

## 4. QA1 — comment trên `WWHN-235` (đóng `DEV233-P1-06`)

```text
[QA1] XÁC NHẬN QA DISPOSITION — WWHN-235

Tôi xác nhận 3 điểm:

1. WWHN-235 = DEFERRED / N/A cho MVP v1.12 — KHÔNG phải Blocked, KHÔNG phải Pending.
   Không có vướng mắc nào để gỡ: capability mà ticket này định test đã bị REQ-SRS-037
   loại khỏi phạm vi MVP.
2. Phạm vi QA thực tế ở MVP này là NEGATIVE GATE 7 mục (route surface, permission
   catalog, end-state schema, movement type, runtime OpenAPI + nguồn, anti-dangling,
   FE route/menu/permission) — chi tiết tại
   docs/evidence/WWHN-233/QA_DISPOSITION_WWHN-235.md §2. Kết quả 7 mục đó LÀ kết quả
   QA của WWHN-233; không kỳ vọng kết quả dương nào khác.
3. Đã chạy trên exact build ở trên: BE Wwhn233CountDeferredGateTest 7/7 pass
   (228 assertion); BE verifier PASS; FE wwhn-233:verify PASS; FE guard test 6/6 pass.

Khi re-enable, WWHN-235 quay lại đúng nguyên văn register (duplicate/partial/stale/
RBAC trên exact build) và bộ negative gate trở thành regression suite.

QA VERIFIED (negative scope) — build SHA như trên.
```

---

## 5. DBA — comment trên `WWHN-233` (đóng `DEV233-P1-05`)

```text
[DBA] XÁC NHẬN STOCK-EFFECT NEGATIVE LOCK — WWHN-233

Tôi xác nhận 3 điểm:

1. Không tồn tại đường ghi tồn thứ hai. Constraint chk_stock_movement_type_current
   chỉ cho OPENING / RECEIPT / ISSUE / COMPONENT_ISSUE / REVERSAL, nên ADJUST_IN /
   ADJUST_OUT bị PostgreSQL từ chối ở tầng DB chứ không chỉ ở tầng ứng dụng.
2. Bốn bảng stocktake_* vắng mặt ở end-state schema. Chain migration lịch sử
   (2026_01_01_000044 tạo → 000400/000420/000600 drop) được GIỮ NGUYÊN có chủ đích:
   xoá một migration đã chạy sẽ phá migrate:rollback (bài học WWHN-300). Nghĩa vụ
   được đo ở end-state, không ở lịch sử.
3. stock_movements là append-only qua trigger hn_reject_append_only_change();
   correction path duy nhất là Reversal (inbound/outbound reverse + reversal-documents),
   đều yêu cầu Idempotency-Key + If-Match.

Không có transaction/idempotency/reconcile report DƯƠNG nào được yêu cầu ở MVP này
vì không có transaction nào để đo. Khi re-enable, mục này phải đóng bằng
race/retry/rollback/reconcile report chạy trên đúng SHA.

Tôi đã đọc: CONTRACT_NEGATIVE_LOCK.json, migration 2026_08_10_000620,
Wwhn233CountDeferredGateTest.
```

---

## 6. PM / BAS — comment trên `WWHN-233` (đóng `DEV233-P2-01`)

```text
[PM/BAS] XÁC NHẬN OWNER NARRATIVE — WWHN-233

Tôi xác nhận 2 điểm:

1. Tên "Duy Khánh" trong Description là HISTORICAL PLACEHOLDER, không phải trách
   nhiệm hiện hành. Live assignee / current DEV owner là Quang.
2. Description được đồng bộ: giữ tên cũ trong phần trace lịch sử kèm nhãn
   "historical placeholder", phần responsibility trỏ current DEV owner. Repository
   không lưu assignee; bản ghi trace tại
   docs/evidence/WWHN-233/NO_CODE_HANDOFF.md §4.

Jira trace đã cập nhật.
```

---

## 7. Ghi chú cho người dán comment

- Nếu vai trò nào **không đồng ý** disposition DEFERRED, đừng sửa comment thành
  "approved có điều kiện": mở Change Request theo `DISP-STK-233` §2 điều kiện 1.
  Disposition này không tự nhận hiệu lực — nó ở trạng thái `PROPOSED / effective date = NULL`
  và verifier assert đúng điều đó.
- Một mục còn mở, **không** thuộc WWHN-233: test
  `Flow05InventoryControlAlignmentTest::test_published_contract_artifacts_carry_no_stocktake_capability`
  đang đỏ vì `docs/postman/WWHN-180 Canonical Contract.postman_collection.json` (immutable
  pack) còn khai `/api/v1/stocktake/sessions/{id}/apply-adjustment`. Việc này thuộc nhánh
  `WWHN-237` (`DISP-STK-237` §4 đã tuyên bố pack đó **không sửa**), đang xử lý song song
  trên cùng `m1`. Lỗi có trước WWHN-233 và không do task này gây ra.
