# UAT_SCAN_v3 — BE handoff TC02, TC04, TC07

Phạm vi duy nhất: UAT_SCAN_v3 `1fyH-UWHZEWMp3-Hh-Jw6L34aZueKnb3NkeHxB4ot200`

Ngày đo live: **29/10/2026, Asia/Ho_Chi_Minh**

Môi trường: `https://khohoanamdev.lptech.info.vn` — `dev-test`

## Release identity

- Source triển khai chức năng: `9f49fed97c2217f51ce198e9ea1347c373139019`.
- Operations/fixture promotion: `b26d8bb87773ba530168b5e9cc496fbf65323f10`.
- Runtime trước release handoff này: `d6b1cc60550e7e351ccfa00e95a0a0f0952450d1`.
- Contract: `docs/contracts/tc26-future-evidence-v1.json`.
- Migration additive: `2026_10_26_000001_create_future_event_evidence`, DEV/TEST batch 27, `Ran`.
- Release candidate của handoff được ghi bằng exact SHA trong delivery manifest tạo sau commit/deploy.
- Production activation: `false`.

## TC02 — chức danh lịch sử người tạo thông báo

**Đã đóng cho dữ liệu mới.** BA/owner đã chốt nguồn là chức danh nhân sự tại
thời điểm event, không phải IAM role. Nguồn master là
`user_business_profiles.job_title`; snapshot append-only nằm trong
`notification_source_snapshots`.

Contract đọc:

- `meta.contract=p13-notifications-v1`.
- `meta.projection_revision=p13-notifications-r2`.
- `data.source_snapshot.contract=notification-source-snapshot-v1`.
- List và detail cùng trả `actor_id`, `actor_name_snapshot`,
  `actor_job_title_snapshot`, `warehouse_id`, `warehouse_name_snapshot`,
  `captured_at`, `capture_policy` và `job_title_source`.
- `job_title_source=user_business_profiles.job_title AT_CAPTURE_NOT_CURRENT_IAM_ROLE`.
- Dữ liệu cũ thiếu snapshot giữ `availability=NOT_RECORDED`, các giá trị null;
  không đọc current directory/IAM role để bù.
- GET không đánh dấu read; lượt smoke không gọi PATCH.

Live fixture recipient TEST:

- Recipient ID: `01a176ae-5459-7018-a3ac-cc062fc1d2ca`.
- Subject document: `a87a33e8-c7ae-4b5a-b7eb-d6c09a24b8d3`.
- Actor snapshot: `TC26 TEST Operator` — `Nhân viên kho TEST`.
- Warehouse: `01a06f33-6bff-70b8-a089-3c68a83b11a8`, `Kho tổng Hoa Nam`.
- Captured at: `2026-10-26T05:28:11.272192Z`.
- Authenticated list Request-ID: `0e46c5e3-cc18-4415-af6d-aa02527f7112`.
- Authenticated detail Request-ID: `57756147-36e3-4da2-a998-bd1ef7ed0459`.

## TC04 — CREATED / CHECKED / SENT

**Đã đóng cho fixture tương lai có nguồn persisted.** App đọc
`data.p12.event_evidence`, contract `p12-event-evidence-v2`, từ GET owner detail
hiện hữu. Không dùng timeline v1 `SOURCE_MAPPING_PENDING` để thay projection mới.

Fixture:

- Document ID: `1eb9356e-95bd-4396-a8a3-eb2f8d61b368`.
- Document no: `PX-20261026122815-9DTVEQ`.
- Warehouse ID: `01a06f33-6bff-70b8-a089-3c68a83b11a8`.
- Authenticated detail Request-ID: `d7d5bb91-b425-46b6-a7f4-37e380be2a89`.

Persisted milestones:

| Event | Event ID | Version | occurred_at | Source |
|---|---|---:|---|---|
| CREATED | `d042b644-83a5-45e9-848d-df9c665108bb` | 1 | `2026-10-26T05:28:15.193265Z` | `stock_document_event_evidence.occurred_at` |
| CHECKED | `608686ae-27b6-4025-b0f6-4b4bda2bd824` | 1 | `2026-10-26T05:28:15.726507Z` | explicit human check |
| SENT | `565a55ff-6fb9-4abb-9c6a-6bcd5c25a490` | 2 | `2026-10-26T05:28:17.139085Z` | canonical RECORD owner |

SENT có handoff persisted `mini_app_web_post_handoffs.submitted_at =
2026-10-26T05:28:17.127598Z`. CHECKED là event riêng do người dùng xác nhận,
không suy từ validation RECORD. Actor/name/job-title/description đều là snapshot
tại event. Dữ liệu lịch sử không có event giữ `NOT_RECORDED/null`, không backfill.

