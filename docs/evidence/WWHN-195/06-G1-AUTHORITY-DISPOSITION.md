# WWHN-195 — G1 Authority disposition (DEV195-P1-04R2)

| Trường | Giá trị |
|---|---|
| Register item | DEV195-P1-04R2 — *"WWHN-40 / HN-PIN-06 / WWHN-334 · Không current G1 disposition"* |
| Yêu cầu | Review exact current API surface + OpenAPI + source revision, ghi **GO / Conditional Go** hoặc required changes |
| Người soạn | DEV (BE1) — trình BA + Tech Lead ký |
| Ngày | 19/08/2026 |

---

## 1. Ba dependency mà register nêu — trạng thái thật trong repo

| Reference | Tìm thấy? | Ghi nhận |
|---|---|---|
| **WWHN-40** | Không có tài liệu trong repo | Không có file `WWHN-40*` ở `docs/docs_task_jira/` (BE) lẫn `docs/Task_docs_Jira/` (FE). |
| **HN-PIN-06** | Không có tài liệu trong repo | Chỉ xuất hiện dưới dạng chuỗi dependency trong chính `WWHN-195.md`. |
| **WWHN-334** | Có, gián tiếp | SRS/FRD v1.12 khai WWHN-334 là **cơ chế phê duyệt formal**: *"PM/PO phê duyệt đúng attachment SRS/FRD v1.12 và HN_OpenAPI_v1_7.yaml revision/hash tương ứng bằng WWHN-334 transition/comment"*, và *"Effective date = ngày transition WWHN-334 sang DONE"*. |

**Hệ quả:** G1 **không thể** ký ở trạng thái "GO tuyệt đối" bằng chứng cứ nội bộ repo, vì chính authority tối cao (SRS v1.12) đang tự khai mình là **REVISION APPROVAL CANDIDATE / READY FOR FORMAL REVIEW** và phụ thuộc WWHN-334 chưa DONE. Đây là ràng buộc của baseline, không phải thiếu sót của WWHN-195.

## 2. Đối chiếu bề mặt API hiện tại với authority

Nguồn đối chiếu: `php artisan route:list --json` (14 operation dưới `/outbound-documents`, xem `01-runtime-route-surface.json`) so với SRS v1.12 §8.1 + Annex C.

| Điều khoản authority | Bề mặt hiện tại | Kết luận |
|---|---|---|
| §8.1 *"Confirm creates exactly one outbound DRAFT; **no direct POST /outbound-documents create operation**"* | Không có route `POST /outbound-documents`, không có `PATCH /outbound-documents/{id}`. Phiếu chỉ sinh từ `POST /outbound-request-imports/{id}/confirm`. | **KHỚP** |
| Annex C `POST /outbound-documents/{id}/scans` | Có, cùng handler với alias lịch sử `/scan`. | **KHỚP** |
| Annex C `PUT /outbound-documents/{item_id}/rfid` | Có, ở dạng `{itemId}`. Khác cách viết tham số, không khác semantic. | **KHỚP (khác cách đặt tên tham số)** |
| Annex C `POST /outbound-documents/{id}/post-issue` | Có, gate đủ: full match + đủ tồn + label PRINTED + snapshot current + label-applied. | **KHỚP** |
| Annex C `POST /outbound-documents/{id}/reversals` | Có, cùng handler với alias `/reverse`. | **KHỚP** |
| §5.2/§6 — 4 operation Packing Label ở mức label | Có đủ preview / ready / print / reprint dưới `/packing-labels/{labelId}`. | **KHỚP** |
| §11 *"active legacy createOutboundDocument và legacy per-Item-label operations = 0"* | createOutboundDocument = 0; per-item label = 0 (đã xoá `createAndDispatchLabel`). | **KHỚP** |
| §8.1 Enforcement standard *"exact x-permission + data scope"* | Cả 14 operation đều có permission + `EnforceOutboundWarehouseScope`. | **KHỚP** |
| DEC-RFID-FALLBACK-001 — RFID không phải gate | `rfid_required=false`, không có `ITEM_RFID_REQUIRED` trong luồng xuất. | **KHỚP** |
| REQ-SRS-005 / DEC-CAMERA-001 — camera + manual fallback | `scan_source` tường minh CAMERA/MANUAL/HARDWARE; mode per-event, không khoá. | **KHỚP** (mới đóng ở R2) |

**Sai lệch còn lại:** cách viết tham số path (`{itemId}`/`{labelId}` ở runtime vs `{item_id}`/`{label_id}` ở Annex C). Không đổi semantic, không đổi permission, không đổi payload. Ghi nhận là **cosmetic**, cần một dòng disposition của BA nếu muốn đồng bộ tuyệt đối cách đặt tên.

## 3. Revision đang được đối chiếu

| Artifact | Revision dùng để review |
|---|---|
| SRS/FRD | `docs/HN_SRS_FRD_v1.12_FULL.md` — v1.12, ngày 14/08/2026, status *REVISION APPROVAL CANDIDATE* |
| ERD | `docs/HoaNam_ERD_Moi_Nhat.md` — bảng 25 `scan_events`, bảng 37 `outbound_requests` |
| Contract task | `docs/api-contract/WWHN-195-outbound-openapi.yaml` — **v2.0.0-R2**, 14 operation |
| Route runtime | `01-runtime-route-surface.json` |
| Source | nhánh `m1`, commit ghi trong `07-EXACT-SHA-PIN.md` |

## 4. DISPOSITION

> ### CONDITIONAL GO
>
> Bề mặt API xuất kho hiện tại **khớp toàn bộ điều khoản của SRS/FRD v1.12 §5.2/§6/§8.1 + Annex C** áp dụng cho HN-OUT-001, và mọi lệch mà register R2 nêu đã được đóng bằng code + bằng chứng chạy lại được.
>
> Điều kiện còn treo **không thuộc phạm vi WWHN-195** và không do WWHN-195 gây ra:
>
> 1. **WWHN-334 chưa DONE** → SRS v1.12 và HN_OpenAPI_v1_7.yaml vẫn là *candidate*, chưa có exact-hash phê duyệt. Mọi "GO tuyệt đối" đều phải chờ transition này (chính SRS quy định).
> 2. **WWHN-40 / HN-PIN-06 không có trong repo** → BA cần cung cấp hoặc chính thức tuyên bố hai reference này đã được supersede bởi SRS v1.12; nếu không, phần "dependency đã thoả" chỉ suy ra được từ code chứ không từ tài liệu.
> 3. **Cosmetic** — đồng bộ cách viết tham số path (`{itemId}` vs `{item_id}`): giữ nguyên hay đổi là quyết định của BA/Tech Lead; đổi sẽ là breaking change cho client hiện có.

**Không có required change nào thuộc trách nhiệm BE1 còn treo.** Ba điều kiện trên là điều kiện tài liệu/authority, cần BA + Tech Lead + PM/PO xử lý.
