# WWHN-187 — Đóng xung đột nguồn của task cha WWHN-42 (`DEV187-P1-06`)

## 1. Xung đột

- Task cha `WWHN-42` (Source & Traceability) vẫn ghi **BRD v3.9**.
- Ghi chú governance của `HN-MC-001` nói **v3.11 controls / v3.9 superseded**.
- Hai phát biểu này mâu thuẫn nhau, và **cả hai đều sai so với baseline hiện hành**.

## 2. Nguồn sự thật

`HN_SRS_FRD_v1.12_FULL.md` §Supersession (bảng "revision cũ → revision điều khiển") ghi
rõ, trong cùng một bảng:

| Revision cũ | Bị thay bởi | Trạng thái |
|---|---|---|
| BRD v3.7 | BRD v3.20 | HISTORICAL / SUPERSEDED |
| **BRD v3.9** | **BRD v3.20** | **HISTORICAL / SUPERSEDED** |
| BRD v3.10 | BRD v3.20 | HISTORICAL / SUPERSEDED |
| **BRD v3.11** | **BRD v3.20** | **HISTORICAL / SUPERSEDED** |
| BRD v3.15 | BRD v3.20 | HISTORICAL / SUPERSEDED |
| Handoff v3.10 reference | Removed; dùng BRD v3.20 + WWHN-333 và SRS v1.12 + WWHN-334 | REMOVED / NO ARTIFACT |

Và §Source Registry: *"Source Registry dùng BRD v3.20 và SRS/FRD v1.12; các revision cũ
chỉ dùng truy vết lịch sử."* (`P0-03`, trạng thái CLOSED).

## 3. Kết luận

**Không phải chọn giữa v3.9 và v3.11 — cả hai đều đã bị thay thế.** Nguồn điều khiển
hiện hành là:

| Loại nguồn | Revision điều khiển | Ghi chú trạng thái theo SRS |
|---|---|---|
| Business | **BRD Hoa Nam v3.20** (`HN-BRD-WM-PV v3.20`) | CONTROLLING BUSINESS SOURCE CANDIDATE — formal effective khi `WWHN-333` duyệt đúng attachment/hash |
| Requirement | **SRS/FRD v1.12** | Formal effective khi `WWHN-334` duyệt đúng attachment/hash + revision `HN_OpenAPI_v1_7.yaml` |
| API contract | **HN_OpenAPI_v1_7.yaml** (67 operations) | Current contract candidate |
| Data | ERD hiện hành trong repo (`docs/HoaNam_ERD_Moi_Nhat.md`) | Nguồn schema thi hành |

Chữ "candidate" ở trên là **trạng thái phê duyệt**, không phải chỗ để quay về v3.9/v3.11:
v3.20/v1.12 vẫn là revision *điều khiển* cho mọi công việc kỹ thuật, còn `WWHN-333`/
`WWHN-334` chỉ gắn dấu chính thức lên đúng bản hash.

## 4. Hành động cần làm trên Jira (không phải việc của repo)

1. Sửa trường **Source & Traceability của `WWHN-42`**: `BRD v3.9` → `BRD v3.20 + SRS/FRD
   v1.12`, kèm dòng supersession tường minh *"v3.9 và v3.11 HISTORICAL/SUPERSEDED theo
   SRS v1.12 §Supersession"*.
2. Sửa cùng cách cho ghi chú governance của `HN-MC-001` (đang nói v3.11 controls) — xem
   `WWHN-187_MASTER_DATA_BASELINE.md`.
3. **Không** sửa các evidence lịch sử đã ghi tại thời điểm cũ; chỉ thêm dòng trỏ tới
   supersession này để giữ tính xác thực của bằng chứng đã chạy.

## 5. Ảnh hưởng tới kết luận kỹ thuật của WWHN-187

Không có. Toàn bộ rà soát luồng quét nhập được đối chiếu trực tiếp với SRS v1.12
(Annex C dòng `scanInboundDocument`, §8.1/§8.2, `NFR-SRS-001`, `NFR-SRS-004`,
`REQ-SRS-002..005`) và ERD hiện hành — tức là đã dùng đúng revision điều khiển, không
dùng v3.9/v3.11. Việc sửa trường Source & Traceability là dọn governance, không làm đổi
một kết luận kỹ thuật nào.
