# Phan hoi hai phan UAT - 19/10/2026

Nguon: `BE_2_PARTS_UAT_20261019.zip`, gom `01_TC12_PROJECTION.md`,
`02_TC05_AUTH_EVIDENCE.md`, `README.md`. Khong sua repo FE/app hoac file goc.

## Phan 1 - TC_UAT_12: PARTIAL, da sua source

### Doi chieu hien trang

- P04 da luu `mini_app_inbound_record_metadata` trong transaction RECORD.
- GET typed owner `GET /api/v1/mini-app/inbound/documents/{id}` da tra
  `data.inbound_metadata.supplier_id/supplier_name/supplier_version/recorded_at`
  trong source. Day khong phai bang chung da replay GET authenticated tren runtime.
- GET P12 `GET /api/v1/mini-app/inbound-documents/{id}` va outbound detail
  chua co projection supplier/creator rieng trong source runtime `9d9e8738`.
- `source_reference` van la alias `source_name`, khong phai ten NCC.

### Bo sung additive tren hai GET detail hien huu

Contract: `p12-owner-projection-v1`, revision `p12-owner-projection-r1`.
Schema: `docs/contracts/p12-owner-projection-v1.schema.json`.
Discovery: `meta.p12.owner_projection_contract`; giu
`meta.p12.contract=p12-documents-read-v2`. Khong them vao LIST, WARRANTY,
receipt, scan, attachment, download hoac API ghi.

| Field path | Type/null | Nguon va y nghia |
| --- | --- | --- |
| `data.p12.owner_projection.document_id` | UUID | Canonical business document, khong phai draft/receipt ID |
| `data.p12.owner_projection.warehouse_id` | UUID | Kho persisted cua parent, khong suy tu kho cua caller |
| `data.p12.owner_projection.supplier.id` | UUID/null | Snapshot `supplier_id` gan dung document |
| `data.p12.owner_projection.supplier.name` | string/null | Snapshot `supplier_name`; khong lookup GLOBAL de fill |
| `data.p12.owner_projection.supplier.version` | integer/null | Version NCC da ghi nhan, khong thay bang version hien tai |
| `data.p12.owner_projection.supplier.recorded_at` | RFC3339 UTC Z/null | Timestamp persisted, giu precision co that cua DB |
| `data.p12.owner_projection.supplier.status/name_status` | enum | Phan biet snapshot, legacy thieu nguon, khong chon NCC, source loi, khong ap dung |
| `data.p12.owner_projection.creator.actor_id` | UUID/null | `stock_documents.created_by`, khong phai caller |
| `data.p12.owner_projection.creator.display_name` | null | Snapshot ten luc tao chua duoc luu; khong fill tu danh ba |
| `data.p12.owner_projection.creator.role` | null | Snapshot role luc tao chua duoc luu; khong lay role hien tai |
| `data.p12.owner_projection.creator.current_display_name` | string/null | Nhan danh ba hien tai RIENG, chi khi co `iam.user.view` va entry ACTIVE/khong deleted |
| `data.p12.owner_projection.creator.current_display_name_status` | enum | Khong co quyen: `REDACTED_PII_PERMISSION`; co nhan: `CURRENT_DIRECTORY_LABEL_NOT_HISTORICAL_SNAPSHOT` |

Loai snapshot thieu giu null; `NOT_RECORDED` khong thanh ten current supplier,
title, source_reference, audit JSON hay receipt cua phien khac. Outbound supplier
la `NOT_APPLICABLE`. Khong bat buoc reader phai la creator/old auth session de
doc metadata cua business document ma owner da cho phep.

Recheck parent id/type/deleted_at/kho/quyen ca khi owner payload cache hit.
Projection khong duoc ghi vao cache parent dung chung, nen current name co quyen
khong lot sang reader thieu quyen. Loi optional source duoc danh dau
`SOURCE_UNAVAILABLE`; parent/lines va API tep khong bi bien thanh rong.

### Phan chua the ket luan DONE toan TC12

- Candidate mapping/quyen `iam.user.view` can owner/BA duyet. Day khong phai ky
  APPROVED thay owner. BA can chot UI co chap nhan NHAN HIEN TAI hay bat buoc
  snapshot ten/vai tro luc tao. Neu bat buoc snapshot, hien chua co nguon persisted
  cho legacy; khong the tao lich su bang lookup hien tai hoac backfill.
