# TC02 và TC04 Nguồn BE và phần đã bổ sung

Ngày 24/10/2026, Asia/Ho_Chi_Minh. **Chưa PASS đủ mẫu hai case.** Source mới chỉ bổ sung đọc thời điểm handoff có sẵn; chưa push/deploy hoặc smoke có xác thực. Không chạy CI/migration/seed/repair/restore hay ghi dữ liệu thật.

## TC02 Chức danh thông báo

Định nghĩa người dùng vừa đề xuất: chức danh công việc tại thời điểm phát sinh thông báo; IAM chỉ được dùng khi BA xác nhận đó chính là nhãn nghiệp vụ. Dữ liệu cũ thiếu snapshot giữ null, FE hiển thị “—”. Quyết định dùng tên danh bạ hiện tại của creator phiếu trước đây không mở rộng thành quyền thay chức danh lịch sử của thông báo.

Nguồn hiện có: `NotificationService::notify()` đọc tên actor và lưu `notifications.actor_name`; GET trả chính snapshot đó. Listener có thể chạy queue sau sự kiện gốc, nên đây là tên lúc tạo notification, không tự chứng minh tên tại thời điểm giao dịch. Schema/model/writer đang xét không có trường chức danh công việc hoặc snapshot chức danh có provenance. Không có nguồn hợp lệ để BE tự điền “Nhân viên kho”.

BE/BA cần chốt nguồn chức danh nhân sự và owner ghi snapshot cho **thông báo mới**, thời điểm chụp, mã/nhãn chức danh, schema/payload revision và field-level ACL. Không bổ sung migration/chạy backfill dữ liệu thật trong lượt này. Tên kho đang là danh mục hiện tại theo đúng warehouse UUID của event và scope đã xác minh, không phải snapshot tên kho lịch sử. Nếu yêu cầu lịch sử tên kho, cần nguồn snapshot riêng.

Giữ nguyên recipient ID, actor snapshot, subject/read_target, quyền subject và first-read semantics. GET không tự PATCH read. Notification quyền nhận lịch sử không tự cấp quyền mở phiếu hoặc đọc danh bạ.

## TC04 Thời điểm gửi Web

Đã bổ sung source contract opt-in `p12-timeline-handoff-v1` trên **hai GET detail hiện hữu**:

```http
GET /api/v1/mini-app/inbound-documents/{id}?warehouse_id=<UUID>
GET /api/v1/mini-app/outbound-documents/{id}?warehouse_id=<UUID>
X-P12-Timeline-Contract: p12-timeline-handoff-v1
```

Sau owner ACL và projection P13 đã xác minh identity, mốc `data.p12.timeline.milestones.sent_to_web.occurred_at` lấy đúng `mini_app_web_post_handoffs.submitted_at`. Trả `timestamp_source` thật và source record/document/version; `event_id` vẫn null vì PK này là business ID. Actor/description thiếu nguồn lịch sử giữ null với trạng thái rõ ràng, không mượn creator/caller/IAM hoặc note chứa PII.

Không có header thì timeline v2 giữ nguyên, tránh đổi parser App đang chạy. Có header, FE kiểm `meta.p12.timeline_contract` và `data.p12.timeline.contract`; `coverage` vẫn PARTIAL. Không gắn header toàn cục, không gửi cho list/warranty/receipt/write; không nhầm với contract aggregate P12 v3. Mất nguồn hoặc identity/timestamp không hợp lệ trả lỗi503, không tạo thành mốc rỗng thành công. Chưa có bản ghi handoff thì NOT_RECORDED, không chứng minh chưa từng gửi.

Mốc gửi là bằng chứng handoff/submission, không phải Web đã tiếp nhận/duyệt/Post. Khi phiếu đã POSTED/CANCELLED, giờ gửi lịch sử vẫn giữ nguyên nhưng trạng thái chờ Web phải lấy nguồn current riêng. Không lấy receipt RECORD cũ thay current; không backfill phiếu cũ.

“Đã kiểm tra” theo câu trả lời mới là xác nhận riêng của con người. Chưa có event/owner CHECK nên giữ SOURCE_MAPPING_PENDING/null. Không dùng validation RECORD, SCANNING/readiness hoặc action trạng thái làm bằng chứng CHECK. Muốn nút gửi kiêm xác nhận kiểm tra phải có quyết định nghiệp vụ và owner ghi bằng chứng/version/receipt riêng; không được coi bản đọc này là phê duyệt mutation.

Contract chi tiết: `docs/contracts/p12-timeline-handoff-v1.json`; OpenAPI của hai detail đã ghi header và lỗi opt-in. Chưa triển khai runtime. Actor/description các mốc và nguồn chức danh vẫn OPEN, nên chưa hoàn thành mẫu TC04.

## Kiểm thử và nghiệm thu

Bản audit trước sửa đã chạy4 test/61 assertions PASS trên SQLite in-memory: actor snapshot/thiếu actor, recipient cross-user404 và timeline cũ không bịa mốc từ SCANNING. Kết quả mới và phạm vi chính xác nằm trong manifest/XML của gói; test cô lập không phải authenticated DEV evidence.

Bản source mới: 37 test/559 assertions PASS; chạy lại với route cache riêng: 14 test/251 assertions PASS, cache test đã được bỏ sau khi kiểm. Các nhóm có giao nhau, không cộng thành tổng ca duy nhất. Pint/diff/contract JSON và sinh Swagger vào thư mục riêng PASS; hai GET detail có header opt-in cùng lỗi422/503. PostgreSQL cho candidate này chưa chạy; không dùng kết quả cũ thay bằng chứng mới.

Trước release cần FE adapter chấp nhận revision, kiểm quyền/sai kho/soft-delete/source error, và PostgreSQL/route-cache/Swagger gates phù hợp. Smoke phải GET cùng một phiếu TEST được phép, có exact deployed SHA, Request-ID, `meta.p12.contract`, timeline contract, timestamp contract và response đã che PII. Hiện chưa có UUID phiếu TEST và token file của đúng scope để thực hiện smoke đó; không tự login thay phiên hay RECORD/POST để tạo dữ liệu.

P14 weak ETag read acceptance đã chốt riêng; hai case này không mở lại hoặc nâng thành toàn App PASS. Không thay UI/App trong lượt source BE này. Các file Note, screenshot fixture và test FE không được dùng làm dữ liệu nghiệp vụ thật.
