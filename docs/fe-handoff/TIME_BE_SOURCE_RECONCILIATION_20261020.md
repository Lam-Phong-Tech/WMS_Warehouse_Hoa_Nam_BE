# TIME-BE-01 / TIME-BE-02 - doi soat nguon 20/10/2026

## Ket luan

TIME-BE-01: loi duong ghi BE da duoc tai hien, khong phai formatter FE.
TIME-BE-02: source normalize P08/P23 da deploy DEV/TEST. Instant lich su cua
event nguoi dung neu van OPEN vi cot naive khong luu offset va chua co provenance
cua lan tao/seed. Deploy khong tu dong bien candidate convention thanh bang chung
ve instant lich su.

## Deploy DEV/TEST

- Run: https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/39400013922, SUCCESS.
- Application SHA: `e2442df9cb03a50f57847aca0bcba78f0b2b2717`.
- Tested source SHA: `f6969280a65e6223b5a937f5022791857a744a25`.
- Hoan tat: 20/10/2026 16:25:55 +07:00.
- Private report: `/opt/hoa-nam-green/backups/deploy-time-20261020T092246Z.json`.
- 218 migration, khong pending; khong chay migrate/seed/repair/restore.
- Config/route cache va Swagger da build lai; nginx hop le; backend, queue,
  scheduler cung SHA moi.
- Fingerprint 113 bang va manifest 13 file `storage/app` khop truoc/sau.
- Free disk sau deploy: 1.052.573.696 bytes. Release giu nguong 960 MiB.
- CI tong SKIPPED theo yeu cau; production activation OFF.

Hai luot chuan bi bi gate dung truoc maintenance/switch vi thieu reserve. Chung
da bo tag 8 image legacy khong co container/release rollback tham chieu, nhung
gan nhu khong thu hoi byte do layer dung chung. Khong xoa container, volume,
PostgreSQL data, media hoac backup. Image rollback dang chay
`69aad9b47acd...` duoc giu nguyen.

Read-only sau deploy (run 39400850010) xac nhan runtime SHA tren va writer moi
tao chuoi co offset `+07:00`; binding giu nguyen offset. Khong co INSERT/UPDATE.
Gia tri persisted cu `2026-10-20 13:49:36+00` duoc giu nguyen.

Public smoke 20/10/2026 16:29:33-16:29:34 +07:00:

- Health 200, RID `7412dcfc-ab88-4eaf-9da0-3bc536d74d36`, SHA dung.
- Notification detail khong credential: 401, RID `ca183eab-3738-4e13-b606-a8636e94aa21`.
- P08 detail khong credential: 401, RID `a4b59c85-1425-40cf-8aa7-8cf3f071c117`.
- Cache-Control `no-store, private`, Content-Type JSON.

Chua co token owner duoc cap, nen khong login lai admin, khong PATCH recipient,
khong tao notification/scan TEST. Authenticated write/read va Android/web van
pending; public 200/401 khong duoc dung de dong gate.

## Bang chung runtime chi doc

Run: https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/39390051818
SUCCESS, job CI SKIPPED. Day la kiem tra read-only, khong phai CI PASS/deploy.
SHA runtime: `22272d9ea65bf4e52d39d632310eb820a6df65f0`.
Raw chuoi timestamp duoc Node luu trong
`docs/evidence/TIME_BE_SOURCE_INSPECTION_20261020.json`, khong qua PowerShell
ConvertFrom-Json/ConvertTo-Json. Khong xuat title/body/customer/token/cookie.

- Thoi diem: `2026-10-20T14:58:42.263630+07:00`.
- PHP/app timezone: `Asia/Ho_Chi_Minh`; DB session: `UTC`.
- DB clock: `2026-10-20 07:58:42.102622+00`. Hai phep do cach nhau khoang 0,161
  giay, khong co bang chung clock lech nhieu gio.
- Recipient `01a116dd-de82-71b3-9095-345ea852dc55`, `read_at` la timestamptz,
  raw persisted `2026-10-20 13:49:36+00`.
- ORM cast `2026-10-20T13:49:36.000000+00:00`; owner serializer
  `2026-10-20T13:49:36+00:00`. Serializer KHONG doi offset sai cho gia tri nay.
- Binding probe KHONG ghi: grammar `Y-m-d H:i:s` bien now() thanh
  `2026-10-20 14:58:42`, mat `+07:00`. Binding chuoi aware giu
  `2026-10-20 14:58:42.439586+07:00`.

Duong ghi hien tai truyen now() local qua binding vao timestamptz trong session
UTC, nen DB hieu wall-clock local la UTC. Cung rui ro o read-all va duong tao
notification/recipient qua Eloquent date cast. Khong ket luan moi row lich su da
sai; khong sua hang loat. RID PATCH/GET trong evidence la do nguoi dung cung cap,
chua co correlation log/raw HTTP replay moi duoi auth owner cua app.

