# WWHN-241 — Bằng chứng tự động bổ sung cho `QA1_RUNBOOK_WWHN-241.md`

Bổ sung cho `DEV240-P1-04R`. **KHÔNG thay thế** yêu cầu QA1 review/executed thủ công 2 case
còn GAP thật ở cuối file này (concurrent request + rollback injection) và ảnh chụp FE (§5.4
runbook gốc) — những phần đó cần con người/hạ tầng multi-process thật, không tự động hoá an
toàn được trong phạm vi phiên làm việc này.

Vòng 1 (khảo sát ban đầu, HEAD `e02903f`): map 20 case → 12 PASS đầy đủ, 5 PASS một phần,
3 GAP thật. Vòng 2 (đóng gap, HEAD `4636733`): đã đóng thêm 5/5 case PASS-một-phần +
1/3 GAP (QA240-14). Bảng dưới đây là kết quả CUỐI CÙNG sau vòng 2.

## Tổng kết: 19/20 case có bằng chứng tự động thật (vòng 3 đóng nốt QA240-19/20), 1 mục ngoài phạm vi kỹ thuật (ảnh chụp FE)

| Case | Trạng thái | Bằng chứng |
|---|---|---|
| QA240-01..09 | PASS đầy đủ | `Wwhn240ReversalNetEffectContractTest` |
| QA240-10 | PASS đầy đủ (đã siết ở vòng 2) | `test_trace_source_movement_khong_ton_tai_tra_404` — nay assert `error.code=RESOURCE_NOT_FOUND` + envelope chuẩn |
| QA240-11 | PASS đầy đủ (đã siết ở vòng 2) | `test_trace_source_ngoai_warehouse_scope_bi_tu_choi_403` — nay assert không rò rỉ `correction_chain`/`nodes`/`net_effect` |
| QA240-12, 13 | PASS đầy đủ | `Wwhn240ReversalNetEffectContractTest` |
| QA240-14 | PASS đầy đủ (mới thêm ở vòng 2) | `test_inventory_xnt_report_requires_report_view_permission` (mới) |
| QA240-15 | PASS đầy đủ | `Wwhn254ReportEvidenceTest` |
| QA240-16, 17 | PASS đầy đủ (đã siết ở vòng 2) | `Wwhn239ReversalItemLevelClosureTest` — nay gọi lại `trace-source` sau replay/conflict, assert `node_count` không đổi |
| QA240-18 | PASS đầy đủ (đã sửa tài liệu ở vòng 2) | Mã lỗi thật là `REVERSAL_QTY_EXCEEDED` (đã sửa kỳ vọng sai trong runbook gốc — xem ghi chú tại dòng 84-92 của `QA1_RUNBOOK_WWHN-241.md`) |
| **QA240-19** | **PASS đầy đủ (đóng vòng 3 — 19/08)** | Race HTTP thật tự động hoá bằng `curl_multi` trong MỘT process (không cần spawn tiến trình song song từ tool): `scripts/wwhn240-qa19-http-race.php` 25/25 checks PASS trên PG 16.14 — statuses `[200,409]`, loser `REVERSAL_ITEM_ALREADY_REVERSED`, chain 2 node không trùng, XNT↔trace↔ledger khớp, RBAC 403. Bổ trợ tầng service: `scripts/wwhn239-reversal-item-race.php` (2 OS process, 2 backend_pid) PASS. Raw: `QA240_19_20_CLOSURE_20260819.md` + `runtime-qa19/` |
| **QA240-20** | **PASS đầy đủ (đóng vòng 3 — 19/08)** | `Wwhn240ForcedRollbackInjectionTest` — fault-injection `QueryExecuted` đúng tại INSERT movement REVERSAL (pattern Wwhn234 G-01): 500 sạch, reversal_document/reversal_line/movement/balance/posting_event/item_status/chain/XNT/ledger đều nguyên trạng, recovery cùng key thành công. PASS sqlite + pgsql (48 assertions mỗi driver). Raw: `QA240_19_20_CLOSURE_20260819.md` |
| §5 mục 4 runbook gốc (ảnh chụp FE) | Ngoài phạm vi BE | Cần chạy FE+BE thật, thuộc phần review thủ công của QA1 |

## Lệnh đã chạy thật — vòng 2 (BE, driver sqlite mặc định, HEAD `4636733`)

```
php vendor/bin/phpunit --filter Wwhn240ReversalNetEffectContractTest
→ OK (15 tests, 130 assertions)

php vendor/bin/phpunit --filter Wwhn239ReversalItemLevelClosureTest
→ OK (8 tests, 99 assertions)

php vendor/bin/phpunit --filter Wwhn254ReportEvidenceTest
→ OK (8 tests, 46 assertions)
```

## Việc còn lại cho QA1 (vòng 3 đã thu hẹp xuống 1 mục)

1. ~~QA240-19~~ — ĐÃ ĐÓNG 19/08 bằng race HTTP thật tự động (xem bảng trên + `QA240_19_20_CLOSURE_20260819.md`); QA1 chỉ cần review evidence, chạy lại được bằng lệnh trong `QA240_19_20_CLOSURE_20260819.md` nếu muốn tái lập độc lập.
2. ~~QA240-20~~ — ĐÃ ĐÓNG 19/08 bằng `Wwhn240ForcedRollbackInjectionTest` (sqlite + pgsql); tái lập: `php vendor/bin/phpunit tests/Feature/Wwhn240ForcedRollbackInjectionTest.php`.
3. Ảnh chụp FE tab XNT + TRACE-01 theo mục 5.4 runbook gốc — mục DUY NHẤT còn cần thao tác người thật (phần render đã có test mount `src/test/wwhn-240-xnt-reversal-columns.test.tsx` bên FE).

Không cần QA1 re-run 19 case còn lại — đã có bằng chứng test tự động PASS thật ở trên,
chỉ cần review + ký xác nhận.
