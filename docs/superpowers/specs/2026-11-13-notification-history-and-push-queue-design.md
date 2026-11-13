# Giữ đầy đủ lịch sử thông báo kho và xếp hàng push

Ngày: 13/11/2026. Trạng thái: **đã được người dùng duyệt; triển khai phần backend hàng đợi trước trên dev**.

## Yêu cầu đã nhận

- Không làm mất lịch sử thông báo kho.
- Khi thông báo dồn dập, chỉ hạn chế toast/push; push vượt hạn mức phải chờ trong hàng đợi.
- Lập kế hoạch sửa, ưu tiên phần hàng đợi.

## Bằng chứng trong checkout

- `Modules/Notification/app/Services/NotificationService.php:77`: rate limit lọc người nhận trước transaction, nên vượt trần mất cả recipient lẫn outbox.
- Cùng service có lọc trùng theo nội dung trong 30 giây trước khi ghi DB; đây không phải định danh sự kiện nghiệp vụ.
- `Modules/Notification/app/Delivery/NotificationOutbox.php`: đã có outbox bền vững, unique target, lease, retry và after-commit wake-up. Giới hạn 50/100 là kích thước batch, không phải thông lượng toàn hệ thống.
- `Modules/Notification/app/Jobs/DispatchNotificationDeliveries.php`: job chưa chọn queue riêng và chưa tự tiếp tục xử lý backlog.
- `Modules/Notification/app/Retention/PruneNotificationRecipients.php`: lịch sử từng người có thể bị xóa sau 90/180/365/540 ngày, dù ledger còn.
- `Modules/Notification/app/Console/Commands/MonitorNotificationBurst.php`: có đếm outbox nhưng không dùng nó để quyết định cảnh báo.
- FE `src/modules/notifications/utils/notification-burst.ts`: buffer 400ms nhưng hiện bỏ phần tử cũ khi vượt 1.000; badge có thể thiếu.
- FE `notification-toast.ts`: giới hạn 3 toast mỗi batch, chưa giới hạn các batch nối tiếp.

## Phương án và lựa chọn đề xuất

1. **Mở rộng outbox DB hiện có — đề xuất dùng.** Lưu lịch sử trước, xếp lịch gửi push bằng DB; Redis/Laravel Queue chỉ đánh thức worker. Dùng lại lease và kiểm tra quyền hiện có, không thêm broker.
2. Đưa toàn bộ thao tác tạo thông báo vào delayed jobs: không phù hợp vì lịch sử cũng bị trì hoãn, và việc hết retry job có thể làm mất lịch sử.
3. Chỉ giữ push chờ trong Redis/cache: không phù hợp vì dữ liệu chờ phụ thuộc cache và khó đối soát theo recipient.

Người dùng đã chọn hướng hàng đợi thay vì drop. Các thông số và chi tiết dưới đây là đề xuất kỹ thuật của người lập, chưa phải giá trị người dùng đã duyệt.

## Ranh giới cam kết

- Lịch sử của mọi sự kiện kho hợp lệ được lưu cho toàn bộ audience hợp lệ, không phụ thuộc tốc độ toast/push hoặc trạng thái worker gửi push.
- Cam kết bắt đầu khi listener tiếp nhận sự kiện và `notify()` ghi thành công. Việc bổ sung transactional business-event outbox cho mọi thao tác kho là dự án riêng; thiết kế này không tuyên bố đã xử lý mất sự kiện trước listener.
- Audience vẫn do permission/warehouse scope quyết định; không cấp quyền mới, không gửi cho người ngoài phạm vi.
- Không hứa thiết bị luôn nhận push: token/phiên/quyền hết hiệu lực, từ chối nhà cung cấp, kết quả UNKNOWN hoặc TTL transport hết hạn có thể kết thúc push với lý do rõ ràng. Các trường hợp này không xóa lịch sử.
- Những thông báo đã bị drop trước đây không thể khôi phục chính xác chỉ từ bảng thông báo. Không tự tạo lịch sử giả hoặc phát lại push cũ.

## A. Tách lịch sử khỏi việc làm phiền người nhận

Tạo `NotificationHistoryPolicy` với một định nghĩa dùng chung cho việc tạo và giữ lịch sử kho:

- Nhóm event: `inbound.`, `outbound.`, `stock.`, `reversal.`, `warranty.`.
- Hoặc source module: `warehouse`, `inventory`, `warranty`, `component`.
- Các event `security.`, `user.`, `role.` luôn theo chính sách tài khoản hiện có, kể cả source module bị gán là warehouse.

