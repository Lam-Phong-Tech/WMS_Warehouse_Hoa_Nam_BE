# WWHN-309 — Bộ comment xác nhận (sign-off) theo vai trò

**Cách dùng:**

1. Thay `<CANDIDATE_SHA>` bằng giá trị `candidate_sha` trong `docs/evidence/WWHN-309/CANDIDATE_MANIFEST.json`. Không tự gõ SHA khác, không dùng `m1` (floating head).
2. Thay `<CI_RUN_URL>` bằng URL run thật của workflow `WWHN-309 Backend Handover Evidence` chạy tại đúng SHA đó — lấy từ `docs/evidence/WWHN-309/CI_RUN_RECORD.md` sau khi QA1 pin.
3. **Mỗi vai trò post đúng 1 comment**, do **chính chủ vai trò** post. Một account ký hộ nhiều vai trò không có giá trị độc lập (lỗi FE311-P2-01 đã bị bắt).
4. Không ghi `VERIFIED` cho hạng mục CI khi chưa có run thật; giữ nguyên `PENDING CI EXECUTION`.
5. **Phân biệt rõ**: comment của DEV là *tự khai*; chỉ comment của BA/BAS, Tech Lead, QA1, DevOps mới là *xác nhận độc lập*. Vì FE1/BE1 đã hợp nhất thành DEV, phần biên nhận contract phía FE nằm trong chính comment DEV và **không** được tính là sign-off độc lập thứ hai.

---

## 1. DEV — Quang Nguyễn Thanh (chủ nhiệm task) — *tự khai, không phải sign-off độc lập*

```text
[DEV SIGN-OFF — WWHN-309] Backend handover kiến trúc/API/vận hành — vòng 2 (đóng 74/100)

Tôi xác nhận đã hoàn thành và tự kiểm trên nhánh m1 @ <CANDIDATE_SHA>:

1. Tài liệu bàn giao: HN-BE-ARCH-001 (kiến trúc as-built), HN-BE-API-001 (contract API cho
   FE/QA), HN-BE-API-002 (247 error code [historical] — số hiện tại xem HN-BE-API-004),
   HN-BE-API-003 (231 operation × 57 permission), HN-BE-API-004 (số liệu contract sinh tự
   động), HN-BE-OPS-001 (runbook vận hành), README build/run/env, docs/docs_task_jira/WWHN-309.md.
2. Đóng gốc DEV309-P1-02: số liệu contract không còn là văn xuôi. HN-BE-API-004 sinh từ
   OpenAPI + catalog + ma trận; cổng tổng đối chiếu MỌI con số trong tài liệu bàn giao với
   artifact đó, dòng mô tả trạng thái cũ phải đánh dấu [historical]. Lệch 2267/2268 không
   thể tái diễn vì tài liệu lệch số là CI đỏ.
3. Đóng gốc DEV309-P1-01: docs/evidence/WWHN-309/CANDIDATE_MANIFEST.json pin candidate SHA
   + SHA-256 từng artifact; scripts/verify-wwhn-309-candidate-pin.mjs kiểm range invariant
   (không commit nào sau candidate được chạm vào tập artifact), bắt buộc khai pull_request
   hoặc independent_review. Quy scope commit gộp 127c573 nằm ở WWHN-309.md §9.2 (phần
   WWHN-310/WWHN-295 tách bạch, không dùng để chấm WWHN-309).
4. Đóng DEV309-P1-03: paths: của workflow evidence trước đây KHÔNG phủ docs/api-contract,
   docs/architecture, docs/ops và OpenAPI — sửa hợp đồng API mà workflow không chạy. Đã mở
   rộng phủ toàn bộ artifact, thêm step Candidate pin integrity, và CI_RUN_RECORD.md là chỗ
   duy nhất pin run.
5. Đóng DEV309-P2-02: gỡ app/Http/Middleware/CurrentBaselineIfMatch.php theo
   DEC-API-IFMATCH-001. Lý do: nó không gắn vào route nào nhưng là nguồn DUY NHẤT của hai
   error code RESOURCE_ID_REQUIRED và VERSION_CONTRACT_MISSING đang publish cho FE/QA —
   contract quảng cáo hai mã không đường chạy nào trả về. Catalog giảm đúng 2 mã ma.
6. [historical] (số hiện tại xem HN-BE-API-004) — OpenAPI: 183 path / 231 operation, phủ 100% route /api/v1; 2315/2315 response có schema envelope, 27 operation khai Idempotency-Key (23 bắt buộc, 4 tuỳ chọn), 58 operation khai If-Match — sinh tự động từ source nên không lệch runtime.
7. Bất biến MVP giữ nguyên: không stocktake/sửa tồn trực tiếp, full-post, correction qua
   reversal, RFID optional, scan stock-neutral.
8. Biên nhận contract phía FE (cùng người, KHÔNG tính là sign-off độc lập):
   FE@docs/Task_docs_Jira/WWHN-309_BE_CONTRACT_RECEIPT.md; FE pin BE SHA ở
   docs/api-evidence/BE_CONTRACT_PIN.json nên npm run contract:sync fail-closed khi lệch
   revision.

CI exact-SHA: PENDING CI EXECUTION cho tới khi run tại <CANDIDATE_SHA> được pin vào
CI_RUN_RECORD.md. Không có blocker P0 thuộc vai trò DEV.
```