Ban sua truyen timestamp aware vao cac lenh danh dau doc, giu WHERE read_at IS
NULL va first-read. Date serialization khi Eloquent tao notification/recipient
giu offset; broadcast doc precision persisted. GET tra RFC3339 UTC Z, khong clamp
gia tri tuong lai. Recipient da sai van bieu dien `20:49:36` gio Viet Nam cho den
khi co quyet dinh xu ly du lieu rieng; khong PATCH lan nua de che loi.

## P08/P23 va provenance con mo

- Canonical `scan_events.id=5818ba7d-ea57-4431-8f29-aa40ff5bfecc` luu
  `created_at=2026-10-15 03:45:57`, kieu timestamp WITHOUT time zone.
- `scan_batch.scan_batch_id=22cbebc2-7d31-4642-b7ec-e74195e0eff6` cung chuoi
  naive. Khong co row tuong ung trong nguon legacy scan_record_events/scan_batches.
- Khong co persisted started_at/ended_at trong hai batch source cua runtime nay;
  giu null. Khong suy tu created_at hoac event cuoi.
- Ban runtime P08 tra event_time tho. P23 batch/latest event cung tra chuoi tho;
  UNION nhieu kieu timestamp con co the bi PG session timezone anh huong.

Candidate source fix normalize theo KIEU COT truoc UNION/filter/sort/keyset,
serialize cac projection list/detail/session/latest ve cung RFC3339. Offset co
san giu instant; naive source theo storage convention Viet Nam cua writer scan
hien co va P08/P23 truoc do, KHONG theo kho/filter/status/formatter hien tai.
Contract: `contracts/timestamps/notification-history-timestamps-v1.json`.

Dieu nay chua chung minh event lich su tren thuc te duoc tao theo convention do.
Neu raw naive cua no duoc ghi boi mot luot seed UTC, ket qua candidate se khac
instant thuc. Can log/manifest nguon tao hoac quyet dinh owner co bang chung cho
dataset nay truoc khi release rule. Khong dung 10:45:57 cua UI list lam bang chung
UTC, cung khong tu gan +07 vao raw chi de khop man detail.

## Nghi ven FE/QA va gate

- FE khong bu gio bang cong/tru 7 gio. Instant raw cua notification hien tai
  hien thi 20/10/2026 20:49:36 theo Viet Nam la dung offset, du nguon ghi sai.
- Meta notification moi: `timestamp_contract=notification-timestamps-v1`.
  History moi: `timestamp_contract=history-timestamps-v1`.
- P08/P23 cursor bind revision thoi gian; cursor cu khong duoc giai lai am tham.
  Giu bo loc khi tai lai trang dau theo error policy hien huu.
- Date-only khong doi ngay; posted_at null khong suy tu POSTED. Khong them field
  khong co nguon; khong lay current lookup bu lich su.
- Chua co raw authenticated GET sau patch, Request-ID moi, Android/web replay
  hay timestamp live write TEST duoc duyet. Khong dung SELECT hoac unit tests
  thay authenticated acceptance. Gate van OPEN.
- Luot nay khong doi timezone DB, deploy, seed, repair, restore, migration hoac
  PATCH recipient that. Test ghi chi tren SQLite memory/PostgreSQL loopback rieng.

## Kiem thu source ban cuoi

SQLite memory: 91 test / 1.206 assertions PASS.
PostgreSQL localhost: 91 test / 1.206 assertions PASS.
Khong failure/error/skipped. JUnit cuoi:
`docs/evidence/TIME_BE_20261020/sqlite.xml` va `postgres.xml`.
Ca fixture token het han khi nhay dong ho da duoc sua bang cach dat dong ho
truoc login; khong noi guard auth hay bo ca de lay PASS.

Phu: Pint, diff check va known-credential scanner PASS. Day la focused local
regression, khong CI tong, audit tong, UI PASS hay kiem ghi moi tren DEV.

Lenh test giong nhau tren hai engine, cac file duoc truyen day du vao PHPUnit:

```text
tests/Feature/NotificationHistoryTimestampTest.php
tests/Feature/P13NotificationContractTest.php
tests/Feature/P13NotificationRevision2Test.php
tests/Feature/NotificationActorTest.php
tests/Feature/P08HistoryContractTest.php
tests/Feature/P08DocumentHistoryTest.php
tests/Feature/P23ReadContractTest.php
tests/Feature/DocumentTimestampHttpTest.php
tests/Unit/DocumentTimestampContractTest.php
tests/Unit/P08HistoryReadContractTest.php
```

PHPUnit dung APP_ENV=testing, DB_URL rong, cache array, mail array, broadcast null,
queue sync va protected-deployment flag false chi trong tien trinh test.
SQLite=:memory:. PostgreSQL=127.0.0.1:55433/p12_contract_test tren cluster co marker
DISPOSABLE_LOCAL_SEED_REGRESSION_ONLY. Khong dung DB, volume hay config runtime DEV.
Schema fixture duoc tao boi RefreshDatabase chi trong DB disposable, khong co
lenh migration/seed/repair nao chay tren wms_hoanam_green trong luot nay.
