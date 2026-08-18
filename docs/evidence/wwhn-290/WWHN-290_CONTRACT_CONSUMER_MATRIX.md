# WWHN-290 — Approved contract source và ma trận consumer

- Review item: `DEV290-P1-03R` — *"Khóa một approved OpenAPI source; pin Postman + QA + FE consumer cùng version/SHA"*
- Owner ký: **Tech Lead** (contract) — FE1 đã có bằng chứng consumer riêng (§4)
- Machine-readable: `WWHN-290_PINS.json` → `contract`, `contract_consumers`
- Verifier: `bash scripts/verify-wwhn290-closure.sh` (mục 3 và mục 8)

## 1. Quyết định khoá nguồn

Hệ thống có **hai tầng contract khác nhau**, và việc trộn hai tầng chính là nguồn gốc của tranh cãi
"`1.0.0` hay `2.0.0`". Quyết định khoá như sau:

| Tầng | Artifact | Version | Ai sở hữu | Vai trò trong WWHN-290 |
|---|---|---|---|---|
| Tài liệu (BA) | `HN_OpenAPI_v1_7.yaml` (attachment Jira, không nằm trong repo) | `1.7.0-ba` | BA — duyệt qua `WWHN-334` | **Baseline yêu cầu**; SRS/FRD v1.12 §1.6 ghi rõ `CURRENT = HN_OpenAPI_v1_7.yaml / OpenAPI 1.7.0-ba / SRS v1.12` |
| Hiện thực (BE) | `storage/api-docs/api-docs.json` trên `m1` | spec `3.0.0` / `info.version` **`2.0.0`**, namespace `/api/v1` | Tech Lead / BE | **Approved implementation contract — nguồn duy nhất dùng làm closure evidence** |

Hai tầng **không cạnh tranh nhau**: một bên là yêu cầu, một bên là bề mặt được sinh ra từ danh sách route
Laravel thật và được `SwaggerRouteSyncTest` chốt. Mọi so sánh version phải nói rõ đang ở tầng nào.

## 2. Disposition cho từng artifact contract khác trong repo/nhánh

Reviewer thấy nhiều số version cùng tồn tại. Không xoá gì — chỉ nói rõ cái nào **không** phải nguồn duyệt:

| Artifact | Version | Status |
|---|---|---|
| `storage/api-docs/api-docs.json` @ `m1` | `2.0.0` | **APPROVED IMPLEMENTATION CONTRACT** |
| cùng file @ `m1-be-2` (`60816499066f9f493596e326dc41116ef32f5c4b`) | `1.0.0` | HISTORICAL — revision cũ của **chính file đó** trên commit ancestor; không phải contract cạnh tranh, không dùng làm evidence |
| `docs/api-contract/releases/WWHN-155/1.0.0/openapi.yaml` | `1.0.0` | RELEASE PACK LỊCH SỬ của WWHN-155; đóng băng theo ticket đó |
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/openapi.yaml` | `1.3.0-proposed` | PROPOSED, chưa duyệt; không dùng cho WWHN-290 |
| `docs/api-contract/WWHN-1xx-*.yaml|json` (per-ticket) | theo ticket | LINEAGE per-ticket; nguồn duyệt vẫn là file ở dòng 1 |

## 3. Ma trận consumer được pin

Mọi consumer bên dưới bị ràng buộc vào **cùng một namespace `/api/v1` và cùng `info.version 2.0.0`**:

| # | Consumer | Artifact | Invariant được kiểm tự động |
|---|---|---|---|
| C1 | Postman — contract collection | `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | mọi request nằm dưới `api/v1`, trừ đúng một ngoại lệ nền tảng `api/health` |
| C2 | Postman — môi trường Local | `docs/postman/WMS_HoaNam - Local.postman_environment.json` | `baseUrl` kết thúc ở `/api/v1` |
| C3 | Postman — môi trường CI | `docs/postman/WMS_HoaNam - CI.postman_environment.json` | `baseUrl` là origin (không kèm path), namespace nằm trong request path của collection |
| C4 | QA — scenario collection | `docs/postman/WMS_HoaNam API Scenario Testing - TEST.postman_collection.json` | chạy trên `baseUrl` đã kết thúc ở `/api/v1` (C2) |
| C5 | QA — regression suite | xem `WWHN-290_QA1_REGRESSION_HANDOFF.md` | chạy tại `frozen_candidate.sha` |
| C6 | FE consumer | repo FE `m1`, xem `fe_consumer.revision_reviewed` trong `WWHN-290_PINS.json` | `src/shared/config/env.ts` ép `VITE_API_BASE_URL` kết thúc đúng ở `/api/v1`; verifier FE `node scripts/verify-wwhn-290-consumer.mjs` |

Ngoại lệ `api/health` là **cố ý**: endpoint health nằm ngoài namespace nghiệp vụ theo thiết kế, và FE khai
riêng bằng `VITE_API_HEALTH_PATH`. Ghi ở đây để nó không bao giờ bị hiểu nhầm là lệch contract.

## 4. Bằng chứng phía FE

FE ghi nhận độc lập tại `WMS_Warehouse_Hoa_Nam_FE/docs/evidence/wwhn-290/WWHN-290_FE_CONSUMER_CONFIRMATION.md`:
FE chỉ tiêu thụ `2.0.0` / `/api/v1`; invariant nằm trong mã (`CANONICAL_API_BASE_PATH`), không phải trong lời
văn; phạm vi WWHN-290 đổi 0 file contract nên **không có breaking change** buộc FE thích ứng.

Verifier FE (`yarn wwhn-290:verify`) còn đối chiếu chéo `WWHN-290_PINS.json` của repo BE khi hai repo nằm
cạnh nhau, nên pin lệch giữa hai repo làm đỏ build thay vì âm thầm sai.

## 5. Vì sao pin bằng invariant chứ không bằng blob SHA

`m1` là nhánh dùng chung. Trong đúng một ngày, `storage/api-docs/api-docs.json` đã đổi blob **hai lần**
(WWHN-328 publish trường data-scope cho dashboard) trong khi `info.version` vẫn `2.0.0`; tại thời điểm viết
tài liệu này, một phiên khác đang regenerate cả file contract lẫn `docs/postman/…DEV.postman_collection.json`
cho WWHN-309. Một pin blob tuyệt đối sẽ mục trong vài giờ và làm evidence **trông như bịa** trong khi không
có gì sai.

Vì vậy WWHN-290 pin hai loại điều kiện đúng vĩnh viễn:

1. **Live invariant** — kiểm vào file thật: `openapi 3.0.0`, `info.version 2.0.0`, namespace `/api/v1`,
   và các invariant consumer ở §3.
2. **Range invariant** — phạm vi commit của WWHN-290 chạm `storage/api-docs/**` và `docs/postman/**`
   **0 lần**; điều này đúng mãi mãi bất kể ticket khác làm gì sau đó.

## 6. Điều kiện vô hiệu

Khoá contract này hết hiệu lực nếu: `info.version` trên `m1` khác `2.0.0`; hoặc một consumer ở §3 trỏ sang
namespace khác; hoặc BA phát hành revision `HN_OpenAPI_v1_x` mới được duyệt ở `WWHN-334` làm đổi bề mặt
`/api/v1`. Verifier đỏ ở đúng mục tương ứng khi điều đó xảy ra.
