# WWHN-309 — Bộ comment xác nhận (sign-off) theo vai trò

**Cách dùng:**

1. Thay `<BE_CLOSURE_SHA>` bằng **exact commit SHA** của nhánh `m1` chứa toàn bộ thay đổi WWHN-309 (pin sau khi commit, không pin SHA cũ — đây đúng là lỗi FE311-P1-01 đã bị bắt).
2. Thay `<CI_RUN_URL>` bằng URL run thật của workflow `WWHN-309 Backend Handover Evidence` chạy tại đúng SHA đó.
3. **Mỗi vai trò post đúng 1 comment.** Comment phải do **chính chủ vai trò** post — sign-off do một account tạo hộ nhiều vai trò không có giá trị độc lập (FE311-P2-01 đã bị bắt lỗi này).
4. Không ghi `VERIFIED` cho hạng mục CI khi chưa có run thật; giữ nguyên `PENDING CI EXECUTION`.

---

## 1. BE1 — Quang Nguyễn Thanh (chủ nhiệm task)

```text
[BE1 SIGN-OFF — WWHN-309] Backend handover kiến trúc/API/vận hành

Tôi xác nhận đã hoàn thành và tự kiểm trên nhánh m1 @ <BE_CLOSURE_SHA>:

1. Tài liệu bàn giao: HN-BE-ARCH-001 (kiến trúc as-built), HN-BE-API-001 (contract API cho
   FE/QA), HN-BE-API-002 (243 error code), HN-BE-API-003 (226 operation × 56 permission),
   HN-BE-OPS-001 (runbook vận hành), README build/run/env, docs/docs_task_jira/WWHN-309.md.
2. OpenAPI: phủ 100% route /api/v1 (226/226); 2268/2268 response có schema envelope
   (ApiSuccessEnvelope/ApiErrorEnvelope); 27 operation khai Idempotency-Key, 41 khai If-Match —
   sinh tự động từ source nên không lệch runtime.
3. Chống trôi tài liệu: 2 generator có --check + cổng tổng
   scripts/verify-wwhn-309-handover-contract.mjs, đã gắn vào workflow evidence.
3b. .gitignore từng chặn toàn bộ /docs/ khiến 20 tài liệu (gồm SRS baseline, 6 decision
   record, runbook cutover/rollback và runbook nạp dữ liệu cuối) nằm ngoài git — đã bỏ rule
   và thêm guard: tài liệu handover bắt buộc mà bị git check-ignore bắt sẽ FAIL.
4. Bất biến MVP giữ nguyên: không stocktake/sửa tồn trực tiếp, full-post, correction qua
   reversal, RFID optional, scan stock-neutral.
5. Test contract/doc: 147 test PASS (145 passed, 2 skipped, 0 failed) trên SQLite;
   nghiệm thu PostgreSQL chạy trong workflow evidence.

CI exact-SHA: PENDING CI EXECUTION cho tới khi run tại <BE_CLOSURE_SHA> được pin.
Không có blocker P0 thuộc vai trò BE1.
```

---

## 2. BA/BAS — Nguyễn Thị Hải Yến

```text
[BAS/BA SIGN-OFF — WWHN-309]

Tôi xác nhận đã đọc và duyệt, không yêu cầu làm thêm:

1. Bất biến nghiệp vụ trong tài liệu bàn giao khớp SRS v1.12: không stocktake/sửa tồn trực
   tiếp, full-post, outbound Scan → Packing Label → Post Issue, RFID optional, correction qua
   reversal append-only.
2. Baseline permission của scan classify gồm 6 role (4 role WWHN-150 + WAREHOUSE_KEEPER /
   WAREHOUSE_STAFF qua alias Annex C scan.execute) — đúng seed/route đã duyệt, không phải
   thay đổi quyền.
3. 4 endpoint /api/v1/pv/* để anonymous là đúng SRC-008 / DR-PLAT-001 (App PV read-only).
4. Contract âm trong HN-BE-API-001 §8 (không có endpoint stocktake, partial posting, sửa
   movement đã post, public migration import) phản ánh đúng quyết định đã chốt.
5. Changelog OpenAPI v1.0.1 còn nhắc stocktake apply-adjustment là bản ghi lịch sử; khi mâu
   thuẫn thì route table và tài liệu hiện hành thắng.

Không còn open point nghiệp vụ thuộc BA/BAS đối với WWHN-309.
```

---

## 3. FE1 — Frontend