---

## 2. BA/BAS — Nguyễn Thị Hải Yến — *xác nhận độc lập*

```text
[BAS/BA SIGN-OFF — WWHN-309]

Tôi xác nhận đã đọc và duyệt gói bàn giao backend tại m1 @ <CANDIDATE_SHA>, không yêu cầu
làm thêm:

1. Bất biến nghiệp vụ trong tài liệu bàn giao khớp SRS/FRD v1.12: không stocktake/sửa tồn
   trực tiếp, full-post, outbound Scan → Packing Label (Preview/Ready/Print + xác nhận đã
   dán) → Post Issue, chỉ Post Issue mới trừ tồn, RFID optional không phải gate, correction
   qua reversal append-only.
2. Controlling baseline: SRS/FRD v1.12 + Annex C là nguồn chuẩn nghiệp vụ; khi changelog
   OpenAPI cũ (v1.0.1, còn nhắc stocktake apply-adjustment) mâu thuẫn với route table và
   tài liệu hiện hành thì route table + tài liệu hiện hành thắng.
3. Scan classify role mapping: baseline gồm 6 role — 4 role theo WWHN-150 cộng
   WAREHOUSE_KEEPER và WAREHOUSE_STAFF qua alias Annex C scan.execute. Đây là đúng seed/
   route đã duyệt, KHÔNG phải thay đổi quyền; chỉ expectation của evidence được sửa cho
   khớp code và SRS v1.12 §5.
4. Endpoint public đúng 9 và được allowlist tường minh; nhóm /api/v1/pv/* để anonymous là
   đúng SRC-008 / DR-PLAT-001 (App PV read-only, không chạm stock ledger).
5. Contract âm trong HN-BE-API-001 §8 (không có endpoint stocktake, không partial posting,
   không sửa movement đã post, không public migration import) phản ánh đúng quyết định đã
   chốt.

Không còn open point nghiệp vụ thuộc BA/BAS đối với WWHN-309. Việc đóng parent WWHN-83 vẫn
tách riêng, không đóng theo task này.
```

---

## 3. Tech Lead — *xác nhận độc lập*

```text
[TECH LEAD SIGN-OFF — WWHN-309]

Tôi xác nhận đã duyệt các quyết định kỹ thuật của gói bàn giao tại m1 @ <CANDIDATE_SHA>:

1. Kiến trúc as-built HN-BE-ARCH-001 phản ánh đúng hệ thống đang chạy: modular monolith,
   PostgreSQL là nguồn sự thật duy nhất, Valkey là cache/queue/session có fail-open, job nền
   tách container.
2. Cách khai contract header và envelope trong OpenAPI sinh từ source (RouteSyncProcessor +
   ApiEnvelopeSchemas) là hướng đúng: tài liệu không thể lệch runtime mà không sửa cùng chỗ.
3. DUYỆT disposition DEC-API-IFMATCH-001: GỠ app/Http/Middleware/CurrentBaselineIfMatch.php.
   Lý do tôi chấp nhận: middleware không gắn vào route nào, enforcement thật ở controller,
   và nó là nguồn duy nhất của hai mã lỗi mà không đường chạy nào trả về được — giữ lại là
   để một contract sai tiếp tục được FE/QA tiêu thụ. Nếu sau này muốn đưa optimistic locking
   về tầng route thì phải làm đủ điều kiện trong decision record, quan trọng nhất là gỡ
   helper assertVersion ở đúng controller đó để version không tăng hai lần.
4. Đăng ký EnforceInventoryWarehouseScope toàn cục là chủ ý fail-closed, không refactor
   thành đăng ký theo từng route.
5. DUYỆT cơ chế chống trôi: HN-BE-API-004 làm nguồn số liệu duy nhất và range invariant
   trong verify-wwhn-309-candidate-pin.mjs. Tôi đồng ý rằng chạm vào artifact bàn giao sau
   candidate SHA thì phải re-pin và chạy lại evidence, không được trích dẫn SHA cũ.
6. Review độc lập khi pull_request = N/A: tôi là người review diff của tập artifact bàn giao
   nêu trong CANDIDATE_MANIFEST.json.

Không có blocker kỹ thuật thuộc Tech Lead đối với WWHN-309.
```

---

## 4. QA1 — DT Soi — *xác nhận độc lập*

