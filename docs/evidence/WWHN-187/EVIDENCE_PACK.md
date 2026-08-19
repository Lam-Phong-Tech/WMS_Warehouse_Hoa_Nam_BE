# WWHN-187 — Evidence pack (`DEV187-P1-01R`)

- Task: **WWHN-187** — [BE] Triển khai quét/ghi nhận/validation
- Parent: `WWHN-42` · Parent Temp Key `HN-IN-002` · Epic `HN-EPIC-05` · Role `BE2` (`HN-BE-02`)
- Dependency: `HN-IN-001`, `HN-MC-001` (xem `WWHN-187_MASTER_DATA_BASELINE.md`)
- Nhánh điều khiển: `m1` (BE và FE) — xem `WWHN-187_BRANCH_DISPOSITION.md`

## 1. Exact SHA

| Repo | Nhánh | Candidate SHA | Nội dung |
|---|---|---|---|
| BE `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `7f919da12d777356c089ff525e4f41d2d1ebd2a8` | evidence pack, decision record, Postman, verifier |
| FE `WMS_Warehouse_Hoa_Nam_FE` | `m1` | `3f2a5c2f5416be8019e0c1d8b91d1732a103f861` | consumer gửi `Idempotency-Key` cho bước quét |

## 2. File đã chạm trong vòng đóng này

### Backend

| File | Loại | Vì sao |
|---|---|---|
| `docs/decision-records/DEC-SCAN-AUDIT-001-scan-evidence-vs-audit-log.md` | Mới | Chốt `scan_event`/`scan_batch` là bằng chứng của bước quét; đóng `BE2-INSCAN-OP-01` (`DEV187-P1-05R`) |
| `docs/evidence/WWHN-187/WWHN-187_DEFECT_CLOSURE_REGISTER.md` | Mới | Register đóng 7 P1 + 1 P2 |
| `docs/evidence/WWHN-187/EVIDENCE_PACK.md` | Mới | File này (`DEV187-P1-01R`) |
| `docs/evidence/WWHN-187/WWHN-187_BRANCH_DISPOSITION.md` | Mới | Supersede `c14280` / `m1-be-2` (`DEV187-P1-02R`) |
| `docs/evidence/WWHN-187/WWHN-187_QA_EXECUTION_HANDOFF.md` | Mới | Gỡ blocker `WWHN-189` (`DEV187-P1-03R`) |
| `docs/evidence/WWHN-187/WWHN-187_MASTER_DATA_BASELINE.md` | Mới | Baseline thay `HN-MC-001` + ma trận rule→runtime→test (`DEV187-P1-04R`) |
| `docs/evidence/WWHN-187/WWHN-187_CONTRACT_LOCK.md` | Mới | Khoá hợp đồng + ma trận consumer (`DEV187-P1-05R`) |
| `docs/evidence/WWHN-187/WWHN-187_CONTRACT_PIN.json` | Mới | Digest hợp đồng + candidate + tập artifact |
| `docs/evidence/WWHN-187/WWHN-187_SOURCE_TRACEABILITY_SUPERSESSION.md` | Mới | v3.9/v3.11 → v3.20 + SRS v1.12 (`DEV187-P1-06`) |
| `docs/postman/WWHN-187 Inbound Scan Retry.postman_collection.json` | Mới | 9 request phủ 4 kịch bản retry (`DEV187-P1-07`, `P1-03R`) |
| `scripts/verify-wwhn-187-closure.mjs` | Mới | Gate chạy lại được tại exact SHA |

**Không sửa file production nào ở backend trong vòng này.** Nửa BE của `DEV187-P1-07`
(`inboundScan()` + `ScanRequestFingerprint` + annotation OpenAPI) do nhánh công việc
`WWHN-188` / `DEC-SCAN-IDEMP-001` thực hiện trên cùng `m1`; hồ sơ này tham chiếu chứ
không viết đè — xem mục 2 của register.

### Frontend

| File | Loại | Vì sao |
|---|---|---|
| `src/modules/inbound/services/inboundService.ts` | Sửa | Luôn gửi `Idempotency-Key`, tách key theo từng mã, bọc `withConcurrencyContract`, không cộng `scanned_qty` cục bộ khi `replayed` |
| `src/modules/inbound/hooks/use-inbound.ts` | Sửa | Thêm `idempotencyKey` vào `useScanInbound` |
| `src/modules/inbound/pages/MvpInboundScreens.tsx` | Sửa | `scanKeyRef` giữ key theo hành động + dấu vân tay nội dung; `shouldReuseScanKey()`; hiển thị replay |
| `src/modules/inbound/types/index.ts` | Sửa | `InboundScanResult` mang `idempotency_key` và `replayed` |
| `src/modules/inbound/services/inboundService.test.ts` | Sửa | 3 test hợp đồng mới + cập nhật 2 assertion header |

## 3. Mapping evidence ↔ `HN-IN-002` / Acceptance Criteria

| AC của `WWHN-187` | Trạng thái | Bằng chứng |
|---|---|---|
| 1. Output "Inbound scan backend" được rà soát, gắn evidence, không còn blocker của BE2 | **Done** | `WWHN-187_MASTER_DATA_BASELINE.md` mục 3 (22 quy tắc → runtime → test); `BE2-INSCAN-OP-01` đóng bằng `DEC-SCAN-AUDIT-001`; `BE2-INSCAN-OP-03` đóng bằng baseline thay thế; `BE2-INSCAN-OP-04` đã đóng từ 2026-08-18 |
| 2. Permission / validation / giao dịch / audit / idempotency / concurrency đúng phạm vi | **Done** | permission `inbound.scan`; `If-Match` + `DB::transaction(…, 3)`; 21 mã lỗi phủ test HTTP; idempotency theo `DEC-SCAN-IDEMP-001`; audit theo `DEC-SCAN-AUDIT-001` |
| 3. Lỗi trả mã rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** | `Wwhn187InboundScanValidationTest` — mỗi case chặn đều assert không sinh `inbound_scan_entries` và không tăng `scanned_qty`; case 403 assert không lộ trace |
| 4. Có unit/integration test và API/DB evidence; OpenAPI/Postman cập nhật khi contract đổi | **Done** | BE 44/44; FE 22/22; OpenAPI đã khai `Idempotency-Key` tùy chọn (digest pin ở `WWHN-187_CONTRACT_PIN.json`); Postman collection mới |
| 5. FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Done** | ma trận consumer ở `WWHN-187_CONTRACT_LOCK.md` mục 4; bàn giao QA ở `WWHN-187_QA_EXECUTION_HANDOFF.md`; biên còn lại được ghi nhận tường minh ở mục 6 của cùng file |

## 4. Kết quả chạy tại candidate

```
php vendor/bin/phpunit --filter "InboundScanConcurrencyBehaviorTest|Wwhn187InboundScanValidationTest|Wwhn188ScanIdempotencyContractTest|FoundationInboundPutawayReadinessTest"
→ 44 tests, 44 passed, 316 assertions

