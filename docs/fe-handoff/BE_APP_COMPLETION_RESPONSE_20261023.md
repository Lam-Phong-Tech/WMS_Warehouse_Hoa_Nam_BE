# BE trả gói yêu cầu App R6 ngày 23/10/2026

## Phạm vi và nguồn

- Input: `BE_COMPLETION_REQUEST_20261023.zip`, SHA-256 `74c9dca89c894a02fc63dc094933e2c44ff7d18ec923d07213e15e76e415762f`.
- Source base: `dc9109830c5d389982964dfe601bff712d6323ab`; nhánh làm việc `codex/be-app-readiness-20261023`.
- Không coi yêu cầu R6 hoặc câu xác nhận “duyệt” là lựa chọn nghiệp vụ cụ thể cho định nghĩa chờ Web, hierarchy/capacity hoặc recovery xuyên family. Không có tài liệu owner/BA/security mới được cung cấp cho các quyết định đó.
- Không sửa native App R6. Kết quả source/test, source release, authenticated live và device UAT là các mức bằng chứng khác nhau.

## Đã thực sự sửa source

### BE-03: capability khi current=null

GET `/api/v1/mini-app/work-shifts/current` vẫn giữ nguyên `data` là ca hiện tại hoặc null, thêm `meta.shift_admission` theo `contracts/p14/admission-v1.json`.

Capability được tính từ actor/quyền/family hiện tại, WarehouseContext và config thực tế của tiến trình: ENABLED/DRAINING/DISABLED, tracked family kể cả ca đã END, fence, khả năng START tracked và lý do không khả dụng. Không tự START hoặc tạo ca giả. Khi cả hai cờ OFF, tracked family vẫn có fence; không hạ legacy. Khi READY thiếu hoặc mode đổi, true cũ không thay kiểm tra transaction của START.

App chỉ dùng sau kiểm tra đúng actor/family/kho/generation; UNKNOWN phải theo đúng giao thức owner gốc và giữ nguyên key/body/version. END đối chiếu GET bằng key gốc; START chỉ replay cùng key/body theo contract START hiện hữu khi được phép, không tạo key hoặc ý định mới từ capability. Không công bố GET receipt START mới. `local_state_verified=false`, coverage vẫn PARTIAL, không coi capability là CLEAN/no-work.

### BE-06: owner target cấp bản ghi P08

GET `/api/v1/mini-app/history/records/{recordId}` thêm `data.record_target`, marker `meta.record_target_contract=p08-record-target-v1`; nguồn `docs/contracts/p08-record-target-v1.json`.

Mapping lấy một canonical identity duy nhất từ tập lịch sử đã scope/quyền trước cursor, không lấy event đầu trang hoặc current lookup. `selectedActivity` vẫn null khi không gửi activityId. DOCUMENT/WARRANTY có exact owner detail và attachments theo quyền hiện hành; SCAN đi đúng batch detail, NFC đi events của đúng physical_code. Không tạo đường tắt receipt P07, không đoán P20/component ID và không biến draft/auth session/recipient thành business ID.

Target là GET hint cần owner kiểm tra lại, không phải grant quyền, receipt hoặc bằng chứng tài nguyên hiện vẫn tồn tại. Các nguồn lỗi/denied/khác kho không trở thành empty/target giả.

### BE-04/08: terminal upload receipt dưới fence P14

Bổ sung verifier P18 trong `P14ShiftOwnerEvidence`. Source trước có transaction ngoài của fence ném lỗi với mọi 4xx, làm rollback terminal REJECTED đã ghi trong owner upload. Bản sửa chỉ cho **receipt native REJECTED đã xác minh** đi qua transaction, giữ nguyên 4xx/quarantine. Không biến pre-intent validation/auth errors thành receipt.

SUCCEEDED chỉ chấp nhận native HTTP 201. Kiểm persisted key/actor/family/kho/parent/file SHA-256 và receipt đầy đủ; audit chỉ identity/outcome/digest, không file/note/credential. Terminal rejection có journal kind OWNER_REJECTED, không tính vào successful_request_count. Exact replay không thêm file/journal hoặc tăng version lần hai. Upload mới làm close-check cũ stale. Nguồn: `contracts/p14/owner-upload-evidence-r1.json`.

**Không đóng toàn BE-04:** crash/storage/5xx/in-flight durability với transaction ngoài, coverage mọi legacy/hardware và local registry App vẫn cần kiểm chứng riêng. Không xóa journal, không dựng negative proof, không auto retry, không tự coi thiếu receipt là CLEAN.

## Trạng thái BE-01–10