Đối với nhóm kho:

1. Lưu `notifications`, source snapshot và tất cả `notification_recipients` hợp lệ trong transaction hiện có.
2. Bỏ trần 60/phút khỏi đường ghi lịch sử. Biến `NOTIFICATION_MAX_IN_APP_PER_MINUTE` bị ngừng sử dụng, không âm thầm chuyển nghĩa sang push.
3. Không dùng khóa nội dung 30 giây để bỏ một sự kiện kho. Hai sự kiện khác nhau có cùng title/body vẫn là hai bản ghi.
4. Giữ unique `delivery_dispatch_key` để retry cùng job không nhân đôi lịch sử/outbox. Không dùng title/body để suy ra cùng sự kiện.
5. Preference `in_app=false` của nhóm kho chỉ tắt toast/push; vẫn lưu lịch sử và đồng bộ danh sách/badge. Preference email vẫn điều khiển email. Nhóm ngoài kho giữ cách chọn kênh theo preference hiện có.
6. Broadcast sau commit vẫn thông báo đầy đủ dữ liệu cho danh sách/badge; toast được lọc ở FE. Push outbox chỉ tạo cho kênh đang được phép; trước khi gửi vẫn kiểm tra lại preference, phiên và quyền.
7. Retention tự động không xóa recipient thuộc nhóm kho. Có thể dọn dữ liệu transport đã kết thúc, không dọn lịch sử người nhận.

## B. Hàng đợi push bền vững

### Dữ liệu và giới hạn

- Giữ bảng `notification_deliveries`, unique `(recipient_id, registration_id, binding_version)` và state machine hiện có.
- Migration mới cho phép `notification_deliveries.expires_at` là NULL; không sửa migration cũ.
- Delivery thông thường mới chưa được thử gửi có `expires_at=NULL`: thời gian nằm chờ không tiêu hao TTL transport. Khi được cấp lượt gửi đầu tiên, đặt deadline `now + message_ttl_seconds` (mặc định 3.600 giây); retry không kéo dài deadline này.
- Row cũ có expiry giữ nguyên deadline. Canary tiếp tục expiry và hàng rào hiện tại; không biến test canary thành gửi đại trà.
- Thêm bảng `notification_push_rate_limits`: `user_id` là khóa chính/FK, `next_send_at` UTC timestamp precision 6, `updated_at`. Khóa hàng DB dùng chung giữa worker/scheduler, không dùng cache làm nguồn quyết định.
- Đề xuất `NOTIFICATION_MAX_PUSH_PER_USER_PER_MINUTE=60`, tức giãn tối thiểu 1 giây giữa hai lần **cấp quyền bắt đầu thử gửi** push không bắt buộc cho cùng người dùng, cộng trên tất cả thiết bị. Một lần cấp cho một delivery/thiết bị dùng một lượt; retry cũng dùng lượt. Nhiều thiết bị không làm nhân hạn mức của người dùng.
- Hạn mức áp dụng tại thời điểm admission trong DB. Worker có thể bị tạm dừng sau commit và mạng/provider có độ trễ; không cam kết khoảng cách chính xác giữa các HTTP request thực tế hoặc thời điểm thiết bị hiển thị. Test concurrency kiểm tra slot/admission, không suy ra bảo đảm thời gian nhận trên thiết bị.
- Giá trị cấu hình push hợp lệ: số nguyên 1..600. Khi cấu hình sai, không gửi push, giữ outbox và báo lỗi vận hành; không ảnh hưởng lịch sử.
- Nhóm account/security giữ ngoại lệ hiện có, không chờ hạn mức optional. Chúng vẫn phải qua kiểm tra quyền/token và giới hạn an toàn canary.

### Luồng xử lý