npx vitest run --pool=threads src/modules/inbound
→ 2 files, 22 tests, 22 passed

npx tsc --noEmit        → exit 0
vendor/bin/pint --test  → passed (file controller đã chạm)
```

## 5. Đổi tên đã chốt trước đó (giữ để không đứt truy vết)

Ghi lại vì evidence lịch sử vẫn mang tên cũ và **cố ý không sửa**, để bằng chứng đã chạy
giữ nguyên tính xác thực tại thời điểm chạy:

| Tên cũ | Tên hiện hành | Ghi chú |
|---|---|---|
| `docs/docs_task_jira/WWHN-198-inbound-scan.md` | `docs/docs_task_jira/WWHN-187.md` | WWHN-198 là task outbound khác hoàn toàn (`WWHN-46` / `HN-OUT-002`) |
| `tests/Feature/Wwhn198InboundScanValidationTest.php` | `tests/Feature/Wwhn187InboundScanValidationTest.php` | Chỉ đổi tên class/file, không đổi logic |
| `tests/Feature/Wwhn198OutboundPickScanDedupTest.php` | `tests/Feature/Wwhn46OutboundPickScanDedupTest.php` | Trả file về đúng task outbound |
| docblock ghi `WWHN-187` ở `InboundScanConcurrencyBehaviorTest` | `WWHN-188` | Task BE1 "rà soát giao dịch/khoá đồng thời" có key thật là `WWHN-188` |

## 6. Việc còn lại ngoài repo

Xem mục 3 của `WWHN-187_DEFECT_CLOSURE_REGISTER.md`. Không có hạng mục nào chờ thêm code.