| ID | Kết quả có thật | Owner / còn thiếu |
| --- | --- | --- |
| BE-01 | Có owner/P12 v3 và P13 RECORD lịch sử; không triển khai tập current waiting bằng suy luận | BA + owner Nhập/Xuất phải chốt loại chờ, tập người đọc, persisted current state và milestone. Không tạo aggregate thứ hai. |
| BE-02 | Đã xác nhận NOT_TRACKED/NO_LOCATION_BALANCE_MODEL là capability thật, không phải empty | BA kho + inventory owner chốt mô hình cấp vị trí, units/capacity/ACL và nguồn hiện tại. Không bật supported hoặc dựng grid giả. |
| BE-03 | Đã bổ sung metadata đọc có version ở current, giữ data=null và fence | Auth/shift owner review source; release và App consume/device chưa thực hiện. |
| BE-04 | Đã xác minh P03/P04/P05/P07, adapter P09/P19 có sẵn; thêm terminal proof P18 và sửa rollback rejection | Còn toàn coverage, crash/5xx uncertainty, legacy/no-work/local registry và live END ma trận. Coverage vẫn PARTIAL. |
| BE-05 | P11 đúng là chưa có durable receipt/idempotency/reconcile; các cờ false được giữ | Security owner phải chốt binding bí mật, retention, lookup sau revoke và audit support cho UNKNOWN cũ; không thêm route recovery chưa duyệt. |
| BE-06 | Đã thêm record_target, không cần chọn event thay người dùng | History/owner review mapping mới; App wiring và authenticated/device smoke còn mở. |
| BE-07 | Bàn giao policy P21 linked có sẵn: đúng actor/family/kho/scan/key/command; legacy không alias, UNKNOWN CHECK_ONLY | Recovery tài khoản/support S01–S02 và xuyên family cần auth/security/BA quyết định. Không bật recovery/mailbox/OTP. |
| BE-08 | Đối chiếu predicate COMPONENT_SKU_REQUIRED; API upload/download/handover đã có source; thêm proof P18 nói trên | Chưa có truy vấn dữ liệu thật hoặc phạm vi credential TEST cho lượt này. Chưa xác định SKU thiếu/không COMPONENT/INACTIVE từ lỗi chung; không seed/grant/tăng tồn. NFC sai instant lịch sử không repair. |
| BE-09 | Bàn giao rule/route middleware và field-owner matrix source; có test IAM/source deny/revoke trong nhóm hồi quy | Chưa là catalog grants live hay nghiệm thu B2B mọi tenant. Đọc catalog live cần iam.role.view/iam.permission.view và phiên TEST được phép. Không dùng nhãn Admin/UI để bypass. |
| BE-10 | Có contract và OpenAPI extension versioned, patch/manifest/checksum, test đã chạy và blocker cụ thể | Không push/deploy; chưa có authenticated raw HTTP của bản sửa, release signoff hoặc App UAT. Health công khai không thay các bằng chứng đó. |

## Policy hiện có bàn giao lại, không tạo endpoint mới

- P21: ba GET `/mini-app/drafts`, `/{draft_id}`, `/{draft_id}/requests/{operation_key}` với `X-Component-Resume-Contract=p21-component-resume-v1`, operation COMPONENT_ISSUE, đúng scan/session/kho/command/key gốc. Version hiện tại của snapshot không thay version của request UNKNOWN. Canonical legacy/manual P05 không tự alias sang linked P21.
- P09: structured handover đã có `contracts/p09/warranty-handover-v1.json`: receiver/handover_date/handover_checked/note tách biệt, returned_at server riêng, If-Match/key/request ID gốc; không viết lại hay ghép vào note.
- P18: upload và ba GET reconcile của owner đã có. Source mới không sửa quota/MIME/scanner/storage/download ACL; UNSCANNED không phải CLEAN. UNKNOWN vẫn CHECK_ONLY và không cho tạo key mới.
- P11: `request_id=CORRELATION_ONLY_NOT_A_RECEIPT`; GET sessions hoặc 404 không chứng minh request cũ chưa thực hiện. Không lưu password/token để retry. UNKNOWN cũ không đủ identity cần audit support/security, không tự backfill.
- RBAC: role permission union với direct grants; direct revoke không phủ định quyền còn từ role; không có deny-overrides được công bố. Source có SUPER_ADMIN role_code bypass permission/scope, không phải display name Admin. Không tự thêm/bỏ bypass; các owner guard tenant/session/resource/field vẫn phải được kiểm riêng. Cache theo user.version, thay quyền qua owner bump sau commit; sửa DB trực tiếp không tự có bảo đảm cache invalidation.

## Bằng chứng release và giới hạn

GET health công khai ngày 23/10/2026 18:29:30 +07 trả 200, Request-ID `6fc6e28e-23c4-44c4-82dd-1415c5ed2f57`, runtime `8be640aa74704b12fe891f8a6db5969901efd8b2`, app/DB/Redis healthy, ran_count=218. Đây chỉ là quan sát health, không xác nhận pending migrations, flags của mọi worker, route cache, dataset/permission hoặc authenticated live smoke. Bản sửa source trong lượt này chưa lên runtime đó.

Kết quả test thực chạy, câu lệnh/config và XML nằm trong gói trả bài; chỉ được điền PASS sau khi kiểm XML không failure/error/skipped. Không chạy CI, deploy, migration/seed/repair/restore/grant thật. PostgreSQL dùng DB loopback riêng tên chứa test, SQLite in-memory, storage/cache/mail test; không gửi notification/mail thật.

Các lỗi và gate không được miễn trừ: lượt PG đầu có 4 error (fixture P09 không có line và 3 test catalog thiếu coverage). Fixture đã sửa, không tắt trigger. Lượt catalog/full-fixture PG với coverage 14 và secret ngẫu nhiên riêng được dừng sau 1.409 giây, ghi INCOMPLETE_NOT_PASS, không gọi full seed thành công. Nhóm owner được chạy riêng và báo riêng, không thay kết quả catalog đó. Composer audit locked exit0/không advisory; npm audit exit1 với 9 cảnh báo (8 high, 1 moderate). Không tự downgrade/force-update dependency để mở gate.

Mức nghiệm thu cuối: `SOURCE_FIXED_WITH_ISOLATED_TEST_EVIDENCE; BUSINESS_DECISIONS_AND_RELEASE_LIVE_DEVICE_ACCEPTANCE_OPEN`.