Route owner CHECK đã có nhưng App UAT này chỉ đọc evidence đã tồn tại:

- `POST /api/v1/inbound-documents/{id}/checks`
- `GET /api/v1/inbound-documents/{id}/checks/requests/{key}`
- `POST /api/v1/outbound-documents/{id}/checks`
- `GET /api/v1/outbound-documents/{id}/checks/requests/{key}`

Các route cần `auth:api` và cặp quyền view + scan tương ứng. Không gọi lại CHECK
để tạo evidence cho lượt handoff này.

## TC07 — fixture nhập kho owner-approved

Fixture mới, tách khỏi catalog thật:

- Warehouse: `01a06f33-6bff-70b8-a089-3c68a83b11a8` — Kho tổng Hoa Nam.
- QR: `HN1|SKU=TC26-PRODUCT|ITEM=TC26-RECEIPTABLE`.
- SKU ID: `01a176ae-276b-72e8-8910-2c32a864c1e0`.
- SKU: `TC26-PRODUCT`, `PRODUCT`, `ACTIVE`, serial-tracked.
- Tên: `TC26 TEST PRODUCT`.
- Category ID: `0d137d4c-5e42-46d6-ae0f-ed3bd375c954`.
- Đơn vị: `cái`.
- Supplier ID: `fe1a7c6d-ae98-490a-b176-d5c37b7b50ce`.
- Supplier: `TC26 TEST SUPPLIER`, `ACTIVE`, version 1.
- Quantity limit: **1**.
- Item/physical code hiện chưa tồn tại: đúng `NEW_ITEM_CANDIDATE`, chưa nhận kho.

Authenticated resolve:

- `POST /api/v1/mini-app/inbound/resolve-code`.
- Request-ID: `0dc71c62-4de6-4d34-9214-8e1d5b880193`.
- HTTP 200.
- `payload_format=COMPOSITE_V1`, `payload_version=1`.
- `resolution_status=NEW_ITEM_CANDIDATE`.
- `item_type=ITEM`, `requires_quantity=false`.
- `stock_effect=NONE`, `movement_created=false`,
  `scan_evidence_created=false`, `wms_document_created=false`.

Auth owner dùng cho smoke có `inbound.view` + `inbound.scan`, warehouse context
`READY`; phiên đã logout sau smoke và **không được tái sử dụng**.

### Cách App dùng fixture

1. Dùng auth owner hiện tại của App, xác nhận đúng Kho tổng Hoa Nam.
2. Resolve QR trên bằng adapter P04 owner.
3. Tạo **draft mới của chính session App** với supplier fixture và quantity 1.
4. SAVE/scan tối đa đúng một QR, chụp màn Review.
5. Dừng trước RECORD/POST; không thay đổi item status/tồn kho.

Không takeover draft TC26 cũ `54d265f0-4387-4f80-b3a3-1b56f63d4255` vì draft
đó thuộc auth session fixture đã kết thúc.

## Runtime routes / flags

- Notification list/detail: `auth:api`; GET không mutation.
- TC07 resolve: `auth:api`, `permission:inbound.scan`,
  `EnforceInboundWarehouseScope`.
- Effective policy trước handoff: P12=true, P14=true, P11 recovery=false,
  P14 recovery=false, P19=false, single-session replacement=true,
  body-token transport=true.
- Không flag mới cho snapshot notification/event evidence/TC07 resolve.
- Không migration, seed, repair, restore hoặc production write trong lượt đo
  29/10; chỉ auth login/switch-context/logout và GET/resolve read-only.

## Test / audit

- Focused SQLite: **6 tests / 81 assertions PASS**.
- Focused isolated PostgreSQL: **6 tests / 81 assertions PASS**.
- `composer validate --strict`: PASS.
- Composer audit sau update `symfony/mime 7.4.13 → 7.4.22`: 0 advisory.
- npm audit (đủ prod/dev): 0 vulnerability.
- Full CI: **NOT RUN**; hai feature/ops commits gốc dùng `[skip ci]`.
- Không dùng test fixture/exit 0 thay live authenticated evidence; raw HTTP redacted
  nằm trong `docs/evidence/UAT_SCAN_V3_TC02_TC04_TC07_20261029/`.

## Null còn hợp lệ

- TC02 legacy không có snapshot: tên/chức danh/kho/captured_at có thể null với
  `NOT_RECORDED`; fixture này không null.
- TC04 document cũ không có event: event/actor/description/time null với
  `NOT_RECORDED`; fixture này có đủ ba event.
- TC07: `item_id`, `item_status`, `serial_number`, `box_code`, `box_number` null
  vì đây là item candidate chưa nhận; không được App biến thành lỗi nguồn hoặc tự
  tạo item trước RECORD.