```text
[QA1 SIGN-OFF — WWHN-309]

Tôi xác nhận đã review tài liệu và evidence bàn giao tại m1 @ <CANDIDATE_SHA>, đủ điều kiện
để QA kiểm thử độc lập:

1. Catalog 247 error code [historical] — số hiện tại xem HN-BE-API-004 + ma trận 231
   operation × 57 permission đủ chi tiết để viết case âm/dương; endpoint public đúng 9 và
   được allowlist tường minh.
2. Quy tắc kiểm thử đồng thời rõ ràng: If-Match sai version → 412, thiếu header bắt buộc →
   428, Idempotency-Key trùng payload → replay / khác payload → 409.
3. Runbook vận hành HN-BE-OPS-001 đủ để dựng và kiểm tra môi trường: health check, 4 job nền
   bắt buộc, lệnh vận hành, kịch bản sự cố.
4. Tôi nhận kịch bản retest độc lập docs/evidence/WWHN-309/QA_RETEST_PLAN.md và đồng ý với
   thiết kế của nó, đặc biệt là 3 phép KIỂM ÂM bắt buộc (sửa số trong tài liệu, sửa byte
   trong artifact đã pin, thêm /docs/ vào .gitignore) — cổng nào không đỏ đúng chỗ thì tôi
   ghi nhận là defect P1, không ghi PASS.
5. Tôi sẽ kiểm trên PostgreSQL tại đúng <CANDIDATE_SHA> và bắt đầu bằng
   verify-wwhn-309-candidate-pin.mjs; nếu pin đã mục thì tôi trả task về DEV, không kiểm
   tiếp trên bản đã trôi.

CI/runtime evidence: PENDING CI EXECUTION — tôi chỉ ghi PASS cho gate CI sau khi có run thật
tại <CANDIDATE_SHA> (<CI_RUN_URL>) kèm log thô và checksum artifact, và chỉ accept khi
resolved source SHA trong run bằng đúng candidate_sha. Kết quả cuối ghi vào WWHN-312.
```

---

## 5. DevOps — Minh Tân Lê — *xác nhận độc lập*

```text
[DEVOPS SIGN-OFF — WWHN-309]

Tôi xác nhận đã đọc phần vận hành tại m1 @ <CANDIDATE_SHA> và tiếp nhận các yêu cầu môi
trường:

1. Bắt buộc có process chạy schedule:work (hoặc cron schedule:run) ở UAT/production —
   thiếu thì 4 job nền (inventory:reconcile, audit:reconcile, import:purge-error-files,
   warranty:purge-pii) không thực thi trên thực tế dù schedule:list vẫn in ra đủ.
2. CI/build phải inject APP_BUILD_SHA / APP_BUILD_REF / APP_BUILD_TIME. Tôi ghi nhận bằng
   chứng trong DEVOPS_HANDOVER_VERIFICATION.md §1.3: ở môi trường dev build.* đang null —
   chấp nhận được ở local, KHÔNG chấp nhận ở UAT/production vì mọi gate exact-SHA mất căn cứ
   và wms:golive-check sẽ chặn.
3. Biến môi trường theo .env.example: CACHE_PREFIX riêng cho từng môi trường dùng chung
   Valkey, CORS_ALLOWED_ORIGINS liệt kê tường minh, APP_DEBUG=false ngoài local,
   AUTO_MIGRATE=false.
4. Quy trình phát hành/rollback: backup trước migrate; ưu tiên deploy lại image cũ hoặc
   restore backup; migrate:rollback toàn lịch sử đã từng brick DB nên chỉ dùng khi đã kiểm
   down() và có backup.
5. Probe dùng /api/v1/health; cache cố ý không phải critical check (fail-open xuống
   PostgreSQL).
6. Tôi nhận bảng xác minh 10 mục trong docs/evidence/WWHN-309/DEVOPS_HANDOVER_VERIFICATION.md
   và sẽ điền kết quả thật cho UAT và production.

Phần environment/access/backup/monitor cấp hạ tầng vẫn thuộc WWHN-313, không đóng tại
WWHN-309.
```

---

## 6. PM / BAS (parent) — *xác nhận phạm vi*

```text
[PM/BAS SIGN-OFF — WWHN-309 phạm vi]

Tôi xác nhận phạm vi đóng của WWHN-309 và ranh giới với các task anh em:

1. WWHN-309 đóng ở mức: tài liệu kiến trúc/API/vận hành backend + cơ chế chống trôi tài
   liệu + pin evidence tại exact SHA. Không đóng thay task khác.
2. Không đóng parent WWHN-83 theo task này.
3. Test/release evidence tổng thuộc WWHN-312; environment/access/backup/monitor thuộc
   WWHN-313; handover index/product/training thuộc WWHN-308; tài liệu dữ liệu/migration/
   báo cáo thuộc WWHN-310.
4. Vai trò thực hiện đã hợp nhất về DEV; chuỗi "BE1" còn lại trong tên file/changelog là dấu
   vết lịch sử được giữ có chủ đích (WWHN-309.md §10), không phải mô tả trách nhiệm hiện tại.
```

---

## Hạng mục KHÔNG được ký tại WWHN-309

| Nội dung | Thuộc về |
| --- | --- |
| Đóng parent WWHN-83 | BAS/PM |
| Environment/access/backup/monitor cấp hạ tầng | DevOps — WWHN-313 |
| Test/release evidence tổng | QA — WWHN-312 |
| Handover index/product/training | BAS — WWHN-308 |
| Tài liệu dữ liệu/migration/báo cáo | DEV — WWHN-310 |
| Contract health/build identity (`/api/v1/health`) | DEV — WWHN-295 |
