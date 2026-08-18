# QA disposition — `WWHN-235`

| Field | Value |
|---|---|
| QA gate | `WWHN-235` (gate của `WWHN-233`) |
| Trạng thái hiện tại trên Jira | `Blocked` / `Pending`, 0 comment, 0 attachment |
| Disposition đề xuất | **DEFERRED / N/A cho MVP v1.12** |
| Owner | QA1 → BA/TL |
| Căn cứ | `REQ-SRS-037` (Must) + `DISP-STK-233` §8 |

Đóng `DEV233-P1-06`.

---

## 1. Vì sao không phải `Blocked`

`Blocked` hàm ý "sẽ test khi hết vướng". Ở đây không có vướng mắc nào để gỡ: capability mà
`WWHN-235` định test đã bị controlling source **loại khỏi phạm vi MVP**. Giữ `Blocked` tạo ra một
mục tồn đọng vĩnh viễn mà không ai có thẩm quyền đóng, và mỗi vòng rà soát lại tính nó là nợ QA.

`Pending` cũng sai vì lý do tương tự: nó gợi ý rằng chỉ cần chờ build là chạy được.

Trạng thái đúng là **DEFERRED / N/A**, gắn với **một** điều kiện re-open dùng chung với `WWHN-233`
(`DISP-STK-233` §2). Khi bốn điều kiện đó được thoả, `WWHN-235` được rebaseline cùng lúc với
`WWHN-233`, không sớm hơn.

---

## 2. Phạm vi QA thực tế ở MVP v1.12 — negative gate

QA1 **không** chạy duplicate / partial / stale / RBAC trên exact build cho một capability không tồn
tại. Thay vào đó, phạm vi QA của `WWHN-233` là xác nhận capability *không* tồn tại ở mọi tầng:

| # | Kiểm tra | Cách chạy | Kỳ vọng |
|---|---|---|---|
| 1 | Route surface | `php artisan test --filter=Wwhn233CountDeferredGateTest` | 0 route chứa `stocktake` / `adjustment` / `variance` / `recount` / `cycle-count` / `physical-count` |
| 2 | Permission catalog | cùng test trên | 0 permission kiểm kê trong seed và trong baseline contract |
| 3 | End-state schema | cùng test trên | 4 bảng `stocktake_*` vắng mặt sau `migrate` |
| 4 | Movement type | cùng test trên | chỉ `OPENING / RECEIPT / ISSUE / COMPONENT_ISSUE / REVERSAL`; `ADJUST_IN` / `ADJUST_OUT` bị từ chối |
| 5 | Runtime OpenAPI + baseline contract + nguồn | `node scripts/verify-wwhn233-count-disposition.mjs` | 0 path bị cấm; anchor `REQ-SRS-037` còn nguyên; digest nguồn khớp |
| 6 | Hàng rào có thật | cùng verifier (`ANTI-DANGLING`) | mọi artifact được disposition cam kết đều tồn tại |
| 7 | FE route / menu / permission | `npm run wwhn-233:verify` (repo FE) | 0 route, 0 menu, 0 permission kiểm kê |

Kết quả 7 mục trên **là** kết quả QA của `WWHN-233`. Không có kết quả QA dương nào khác được kỳ vọng
ở MVP này.

---

## 3. Khi re-enable

`WWHN-235` quay lại đúng nguyên văn register: duplicate / partial / stale / RBAC trên exact build,
kèm raw API/DB evidence và build SHA. Bộ negative gate ở §2 vẫn giữ nguyên và trở thành regression
suite của lần re-enable đó.

---

## 4. Bản ghi Jira đề xuất cho `WWHN-235`

```text
Disposition: DEFERRED / N/A cho MVP v1.12 (không phải Blocked, không phải Pending)
Căn cứ: REQ-SRS-037 (Must, SRS/FRD v1.12 @ WWHN-334) + DISP-STK-233 §8
Phạm vi QA đã thực hiện: negative gate 7 mục — docs/evidence/WWHN-233/QA_DISPOSITION_WWHN-235.md §2
Evidence: docs/evidence/WWHN-233/SHA256SUMS
Điều kiện re-open: dùng chung với WWHN-233 (DISP-STK-233 §2)
QA1 result/date: <value>
```