- Chua deploy source nay; chua authenticated replay GET detail co supplier that,
  chua co Request-ID live cua projection moi. Khong tao upload/RECORD/POST de lay evidence.
- FE doi chieu cung document tren appqr sau khi co release/contract duoc duyet.
  TC12 van PENDING, khong PASS tu source tests.
- Khong sua download/upload vi loi luu web da duoc FE sua; khong khang dinh checksum
  file da tai cua user khi chua duoc cap path/bytes.

## Phan 2 - TC_UAT_05: DONE contract/evidence, UI/device van pending

Ban giao lai file `TC_UAT_05_DEVTEST_RAW_REDACTED.json` (14 response),
contract `auth-session-replacement-v1`, report release va JUnit nguon co san.
Application SHA `9d9e87382eea39deb3f3d660b3955110b526ea3c`, operations SHA
`1b1fb69d93c7a056879333405e7c7b0bddd0481b`. Release `39124139975` SUCCESS,
218 migration, effective policy true. Khong goi day la full CI PASS: release
da skip CI/seed. Khong login/revoke admin, doi config hoac redeploy trong luot nay.

Error envelope chinh thuc tren access/refresh cua family bi thay:

```json
{
  "success": false,
  "message": "[REDACTED]",
  "error_code": "AUTH_SESSION_REPLACED",
  "errors": [],
  "error": { "code": "AUTH_SESSION_REPLACED", "details": [] },
  "meta": { "request_id": "<observed Request-ID>", "timestamp": "<server RFC3339>" }
}
```

Doan tren la schema minh hoa; response THUC da che nam trong raw JSON, khong
phai response moi tao hoac replay trong luot 19/10.

| HTTP/code | Cach phan biet |
| --- | --- |
| 401 `AUTH_SESSION_REPLACED` | Terminal theo family, dung refresh/write, login moi do user chu dong |
| 401 `AUTH_SESSION_REQUIRED` | Policy enabled va legacy JWT khong co sid; can login |
| 401 `AUTH_SESSION_EXPIRED` | Family het han/revoked theo owner, khong phai bang chung replacement |
| 401 `UNAUTHENTICATED` | Generic invalid/expired JWT. Runtime CHUA co code rieng de phan biet expired access voi invalid JWT. Chi dung quy tac refresh single-flight hien co, khong ghi de replacement terminal |
| 403 | Quyen/account/origin, khong tu ket luan bi thay phien |
| 503/network | Khong phai bang chung replacement; giu journal/UNKNOWN |

Scope: ACCOUNT_ALL_CLIENTS, khong mien tru admin/device. Login hoan tat moi
thay family; sai password/MFA challenge chua thanh cong khong da phien.
Refresh/doi kho giu family. Replay refresh da duoc thay khong revoke successor.
Logout chi current family, khong END ca/xoa nhap. Metadata replacement khong
bi xoa khi rollback flag; schema rollback bi chan neu marker da duoc dung.

BE KHONG co push/disconnect event. Server check ngay request protected ke tiep,
khong co cam ket logout tuc thi cho client idle/offline. FE can GET `/auth/me`
khi foreground va truoc khi resume command; contract khuyen nghi heartbeat
60 giay khi foreground, khong phai interval BE da ep app thuc thi. Request da
duoc admit truoc replacement van co the hoan tat; FE bo late response auth
generation cu. KHONG tu retry owner UNKNOWN/key/body/version bang login moi.

Live evidence CU: `bb860587-89cf-4357-a969-f1a4221a9f2a` (A me 401),
`53e61cba-2396-4da3-bf81-1a4f9536d36a` (A refresh 401),
`f92785cc-6511-48ea-84ec-7405aa7f8dc5` (B me 200),
18/10/2026 18:43:41 +07:00. Hai HTTP cookie jars khac hai thiet bi vat ly.
Test race/legacy/password/MFA co trong JUnit nguon cua fix 9d9e8738; khong replay
tren admin hien tai. UI foreground/hai Android-browser van `BE_DEPLOYED_PENDING_UI_DEVICE_RETEST`.

## An toan va pham vi

Luot xu ly goi 19/10: chi source/tests/doc va GET public health khong credential.
Khong CI, push release, deploy, migration, seed, repair, restore, START/END,
RECORD/POST hoac upload. Khong gui email/ra kenh ngoai. Bo test dung SQLite
memory va PostgreSQL localhost rieng co test DB guard; khong DB serving.