1. Tạo delivery cùng transaction với lịch sử; after commit chỉ đánh thức worker.
2. Worker lấy một batch nhỏ theo `(available_at, created_at, id)`; claim bằng CAS/lease hiện có và không giữ transaction DB khi gọi HTTP.
3. Kiểm tra binding, session, preference, quyền và canary. Delivery không còn hợp lệ thành SUPPRESSED có mã lý do; lịch sử không đổi.
4. Trong transaction ngắn, kiểm tra lease còn thuộc worker rồi khóa hàng rate limit của user. Nếu chưa đến lượt: trả row về trạng thái trước claim, đặt `available_at=next_send_at`, `last_code=RATE_DEFERRED`, giải phóng lease; không tăng attempts, không tạo row mới, không gọi FCM.
5. Nếu đến lượt: cập nhật `next_send_at=now+60/limit` giây; khởi tạo expiry nếu NULL. Commit rồi mới gọi sender.
6. Dùng kết quả sender hiện có để chuyển PROVIDER_ACCEPTED/RETRYABLE/CONFIGURATION_ERROR/REJECTED/UNKNOWN. Tôn trọng `Retry-After`; không tự gửi lại UNKNOWN hoặc lease đã hết để tránh nhân đôi push không rõ kết quả.
7. Job tự đánh thức lần kế tiếp khi còn việc. Wake-up dùng `ShouldBeUniqueUntilProcessing` để gộp tín hiệu đánh thức; unique lock chỉ tối ưu, không quyết định sự tồn tại của dữ liệu. Có scheduler DB backstop nếu dispatch hoặc cache lock lỗi.
8. Mỗi lần drain có ngân sách 20 giây, kiểm tra trước mỗi lần gửi. Với sender timeout hiện có 15 giây, một lần gửi cuối có thể kéo drain tới khoảng 35 giây, dưới worker timeout 90 giây. Lease vẫn 120 giây. Batch mặc định 50, trần 100; không đổi chúng thành rate limit.
9. Chỉ tự tiếp tục khi transport được phép hoạt động và có outbox chưa kết thúc; thời điểm đánh thức dựa trên `available_at`, delay tối thiểu 1 giây và tối đa 5 giây. Không sleep trong worker.
10. Scheduler mỗi phút là backstop. Khi transport tắt, không tạo vòng job rỗng và không thay đổi outbox.

### Worker riêng

- Queue gửi push: `notification-delivery` qua cấu hình `queue.notification_delivery_queue`/`NOTIFICATION_DELIVERY_QUEUE`.
- Ba consumer riêng trong supervisor: `notifications` tạo lịch sử/realtime, `notification-delivery` gọi FCM, `default` xử lý công việc khác.
- Nếu tên queue được override, supervisor đọc cùng biến `NOTIFICATION_QUEUE` và `NOTIFICATION_DELIVERY_QUEUE` với producer; không để consumer dùng tên cố định khác cấu hình. Các service producer/worker/scheduler phải nhận cùng giá trị.
- `NOTIFICATION_QUEUE=notifications` đưa listener sang consumer hiện có. Chỉ kích hoạt tên queue khi consumer tương ứng đã chạy; cấu hình dev template/compose và runbook phải thống nhất.
- Không cho `QUEUE_CONNECTION=sync` làm request nghiệp vụ thực hiện HTTP push. Với sync, chỉ lưu outbox và để scheduler drain; không dispatch chuỗi continuation đồng bộ.
- Không tự bật FCM hoặc nới environment/canary/origin hiện có. Không triển khai lên VPS trong công việc lập kế hoạch này.

### Dọn dẹp và giám sát

- Pruner chỉ dọn delivery ở trạng thái kết thúc sau 30 ngày tính từ `updated_at`: PROVIDER_ACCEPTED, REJECTED, EXPIRED, SUPPRESSED, UNKNOWN. Không xóa PENDING/RETRYABLE/CONFIGURATION_ERROR/SENDING do nằm chờ lâu; vẫn giữ hàng rào token/installation hiện có.
- Giám sát thêm outbox theo từng trạng thái, số RATE_DEFERRED đang chờ, tuổi row chờ lâu nhất và tuổi row đã đến hạn lâu nhất.
- Cảnh báo khi số outbox đang chờ >100 hoặc có row đến hạn bị chậm >300 giây, hoặc có CONFIGURATION_ERROR/UNKNOWN. Có cấu hình riêng cho hai ngưỡng; không chỉ dựa vào Redis queue length.
- Log tổng hợp có delivery/recipient IDs và mã lý do; không log token/nội dung khách hàng. Không ghi một warning cho mỗi lần trì hoãn bình thường.

## C. Toast và badge

