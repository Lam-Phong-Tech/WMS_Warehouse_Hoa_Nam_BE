# WWHN-290 — Source & Traceability: cập nhật nguồn kiểm soát hiện hành

- Review item: `DEV290-P1-06` — *"Parent `WWHN-77` vẫn dùng BRD v3.9, candidate dùng current baseline khác. BA/BAS cập nhật current controlled source + supersession; không để repo artifact tự đổi baseline"*
- Owner ký: **BA/BAS** (nội dung nguồn) + **PM** (phê duyệt hiệu lực)
- Người soạn: DEV/BE2 — **DEV không tự ký**, và DEV **không sửa** bất kỳ tài liệu baseline nào
- Machine-readable: `WWHN-290_PINS.json` → `source_traceability`
- Verifier: `bash scripts/verify-wwhn290-closure.sh` (mục 9)

## 1. Sai lệch được ghi nhận

`WWHN-77` (parent) khai nguồn là **BRD v3.9**. Gói closure của WWHN-290 lại được đối chiếu với baseline hiện
hành **BRD v3.20 + SRS/FRD v1.12**. Hai giá trị này khác nhau, nên chuỗi truy vết
`Contract Scope → BRD → SRS Requirement → AC → TC → Jira evidence` bị đứt tại mắt đầu tiên.

Đây **không** phải lỗi của repo: bản thân baseline hiện hành đã nói rõ v3.9 là bản cũ. Thứ còn thiếu là
**cập nhật trường nguồn trên Jira** và một bản ghi supersession có chủ sở hữu.

## 2. Nguồn kiểm soát hiện hành (authority, trích nguyên văn baseline)

| Loại nguồn | Giá trị hiện hành | Căn cứ |
|---|---|---|
| Business source | **BRD Hoa Nam v3.20** (`HN-BRD-WM-PV v3.20`) | SRS/FRD v1.12 §0.1 *Source documents*: "BRD Hoa Nam v3.20 - controlling business candidate"; §1.3 Source Registry `SRC-002` |
| Requirement source | **SRS/FRD v1.12** (`HN-SRS-FRD-001`, cập nhật 14/08/2026) | SRS/FRD v1.12 §0.1 Document Control |
| Contract companion | **HN_OpenAPI_v1_7.yaml** (`OpenAPI 1.7.0-ba`) | SRS/FRD v1.12 §1.6 |
| Screen baseline | Wireframe MVP ALIGNED **v3.20 / v1.12** (14/08/2026) | file `HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` |
| Data baseline | ERD/DD hiện hành (`HoaNam_ERD_Moi_Nhat.md`) | SRS §7 + ERD |

**Supersession đã tồn tại sẵn trong baseline** — SRS/FRD v1.12 §1.6 *Supersession Register* có đúng dòng:

| Old source/statement | New controlling source/decision | Status |
|---|---|---|
| BRD v3.7 | BRD v3.20 | HISTORICAL / SUPERSEDED |
| **BRD v3.9** | **BRD v3.20** | **HISTORICAL / SUPERSEDED** |
| BRD v3.10 / v3.11 / v3.15 | BRD v3.20 | HISTORICAL / SUPERSEDED |
| Các SRS/FRD revision trước | SRS/FRD v1.12 | SUPERSEDED |

Nghĩa là: **không cần quyết định mới**. BA/BAS chỉ cần *ghi nhận* điều baseline đã nói, vào đúng trường
`Source & Traceability` của `WWHN-77` và `WWHN-290`.

## 3. Việc BA/BAS cần làm (chỉ cập nhật Jira, không sửa file)

| # | Hành động | Nơi thực hiện |
|---|---|---|
| 1 | Sửa trường nguồn `BRD v3.9` → `BRD v3.20` | `WWHN-77` |
| 2 | Ghi nguồn requirement `SRS/FRD v1.12 (HN-SRS-FRD-001, 14/08/2026)` | `WWHN-77`, `WWHN-290` |
| 3 | Ghi supersession `BRD v3.9 → BRD v3.20 (HISTORICAL/SUPERSEDED, theo SRS v1.12 §1.6)` | comment trên `WWHN-77` |
| 4 | Ghi mốc phê duyệt: BRD tại `WWHN-333`, SRS tại `WWHN-334` (exact attachment/hash ghi tại chính hai ticket đó) | `WWHN-77` |

Trạng thái hiệu lực formal vẫn theo baseline: BRD v3.20 chỉ effective khi `WWHN-333` duyệt đúng
attachment/hash, SRS v1.12 chỉ effective khi `WWHN-334` DONE. Tài liệu này **không** tự tuyên bố hiệu lực
thay hai ticket đó.

## 4. Nguyên tắc: repo không được tự đổi baseline

Bản sao tài liệu trong repo là **bản làm việc**, không phải nguồn kiểm soát. Do đó:

- DEV không sửa `docs/HN_SRS_FRD_v1.12_FULL.md`, `docs/HoaNam_ERD_Moi_Nhat.md`,
  `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` để "khớp" với ticket.
- WWHN-290 chạm **0** file baseline (kiểm bằng verifier mục 4/4b).
- Định danh baseline được pin bằng **live marker** (`VERSION 1.12`, `HN-SRS-FRD-001`, dòng supersession
  `BRD v3.9 → BRD v3.20`) chứ không bằng `sha256` tuyệt đối: các bản sao này đang được ticket khác chỉnh
  (ví dụ WWHN-310 đang cập nhật ERD tại thời điểm viết), nên một pin hash sẽ mục ngay trong ngày. Hash chỉ
  được ghi ở `WWHN-290_PINS.json` dưới dạng **lineage tại thời điểm pin**.

### 4.1 Sai lệch được phát hiện khi đối chiếu (báo cáo, không tự sửa)

`docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` tồn tại **hai bản khác nhau** ở hai repo:

| Repo | Khác biệt quan sát được |
|---|---|
| BE | có dòng `Document ID: HN-WMS-TECH-GUIDE-001`; ví dụ `VITE_API_BASE_URL` ghi cổng `8000` |
| FE | thiếu dòng Document ID; ví dụ ghi cổng `8080` + ghi chú "luôn kết thúc ở `/api/v1`"; có thêm mục verify của WWHN-296/WWHN-236 |

Đây là **doc drift giữa hai repo**, thuộc quyền BA/BAS xử lý (phát hành lại một bản kiểm soát duy nhất).
WWHN-290 **không** tự hợp nhất hai bản — làm vậy đúng là "repo artifact tự đổi baseline", điều mà chính
`DEV290-P1-06` cấm. Ghi nhận ở đây để BA/BAS có đầu vào quyết định.

## 5. Ô ký

| Vai trò | Tên | Ngày | Kết luận |
|---|---|---|---|
| BA/BAS | | | `PENDING` |
| PM | | | `PENDING` |