```text
[FE1 SIGN-OFF — WWHN-309]

Tôi xác nhận đã nhận đủ contract backend để tích hợp và đã kiểm chứng được:

1. Lấy OpenAPI của BE theo exact SHA bằng npm run contract:sync -- <BE_CLOSURE_SHA>.
2. npm run check:api-mapping PASS: 56 dòng contract đối chiếu với OpenAPI thật của BE
   (4 dòng fail-closed đúng thiết kế), không có endpoint/permission stocktake.
3. Envelope đọc được trực tiếp từ spec (ApiSuccessEnvelope/ApiErrorEnvelope); error_code và
   error.code luôn bằng nhau; FE bắt lỗi theo error_code.
4. Header contract đã rõ: Idempotency-Key (27 operation, 23 bắt buộc) và If-Match
   (41 operation) — FE xử lý 428/412 theo đúng hướng dẫn HN-BE-API-001 §4/§5.
5. Biên nhận phía FE: docs/Task_docs_Jira/WWHN-309_BE_CONTRACT_RECEIPT.md.

Không còn contract mismatch thuộc FE1 đối với WWHN-309. FE không ký thay BE1.
```

---

## 4. QA1 — DT Soi

```text
[QA1 SIGN-OFF — WWHN-309]

Tôi xác nhận đã review tài liệu và evidence bàn giao, đủ để kiểm thử:

1. Catalog 243 error code + ma trận 226 operation × 56 permission đủ chi tiết để viết case
   âm/dương; endpoint public đúng 7 và được allowlist tường minh.
2. Quy tắc kiểm thử đồng thời rõ ràng: If-Match sai version → 412, thiếu header bắt buộc →
   428, Idempotency-Key trùng payload → replay / khác payload → 409.
3. Runbook vận hành HN-BE-OPS-001 đủ để dựng và kiểm tra môi trường: health check, 4 job nền
   bắt buộc, lệnh vận hành, kịch bản sự cố.
4. Cổng chống trôi tài liệu chạy được tại chỗ và trong CI
   (scripts/verify-wwhn-309-handover-contract.mjs).

CI/runtime evidence: PENDING CI EXECUTION — tôi sẽ chỉ ghi PASS cho gate CI sau khi có run
thật tại <BE_CLOSURE_SHA> (<CI_RUN_URL>) kèm log thô và checksum artifact.
```

---

## 5. DevOps — Minh Tân Lê

```text
[DEVOPS SIGN-OFF — WWHN-309]

Tôi xác nhận đã đọc phần vận hành và tiếp nhận các yêu cầu môi trường:

1. Bắt buộc có process chạy schedule:work (hoặc cron schedule:run) ở UAT/production —
   thiếu thì 4 job nền (inventory:reconcile, audit:reconcile, import:purge-error-files,
   warranty:purge-pii) không thực thi trên thực tế.
2. CI/build phải inject APP_BUILD_SHA / APP_BUILD_REF / APP_BUILD_TIME; wms:golive-check chặn
   nếu thiếu ở môi trường ngoài local/testing.
3. Biến môi trường theo .env.example: CACHE_PREFIX riêng cho từng môi trường dùng chung
   Valkey, CORS_ALLOWED_ORIGINS liệt kê tường minh, APP_DEBUG=false ngoài local.
4. Quy trình phát hành/rollback: backup trước migrate; ưu tiên deploy lại image cũ hoặc
   restore backup; migrate:rollback chỉ khi đã kiểm down() và có backup.
5. Probe dùng /api/v1/health; cache cố ý không phải critical check.

Phần environment/backup/monitor cấp hạ tầng vẫn thuộc WWHN-313, không đóng tại WWHN-309.
```

---

## 6. Tech Lead

```text
[TECH LEAD SIGN-OFF — WWHN-309]

Tôi xác nhận đã duyệt các quyết định kỹ thuật của gói bàn giao:

1. Kiến trúc as-built HN-BE-ARCH-001 phản ánh đúng hệ thống đang chạy (modular monolith,
   PostgreSQL là nguồn sự thật, Valkey là cache/queue/session có fail-open).
2. Cách khai contract header và envelope trong OpenAPI sinh từ source (RouteSyncProcessor) là
   hướng đúng: tài liệu không thể lệch runtime mà không sửa cùng chỗ.
3. Disposition cho app/Http/Middleware/CurrentBaselineIfMatch: giữ nguyên, chưa gắn route;
   enforcement thật nằm ở controller và đã có contract test riêng. Nếu sau này wire vào route
   phải kiểm tra không double-increment version.
4. Đăng ký EnforceInventoryWarehouseScope toàn cục là chủ ý fail-closed, không refactor thành
   đăng ký theo route.

Không có blocker kỹ thuật thuộc Tech Lead đối với WWHN-309.
```

---

## Hạng mục KHÔNG được ký tại WWHN-309

| Nội dung | Thuộc về |
| --- | --- |
| Đóng parent WWHN-83 | BAS/PM |
| Environment/access/backup/monitor cấp hạ tầng | DevOps — WWHN-313 |
| Test/release evidence tổng | QA — WWHN-312 |
| Handover index/product/training | BAS — WWHN-308 |
| Tài liệu dữ liệu/migration/báo cáo | BE — WWHN-310 |
