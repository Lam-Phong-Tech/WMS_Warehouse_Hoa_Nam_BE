# TIME-P07-01 - BE doi soat va source fix 21/10/2026

Trang thai: DEVTEST_DEPLOYED_PENDING_OWNER_HTTP_AND_NEW_TEST_WRITE_ACCEPTANCE.
Runtime moi: `8be640aa74704b12fe891f8a6db5969901efd8b2`.
Source fix: `6bfa66dc5d0edaddd91b67e59811e269983c5d71`.
Bang chung doi soat ban dau ben duoi duoc lay tren runtime cu `e2442df9`.
Chi tiet deploy: `docs/evidence/NFC_TIMESTAMP_DEVTEST_RELEASE_20261021.md`.

## Nguyen nhan da xac nhan

Loi duong ghi BE: Laravel bind now()/Carbon theo `Y-m-d H:i:s`, lam mat offset
`+07:00`. PostgreSQL session UTC hieu wall-clock local la UTC khi ghi timestamptz.
Moi ngay/offset ben FE khong phai giai phap cho gia tri da ghi sai.

| Nguon persisted | Gia tri nguyen chuoi |
| --- | --- |
| physical_codes.assigned_at | `2026-10-21 12:09:01+00` |
| physical_codes.created_at | `2026-10-21 12:09:01+00` |
| physical_codes.updated_at | `2026-10-21 12:09:01+00` |
| physical_codes.deactivated_at | null |
| receipt.data.nfc.assigned_at | `2026-10-21 12:09:01+00` |
| receipt.meta.timestamp | `2026-10-21T12:09:01+07:00` |
| audit_log.created_at - ASSIGN_ITEM_NFC | `2026-10-21 12:09:01+00` |

Epoch assigned_at = `1792584541.000000`; instant receipt.meta.timestamp =
`1792559341`, lech 25.200 giay. Offset trong du lieu the thuc su bieu dien
19:09:01 Viet Nam. Hien thi 19:09 tren client phu hop voi instant BE da luu.

Runtime schema: bon cot physical_codes tren va audit_log.created_at la timestamptz.
PHP/app=Asia/Ho_Chi_Minh, DB session=UTC. Do luc 12:36:31 +07:00:
PHP epoch va DB epoch cach nhau khoang 0,0053 giay do thu tu lay mau, khong lech 7 gio.

## Identity va receipt goc

- Physical code: `d641ce1b-cf3a-4b76-8d32-fec41634c059`.
- Item: `1982077a-ddd8-4203-a97d-cb9dcea1b5b5`.
- Warehouse: `01a06f33-6bff-70b8-a089-3c68a83b11a8`.
- Operation: mini_app_nfc_confirm; receipt id 271, status done, response_code 201,
  confirmation.state SUCCEEDED. HTTP 201 la gia tri persisted, khong phai replay moi.
- Request-ID goc tim duoc trong receipt VA audit:
  `590efbfb-ce83-4329-b64a-ecd49d70c457`.
- Actor cua confirmation khop assigned_by va user_id cua receipt;
  auth_session_id khop nfc_auth_session_id; kho/item/physical_code cung khop.
  Chi xuat boolean ket qua so sanh; khong xuat operation key, actor name, session
  token hoac cookie.
- Fingerprint receipt qua hai lan doc giong nhau:
  `a100c78e2fa6f339e5af8c22029e6934`. Khong sua row, receipt hoac mapping.

Run SELECT-only:
https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/39549382272
va https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/39550457251.
Hai run SUCCESS, CI skip, khong deploy/migration/seed va transaction READ ONLY.

Raw evidence Node, giu timestamp la chuoi:
`docs/evidence/TIME_P07_01_SOURCE_INSPECTION_20261021.json` va
`docs/evidence/TIME_P07_01_OWNER_INSPECTION_20261021.json`.
Day la projection cot/receipt persisted da loc, khong phai raw HTTP owner.
Public health 200 co HTTP Date/Request-ID/runtime SHA trong evidence, chi chung
minh build/clock cong khai; khong thay auth smoke.

## Source fix

Contract: `contracts/timestamps/nfc-timestamps-v1.json`, revision time-p07-01-r1.

- NFC insert/update moi bind chuoi co offset va microseconds. assigned_at,
  created_at, updated_at, deactivated_at tra RFC3339 UTC Z tu gia tri DB.
- P07 project, tagPayload va GET list/detail/item/resolve dung chung serializer.
  Response confirm MOI luu cung projection vao receipt trong transaction hien huu.
- Confirmation/replay van tra body receipt da luu, khong normalize lai receipt
  lich su hoac thay du lieu bang mapping hien tai. Wrapper current co the cong bo
  timestamp_contract nhung nested receipt cu van giu text/marker goc.
