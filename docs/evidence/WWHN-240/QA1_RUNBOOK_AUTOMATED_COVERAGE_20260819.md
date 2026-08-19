# WWHN-241 — Bằng chứng tự động bổ sung cho `QA1_RUNBOOK_WWHN-241.md`

Bổ sung cho `DEV240-P1-04R`. **KHÔNG thay thế** yêu cầu QA1 review/executed thủ công 2 case
còn GAP thật ở cuối file này (concurrent request + rollback injection) và ảnh chụp FE (§5.4
runbook gốc) — những phần đó cần con người/hạ tầng multi-process thật, không tự động hoá an
toàn được trong phạm vi phiên làm việc này.

Vòng 1 (khảo sát ban đầu, HEAD `e02903f`): map 20 case → 12 PASS đầy đủ, 5 PASS một phần,
3 GAP thật. Vòng 2 (đóng gap, HEAD `4636733`): đã đóng thêm 5/5 case PASS-một-phần +
1/3 GAP (QA240-14). Bảng dưới đây là kết quả CUỐI CÙNG sau vòng 2.

## Tổng kết: 17/20 case có bằng chứng tự động thật, 2/20 GAP thật, 1 mục ngoài phạm vi kỹ thuật

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
| **QA240-19** | **GAP thật** | Không có test/script race đa-tiến-trình thật cho endpoint reversal trong repo. Đã thử viết bổ sung trong phiên này (theo pattern race PG đã có cho các luồng khác) nhưng bị chặn bởi permission classifier của công cụ khi cố spawn tiến trình song song — cần QA1 tự dựng 2 request đồng thời qua HTTP thật + theo dõi DB |
| **QA240-20** | **GAP thật** | Không có cơ chế fault-injection sẵn cho bước sau-movement-trước-commit; cùng lý do trên chưa tự động hoá được trong phiên này — cần QA1 thủ công (hoặc phối hợp DEV dựng fault-injection có kiểm soát) |
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

## Việc còn lại cho QA1 (đã thu hẹp từ 20 case xuống 3 mục cụ thể)

1. QA240-19 — tự dựng 2 request `POST reverse` đồng thời cùng movement qua HTTP thật, xác nhận 1 thành công/1 bị từ chối (409/412 theo `ConflictCode`), chain không trùng node.
2. QA240-20 — inject lỗi thủ công (hoặc phối hợp DEV dựng fault-injection có kiểm soát) sau bước ghi movement, xác nhận rollback sạch (chain/XNT trở về nguyên trạng).
3. Ảnh chụp FE tab XNT + TRACE-01 theo mục 5.4 runbook gốc.

Không cần QA1 re-run 17 case còn lại — đã có bằng chứng test tự động PASS thật ở trên
(commit `4636733`), chỉ cần review + ký xác nhận.