- Giữ batch 400ms và kiểu hiển thị theo mục 9 của canonical UI guide.
- Đề xuất tối đa 3 toast cá nhân trong cửa sổ trượt 10 giây cho mỗi user trên mỗi tab đang hiển thị; phần còn lại cập nhật cùng một toast tổng hợp, tối đa một lần/giây. Không xếp hàng hiển thị từng toast cũ.
- Summary đếm các ID chưa được hiển thị riêng còn nằm trong 10 giây gần nhất, không cộng lại toast cá nhân hoặc ID lặp. Khi số đếm về 0, dismiss summary. Timer phải kiểm tra lại tab đang hiện và route trước mỗi lần cập nhật; ẩn tab, đi vào trang thông báo, logout hoặc đổi user thì hủy timer và summary chờ, không phát lại chúng khi quay về.
- Xếp ưu tiên critical/warning trước trong batch. Không thêm ngoại lệ tạo vô hạn toast; cảnh báo mới vẫn có lịch sử, badge và tổng hợp.
- Tab ẩn không tạo toast chờ; vào trang thông báo không bật toast lặp. Reset bộ đếm/timer khi user đổi hoặc logout.
- Khi buffer đủ 1.000 mục, flush sớm thay vì xóa mục cũ. Mọi ID mới vẫn cập nhật badge và danh sách; số item đang giữ trong bộ nhớ vẫn hữu hạn.
- Các tab đều đồng bộ badge như hiện có; giới hạn toast tính riêng từng tab. Việc bầu một tab duy nhất được phát toast là phạm vi riêng, không hứa đã giải quyết.
- Không thêm thông báo hệ điều hành/trình duyệt, không xin quyền browser push mới, không thay đổi giao diện toast.

## Ví dụ nghiệm thu

Với 120 sự kiện kho khác nhau cho một người đang bật thông báo, một thiết bị hợp lệ, sender phản hồi thành công và push limit=60:

- Sau khi listener xử lý xong: 120 notifications và 120 recipient; danh sách/badge phản ánh đủ 120, không chờ gửi push.
- 120 delivery được lưu. Slot đầu có thể được cấp ngay, các slot sau cách nhau tối thiểu 1 giây; phần còn lại chờ trong DB. Khoảng cách nhận thực tế phụ thuộc provider/thiết bị.
- Sau khi worker dừng rồi chạy lại, phần chưa thử gửi vẫn tiếp tục, không nhân đôi row hay gửi lại PROVIDER_ACCEPTED.
- Nếu 120 sự kiện đến trong 400ms: tối đa 3 toast cá nhân và một tổng hợp phần còn lại; badge vẫn tăng đủ 120.
- Với preference in_app=false: vẫn có đủ lịch sử, không toast/push cho nhóm kho đó.
- Sau thời gian retention cũ: lịch sử kho vẫn còn; audit transport terminal có thể được dọn riêng.

## Kiểm chứng và triển khai dự kiến

Test bằng SQLite memory cho persist/rollback/retry/TTL/preferences/retention; PostgreSQL test database riêng cho concurrency hai drainer, FK/migration và atomic rate limit. Không coi SQLite là bằng chứng khóa liên tiến trình PostgreSQL. Fake FCM trong test.

Triển khai schema trước, consumer và cấu hình sau, rồi mã producer/job. Xác nhận consumer đúng queue trước khi bật routing; lưu số backlog/oldest age trước và sau. Rollback mã phải giữ cơ chế lưu đầy đủ lịch sử, không quay lại DROP; ưu tiên tắt transport/continuation và giữ pending rows để xử lý sau. Không chạy migration down xóa outbox/rate table trên dữ liệu thật.

## Ngoài phạm vi

Khôi phục dữ liệu đã drop, thiết kế lại toàn bộ business-event delivery, bật production FCM, thêm digest push/collapse payload mới, thay đổi quyền truy cập, và triển khai VPS. Những thay đổi này cần yêu cầu và kiểm chứng riêng.


## Chốt trong implementation/review

- User yêu cầu sửa trực tiếp nhánh dev và push dev lên GitHub sau kiểm thử; chỉ triển khai backend queue trước, Task4 FE chưa thay đổi.
- Scheduler dùng store database riêng notification_scheduler với hai bảng notification_scheduler_cache và notification_scheduler_cache_locks, file config cache riêng config.scheduler.php. Laravel poll pause/interrupt trước chạy event, nên store này là điều kiện phục hồi khi Redis lỗi. Hai event drain/monitor không dùng Redis mutex; các guard của cron khác không bị vô hiệu hóa.
- Pruner không xóa binding còn bất kỳ delivery cần giữ, tránh FK cascade xóa pending gián tiếp.
- Admission lấy lại clock sau khóa user; recheck lease/deadline và rollback slot nếu không còn gửi an toàn.
- Perf queue chạy ba consumer thay vì một, default memory container từ 300m lên 900m; operator có thể override theo đo đạc thực tế.
