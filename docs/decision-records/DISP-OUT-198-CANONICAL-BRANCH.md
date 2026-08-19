# DISP-OUT-198 — Disposition nhánh canonical và sửa trace sai của WWHN-198

- Ticket: WWHN-198 (register DEV198-P1-01R)
- Ngày: 2026-08-19
- Owner: DEV (BE2) → cần PM/Tech Lead xác nhận, QA1 kiểm chứng

## 1. Nhánh canonical

**`m1` là nhánh canonical hiện hành. `m1-be-2` là tiền tố lịch sử đã bị `m1` bao trọn.**

Đây là dữ kiện kiểm chứng được, không phải lựa chọn:

```
git merge-base --is-ancestor origin/m1-be-2 origin/m1   -> true
git rev-list --left-right --count origin/m1-be-2...origin/m1 -> 0   719
```

`m1-be-2` **0 commit** đi trước `m1`; `m1` **719 commit** đi trước `m1-be-2`. Vì
`m1-be-2` là ancestor thuần, không có nội dung nào của nó bị mất khi lấy `m1` làm
nguồn — nên đây **không phải một quyết định thay thế nhánh** cần governance mới, mà là
việc ghi nhận đúng quan hệ đang tồn tại. (Cùng kết luận đã ghi ở WWHN-290.)

Hệ quả bắt buộc: mọi evidence/PR/SHA của WWHN-198 pin theo `m1`. Không tạo nhánh riêng.
Nếu về sau có ai muốn `m1-be-2` trở lại làm canonical thì lúc đó mới cần Jira governance
comment/Decision — vì khi đó mới thật sự là thay thế.

## 2. Sửa false trace "WWHN-198 là task inbound"

| Điểm sai | Trạng thái đúng |
|---|---|
| File test từng mang tên `Wwhn198OutboundPickScanDedupTest.php`, đặt theo key tạm dùng lúc viết — mà key tạm đó thực chất trỏ task INBOUND "[DEV] Triển khai quét/ghi nhận/validation" (HN-IN-002) | Task inbound đó là **WWHN-187** (`docs/docs_task_jira/WWHN-187.md`, `tests/Feature/Wwhn187InboundScanValidationTest.php`) |
| WWHN-198 bị hiểu nhầm là inbound | WWHN-198 = "[DEV] Triển khai lấy/quét hàng/dedup/availability", **OUTBOUND**, Parent Temp Key `HN-OUT-002`, task cha `WWHN-46`, Epic `HN-EPIC-06`, role BE2 |
| Artifact test mang số hiệu gây nhầm | Đã đổi tên thành `tests/Feature/Wwhn46OutboundPickScanDedupTest.php` (đặt theo **task cha thật** WWHN-46/HN-OUT-002), docblock ghi rõ lịch sử đổi tên để trace không đứt |

## 3. Map Jira 198 → artifact → test/build

| Jira | Artifact sản phẩm | Test/evidence |
|---|---|---|
| WWHN-198 (BE2, HN-OUT-002) | Không sửa code sản xuất ở vòng 1 (rà soát); vòng 2 (bản này) sửa **contract** OpenAPI Packing Label v1.2.0 và bổ sung guard | `tests/Feature/Wwhn46OutboundPickScanDedupTest.php` (10 case), `Wwhn198PackingLabelContractAlignmentTest.php` (4), `Wwhn198ReservedQtySemanticsTest.php` (4), `Wwhn198OutboundReservationRuntimeRaceTest.php` (PG gate) |
| WWHN-199 (BE1, cùng task cha) | Tầng reservation/concurrency | `tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php` |
| WWHN-46 (task cha) | Luồng lấy hàng/quét mã/chống trùng | Hợp của hai bộ trên + race PostgreSQL đã thực thi |

Exact SHA của candidate nằm ở `docs/evidence/WWHN-198/WWHN-198_CLOSURE_REGISTER.json`
(trường `candidate`), được `scripts/verify-wwhn-198-closure.mjs` kiểm tra lại.

## 4. Cần xác nhận

- **PM/Tech Lead**: xác nhận mục 1 (m1 canonical, m1-be-2 là ancestor thuần nên không
  cần governance thay nhánh) và mục 2 (trace đã sửa đúng).
- **QA1**: dùng đúng nhánh `m1` + exact SHA ở register khi chạy QA execution.