- Audit writer NFC luu time aware truoc ORM cast/outbox. Publisher audit dung
  thoi diem goc trong payload; khong lay assigned_at hien tai hoac now() luc publish.
  Audit ngoai ASSIGN_ITEM_NFC/REVOKE_ITEM_NFC giu date format cu. Payload queue
  legacy khong offset giu hanh vi binding cu, khong suy provenance moi.
- P22 normalize time theo actual column type truoc filter/sort/keyset; event time
  la audit_log.created_at. Giu quyen, scope, snapshot, null va danh tinh owner.
- Meta timestamp_contract=nfc-timestamps-v1 tren receipt moi/current P07/P22.
  P22 giu base contract p22-nfc-history-v1. Cursor bind timestamp contract moi,
  cursor cu nhan scope-mismatch theo policy hien huu; FE giu filter khi reload.
- Khong doi date-only, timezone DB, owner gates, NFC readback, quantity, stock,
  schema, route hay bien moi truong. Replacement P07 van bi chan.

## Gate con mo

Nguoi dung xac nhan khong co file token/operation key tu APK. Chua replay GET
list/detail/confirmation bang dung owner session, khong tao key moi, khong login
phien moi va khong gui lai PATCH confirm. Khong doc token/private storage APK.

Da deploy source fix luc 13:57:58 +07:00 ngay 21/10/2026, run 39557980205 SUCCESS.
Read-only probe tren runtime moi xac nhan writer va binding cung giu +07:00;
fingerprint receipt 271 giu nguyen. Chua authenticated owner HTTP hay ghi NFC moi.
Sau release, tag/receipt da ghi sai se van hien
19:09 neu FE hien thi dung raw instant. Xu ly du lieu cu la quyet dinh rieng cua
data owner; source fix chi ngan ghi moi tiep tuc sai va thong nhat serialization.

Live new-write smoke can ca TEST rieng da duyet. Kiem lai tren Android/web sau
release dung SHA, giu RFC3339 va Request-ID; khong cong/tru tay 7 gio tai FE.
P07/P01-P24 va TIME-P07-01 chua PASS toan bo.

## Kiem thu ban cuoi

| Engine co lap | Tests | Assertions | Ket qua |
| --- | --- | --- | --- |
| PostgreSQL loopback p12_contract_test:55433 | 69 | 1.032 | PASS |
| SQLite memory | 69 | 1.012 | PASS |

Zero errors, failures, skipped. Day la focused local regression, khong phai CI
tong hay live device acceptance. JUnit nam trong `docs/evidence/TIME_P07_01_20261021`.
Pint va diff check PASS. Secret scan chay tren cac file duoc stage truoc commit.

Ca PostgreSQL kiem qua bon to hop app/PHP UTC hoac Asia/Ho_Chi_Minh va session
DB UTC hoac Asia/Ho_Chi_Minh, doi chieu ca raw value va extract(epoch). Bao gom
assigned/created/updated/deactivated, null, microseconds, qua nam moi, GET
list/detail/item/resolve, receipt/replay, P22 va audit publish tre. Meta P22.asOf
cung tra RFC3339 UTC Z; key noi bo cua query/cursor khong bi lo thanh timestamp
public khong offset.

Fixture P22 cu da format ve UTC roi de ORM bo offset, phu thuoc PG session.
Helper fixture nay nay dung canonical recordPayload aware; assert chinh xac
hai event tai bien ngay van duoc giu. Fixture legacy rieng giu date format cu.
Fixture hold dung moc now gan dong ho that vi owner guard so sanh time(); khong
noi reservation TTL, bo readback hoac bo guard de lay PASS.

Lenh PHPUnit cuoi dung cac file sau tren ca hai engine:

```text
tests/Feature/P07NfcTimestampTest.php
tests/Feature/P07NfcContractTest.php
tests/Feature/P07NfcRecoveryTest.php
tests/Feature/P07NfcRouteContractTest.php
tests/Feature/P22NfcHistoryReadTest.php
tests/Feature/P22OutboxPublicationTest.php
tests/Feature/NotificationHistoryTimestampTest.php
tests/Feature/AuditLogObjectReferenceTest.php
tests/Feature/Wwhn172AuditFailClosedTest.php
tests/Feature/Wwhn222AuditEventCatalogTest.php
```

APP_ENV=testing; DB_URL rong; cache array, mail array, broadcast null, queue sync.
RefreshDatabase chi tao schema/fixture trong memory hoac cluster PostgreSQL
loopback co marker DISPOSABLE_LOCAL_SEED_REGRESSION_ONLY. Khong chia se DB/storage
voi runtime DEV. Khong chay seeder/migration/repair tren wms_hoanam_green.
