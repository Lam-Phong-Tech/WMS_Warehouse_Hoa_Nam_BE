# Notification History and Push Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Trạng thái: người dùng đã duyệt; triển khai backend Tasks 1–3 trực tiếp trên dev, Task 4 toast hoãn theo yêu cầu queue trước. Kiểm thử backend nằm ở Task 5; không tự deploy VPS.

**Goal:** Lưu đầy đủ lịch sử thông báo kho và chuyển giới hạn chống quá tải sang toast/push có hàng đợi bền vững.

**Architecture:** Tiếp tục dùng transaction lịch sử và bảng notification_deliveries hiện có. Throttle push bằng khóa hàng DB theo user; quá hạn mức thì đổi available_at và để worker riêng xử lý sau. Redis chỉ đánh thức worker; scheduler có thể phục hồi đường xử lý từ outbox DB.

**Tech Stack:** Laravel/PHP hiện có, PostgreSQL, Laravel Queue/Valkey, React/TypeScript, Vitest, PHPUnit; không thêm broker hoặc package.

**Spec:** [Thiết kế](../specs/2026-11-13-notification-history-and-push-queue-design.md).

## Global Constraints

- Gốc BE: `D:/laragon/www/Hoa_Nam/WMS_Warehouse_Hoa_Nam_BE`; gốc FE: `D:/laragon/www/Hoa_Nam/WMS_Warehouse_Hoa_Nam_FE`.
- Không DROP recipient kho do rate limit, preference hiển thị, lọc trùng nội dung hoặc retention tự động.
- Giữ permission/warehouse scope, dispatch idempotency, source snapshots, token/session checks, canary và quy tắc UNKNOWN không tự retry.
- Default push 60 slot bắt đầu thử gửi/user/phút cộng trên mọi thiết bị, admission cách 1 giây; hợp lệ 1..600. Đây là giới hạn admission DB, không cam kết khoảng cách HTTP/hiển thị thực tế sau độ trễ worker/mạng. Nhóm account/security miễn optional throttle.
- TTL transport 3.600 giây bắt đầu từ lần thử gửi đầu tiên cho delivery mới thông thường; queue wait chưa gửi không hết TTL.
- Queue push `notification-delivery`; drain budget 20 giây, lease 120 giây, batch mặc định 50/trần 100, worker timeout 90 giây.
- Ngưỡng cảnh báo outbox >100 hoặc overdue >300 giây. Không bật FCM/environment/origin ngoài chính sách hiện có.
- Toast: batch 400ms, 3 cá nhân/10 giây/user/tab, một tổng hợp cập nhật tối đa một lần/giây; hidden tab không toast chờ.
- Canonical FE guide `docs/ui/DESIGN_FOUNDATIONS.md` mục 9, AGENTS.md và i18n VI/EN/ZH áp dụng; không đổi style toast.
- Không đụng các file dirty/evidence có sẵn, không tự deploy hoặc phục hồi lịch sử cũ. Tạo isolated worktree khi bắt đầu implementation theo skill using-git-worktrees.

## Review Focus

1. Retry cùng dispatch key và hai sự kiện khác nhau có cùng nội dung: cùng job chỉ một bản ghi, hai sự kiện vẫn hai bản ghi — Task 1.
2. Worker và scheduler cùng xử lý một user, nhiều thiết bị và biên phút: không cùng nhận một slot, không nhân hạn mức, không starvation user khác — Task 2.
3. Chờ quá một giờ, worker restart, configuration failure và UNKNOWN: không mất pending mới do thời gian chờ; không gửi lại kết quả không rõ — Tasks 2–3.
4. User tắt kênh/đổi quyền/đổi phiên trong lúc chờ và history aging: không push trái phép, vẫn giữ lịch sử theo audience lúc tạo — Tasks 1–2.
5. Hơn 1.000 socket events, các batch liên tục, logout/chuyển user, hidden/visible: badge đúng, toast có giới hạn, timer không rò sang user khác — Task 4.

## File Structure

BE tạo mới:

- `Modules/Notification/app/Support/NotificationHistoryPolicy.php`: cùng định nghĩa nhóm kho cho service/retention.
- `Modules/Notification/app/Delivery/PushDeliveryThrottle.php`: cấp slot theo user bằng DB, không gọi provider.
- `Modules/Notification/database/migrations/2026_11_13_000001_add_notification_push_queue_controls.php`: nullable expiry và bảng rate limit.
- `tests/Feature/NotificationHistoryPersistenceTest.php`, `tests/Feature/NotificationPushQueueTest.php`, `tests/Feature/NotificationPushQueueConcurrencyTest.php`: test hành vi mới; concurrency dùng PG test database được chỉ định, không tự tạo trên DB dev.
- `tests/Unit/NotificationQueueSupervisorTest.php`: chạy supervisor với lệnh php giả để chứng minh ba consumer và cleanup, không chỉ tìm chuỗi trong file.

BE sửa:

- `NotificationService`, `NotificationDeliveryPolicy`, `NotificationOutbox`, `DispatchNotificationDeliveries`, `PruneNotificationRecipients`, `PruneNotificationDeliveryState`, `MonitorNotificationBurst`, `DrainNotificationDeliveries`, `NotificationServiceProvider` tại các thư mục hiện có.
- `config/notification_delivery.php`, `config/queue.php`, `.env.example`, `docker/php/queue-supervisor.sh`, `docker-compose.yml`, `docker-compose.perf.yml`.
- Các test hiện có `ProfileNotificationDeliveryTest`, `NotificationDeliveryOutboxTest`, `NotificationRetentionPruneTest`, `NotificationDeliveryPruneTest`; dùng lại fixtures của `Concerns/PushDeliveryTestCase.php`.
- `docs/ops/HN-BE-OPS-001_OPERATIONS_RUNBOOK.md`: routing, monitor, migration và rollback không DROP.

FE tạo mới `src/modules/notifications/utils/notification-toast-budget.ts` cùng `.test.ts`; sửa `notification-toast.ts`, `notification-burst.ts`, `hooks/useNotificationSocket.ts` cùng test hiện có và canonical guide mục 9. Không sửa generated files hoặc snapshot không liên quan.

## Task 1: Lưu đủ lịch sử kho độc lập kênh hiển thị

**Interfaces:**

- `NotificationHistoryPolicy::isWarehouse(string $eventKey, string $sourceModule): bool` theo đúng families/modules và ngoại lệ account trong spec.
- `NotificationHistoryPolicy::excludeWarehouse(\Illuminate\Database\Query\Builder $query, string $notificationAlias = 'n'): void` thêm predicate SQL loại nhóm kho khỏi truy vấn prune; kiểm thử cùng bảng phân loại với isWarehouse.
- Giữ nguyên signature `NotificationService::notify(...)`; `dispatchKey` vẫn là khóa chống retry trùng.

- [ ] Viết `NotificationHistoryPersistenceTest` với các ca: 120 event có dispatchKey khác nhau -> đủ 120 history/recipient dù legacy cap=1; hai event cùng title/body khác dispatch key -> 2; cùng dispatch key -> 1 history/recipient/outbox; gọi không dispatchKey hai lần có cùng nội dung kho -> 2; rollback ngoài không lưu recipient/outbox và không dispatch.
- [ ] Thêm ca warehouse preference in_app=false vẫn lưu recipient, không push/email ngoài preference; nonwarehouse preference hoạt động như cũ. Dùng audience đã được resolver cấp, không thêm người nhận.
- [ ] Sửa `ProfileNotificationDeliveryTest` để thay kỳ vọng drop bằng persist; giữ test coalesce ngoài kho. Bổ sung matrix event/module và giữ account exception.
- [ ] Chạy test mới để xác nhận lỗi hiện tại đúng là thiếu recipient/notification. Dùng lệnh BE an toàn bên dưới, filter `NotificationHistoryPersistenceTest|ProfileNotificationDeliveryTest`.
- [ ] Thêm policy; bỏ lời gọi/private method rateLimitInApp khỏi đường ghi; bỏ coalesce nội dung cho kho; tách `$historyIds` khỏi eligibility toast/push. History kho bỏ qua preference hiển thị, còn eligibility outbox vẫn theo preference và policy hiện có. Broadcast đầy đủ history IDs sau commit.
- [ ] Sửa `PruneNotificationRecipients` dùng cùng classifier, bảo vệ recipient kho. Trong `NotificationRetentionPruneTest`, chuyển các fixture kiểm tra xóa thông thường sang custom nonwarehouse; thêm warehouse read/unread info/warning cũ 2.000 ngày đều còn.
- [ ] Chạy tests history/profile/retention, P13 contract/timestamp và outbox rollback. Kết quả cần: đủ số recipient, đúng unread-count qua API, không mở rộng scope, không mail/push bị opt-out.
- [ ] Commit riêng phần persistence và test sau khi đạt; không stage thay đổi có sẵn.

## Task 2: Trì hoãn push bền vững, throttle nguyên tử và TTL đúng

**Interfaces:**

- `PushDeliveryThrottle::admit(string $userId, \Carbon\CarbonImmutable $now): ?\Carbon\CarbonImmutable`: NULL nghĩa là đã cấp slot và đã đẩy next_send_at; datetime nghĩa là chưa được gửi, chưa dùng lượt. Hàm dùng transaction/row lock và được gọi khi delivery lease đã được kiểm tra trong cùng transaction ngắn. Config sai ném lỗi nghiệp vụ nội bộ để giữ pending và cảnh báo, không fail-open gửi vượt hạn mức.
- Giữ `NotificationOutbox::enqueue(Notification $n, ?array $registrationIds = null): int` và `drain(DeliverySender $sender, int $limit = 50): int`; giá trị drain trả về tiếp tục là số sender calls, không đếm RATE_DEFERRED.
- Thêm `NotificationOutbox::nextWakeAt(): ?\Carbon\CarbonImmutable`, chỉ xét rows trong phạm vi transport/canary được phép và các state có thể tiến triển; không xét terminal UNKNOWN.

- [ ] Viết migration mới: expiry nullable; rate table PK user_id/FK, next_send_at timestampTz precision 6, updated_at. Không backfill/replay delivery cũ; rollback migration chỉ dùng trong DB test, không chạy trên dữ liệu thật.
- [ ] Viết failing tests fake sender: 120 delivery/user, tại t=0 chỉ 1 lần sender; trước 1 giây vẫn 1; tại 1 giây thêm 1; queue còn đủ; user thứ hai không bị backlog user đầu chặn. Hai thiết bị cùng user dùng chung slot, hai user khác nhau có thể gửi cùng lúc. Account/security miễn optional gate.
- [ ] Thêm ca lease/CAS worker thua không dùng slot; deferred giữ nguyên attempts, giữ delivery ID, clear lease, update available_at/last_code; config rate sai không gọi sender; cache outage không làm thất thoát lịch sử hoặc phát push ngoài throttle. Thêm worker bị tạm dừng sau khi nhận slot: slot tiếp theo vẫn đúng thời điểm admission, không khẳng định thứ tự/khoảng cách HTTP thực tế.
- [ ] Thêm ca chờ 2 giờ chưa từng gửi -> expires_at vẫn NULL và sau đó gửi được; lần đầu cấp slot đặt expiry now+3600; retry không nới expiry; row cũ có deadline vẫn giữ; UNKNOWN/lease expired không tự retry; canary không thay số lượt cho phép.
- [ ] Chạy test mới để thấy thất bại trước implementation. Implement DB gate, nullable expiry handling, defer sau policy check, deadline/budget 20 giây bằng monotonic clock, sorting deterministic `(available_at, created_at, id)`.
- [ ] Nếu batch toàn cùng user, chuyển những row bị hoãn khỏi tập đến hạn rồi đánh thức tiếp; kiểm thử user khác cuối batch được tiến triển trong các lượt kế, không vòng lặp busy-spin. Không giữ transaction khi gọi FCM.
- [ ] Thêm `NotificationPushQueueConcurrencyTest`: hai process/drainer trên cùng DB PostgreSQL test, cùng thời điểm một user chỉ một sender call; một delivery chỉ một claim. Test yêu cầu DSN PG test explicit và guard disposable tương tự PushDeliveryTestCase; thiếu PG thì ghi chưa kiểm chứng, không coi skip là pass concurrency.
- [ ] Sửa transport pruner: chỉ prune terminal sau 30 ngày theo updated_at, giữ toàn bộ pending/retry/configuration/sending kể cả cũ; giữ original token cleanup/version fences. Test NULL expiry và suppression trước first attempt.
- [ ] Thêm config/env `max_push_per_user_per_minute`/`NOTIFICATION_MAX_PUSH_PER_USER_PER_MINUTE=60`, `drain_time_budget_seconds`/`NOTIFICATION_DELIVERY_DRAIN_BUDGET_SECONDS=20`, sử dụng message_ttl_seconds hiện có; ngừng dùng legacy cap lịch sử. Test config bounds 1/60/600 và invalid 0/âm/không nguyên.
- [ ] Chạy push queue/outbox/prune/canary/transport tests; kiểm tra migration up trên SQLite memory và PostgreSQL disposable; commit sau khi đạt.

## Task 3: Worker riêng, tự tiếp tục backlog và cảnh báo

**Interfaces:**

- `config('queue.notification_delivery_queue')` đọc `NOTIFICATION_DELIVERY_QUEUE`, default `notification-delivery`.
- `DispatchNotificationDeliveries`: giữ ShouldQueue, thêm ShouldBeUniqueUntilProcessing, `uniqueId(): string` trả `notification-delivery:drain:v1`, uniqueFor=30 giây; constructor chọn đúng queue. `handle()` gọi drain, rồi lên lịch wake tiếp theo từ nextWakeAt với delay 1..5 giây nếu được phép. Sync connection không dispatch job nối tiếp hoặc gọi provider từ notify.
- `MonitorNotificationBurst` bổ sung số lượng theo state, rate-deferred đang chờ, oldest_wait_seconds, oldest_overdue_seconds; giữ các keys cũ. Status alert theo spec, cảnh báo tổng hợp.

- [ ] Viết failing tests Queue fake cho queue routing/after-commit/rollback, nhiều enqueue được coalesce ở wake-up nhưng mọi DB row vẫn đủ; delayed continuation có đúng available_at, không xuất hiện khi terminal/disabled, không recursive dispatch khi sync. Khi dispatch/cache unique lock lỗi, scheduler vẫn drain DB.
- [ ] Viết ca fake sender chậm và fake clock chứng minh drain dừng lấy thêm việc sau ngân sách, các row chưa claim vẫn chờ. Không dùng sleep dài trong test.
- [ ] Implement job, queue config, after-commit wake-up guard và scheduler backstop; command manual tiếp tục drain có budget. Bảo đảm scheduler và worker dùng cùng gate, không lách throttle.
- [ ] Mở rộng supervisor thành ba process; khi một consumer chết, cleanup hai process còn lại rồi để Docker restart. Consumer đọc cùng NOTIFICATION_QUEUE/NOTIFICATION_DELIVERY_QUEUE với producer, kể cả khi override tên. Update compose/perf và template để listener dùng notifications, push dùng notification-delivery, export dùng default. Kiểm tra không có override --queue=default bỏ mất consumer push ở stack khác.
- [ ] Test supervisor bằng php giả ghi argv và process state trong temp directory; chứng minh đủ ba consumer, SIGTERM cleanup và một consumer chết được xử lý. Trên Windows thiếu POSIX shell thì yêu cầu chạy kiểm tra này trong Linux CI/stack test, không tự đánh dấu đạt.
- [ ] Viết test monitor: queue size=0 nhưng 101 pending -> alert; pending nhỏ nhưng overdue=301 -> alert; future-due nhỏ không quá 300 -> không cảnh báo tuổi; CONFIGURATION_ERROR/UNKNOWN -> alert; deferred không tính là failed job; log không có token/nội dung.
- [ ] Implement monitor thresholds `NOTIFICATION_MONITOR_DELIVERY_THRESHOLD=100`, `NOTIFICATION_MONITOR_OVERDUE_SECONDS=300`, đồng bộ .env.example và runbook. Chạy tests job/monitor/supervisor và commit.

## Task 4: Chặn toast liên tục, vẫn đồng bộ đủ badge

**Interfaces:**

- Tạo `createNotificationToastBudget(options?: { now?: () => number; maxIndividual?: number; windowMs?: number }): { take(count: number): number; reset(): void }`, defaults 3 và 10.000ms. Sliding window dùng timestamp các lượt đã cấp, không fixed-minute window.
- `createNotificationBurst(onFlush, windowMs=400)` giữ API hiện có, flush trước khi thêm item thứ 1.001 thay vì xóa item cũ; dispose hủy pending theo lifecycle hiện tại, reconnect sẽ reconcile DB.
- `showNotificationToasts(items: NotificationItem[], individualLimit?: number): void` thêm tham số tương thích caller cũ; số summary phải tính cả những item chưa được cấp individual slot.
- `useNotificationSocket` sở hữu budget/summary timer trong effect theo user, cập nhật cùng summary ID tối đa 1 lần/giây. Summary đếm unique ID không hiện riêng còn trong 10 giây gần nhất, dismiss khi count=0. Cleanup khi logout/user change/hidden hoặc route vào trang notifications; timer phải kiểm tra lại visibility/route trước khi hiện, không phát lại summary cũ lúc quay về.

- [ ] Viết fake timer tests: 120 events một burst -> badge+120, 3 individual và summary117; 10 batch trong 10 giây vẫn tối đa3 individual, summary gộp đủ; t=10 giây slot cũ hết hạn; cùng ID không tăng badge/toast hai lần.
- [ ] Thêm 1.201 unique events trong 400ms -> mọi ID qua onFlush đúng một lần, buffer hữu hạn; hidden -> không toast chờ; đổi user/logout -> không phát summary từ user trước; route sang notifications trước khi timer chạy -> hủy summary; t=10 giây summary cũ hết cửa sổ không cộng vào burst mới; preference kho tắt -> badge/history vẫn đồng bộ, không toast.
- [ ] Run tests để thấy lỗi hiện tại; implement budget, early flush, summary cadence và cleanup. Giữ màu/layout/i18n hiện có; dùng các chuỗi summary VI/EN/ZH đang có.
- [ ] Update canonical guide mục9 với giới hạn qua nhiều batch; không tạo guide UI cạnh tranh. Run notification-focused Vitest và npm run typecheck; nếu thay đổi thông điệp thì kiểm tra catalog VI/EN/ZH.
- [ ] Commit FE độc lập, ghi rõ SHA BE/FE tương ứng.

## Task 5: Kiểm chứng tổng thể và hướng dẫn đưa vào vận hành

- [ ] Tạo fixture 120 sự kiện kho/1 user/1 thiết bị và fake provider thành công: listener lưu đủ history/recipient, API phân trang đọc đủ, unread=120; worker xử lý dần không mất rows, restart không gửi lại accepted. Test thêm rollback và token/permission revoked giữa lúc chờ, lịch sử không đổi.
- [ ] Run BE regression notification history/profile/P13/timestamps/retention/outbox/canary/prune/registration/selftest/transport, queue monitor và PG concurrency. Báo riêng test bị skip hoặc dependency thiếu; không lắp dependency hay bypass canary chỉ để xanh test.
- [ ] Run FE focused tests và typecheck. Trình duyệt: chuông, trang lịch sử, liên tiếp burst, hidden/visible, opt-out, đổi user, đa tab; ghi rõ chỉ toast đang được giới hạn, badge/lịch sử vẫn đủ. Không suy ra đã kiểm thử UI từ Vitest.
- [ ] Hoàn thiện runbook: migration trước; chạy đủ ba consumers trước routing; config cache/restart đúng deployment hiện có; monitor backlog/oldest age; không tự bật production FCM. Rollback chỉ tắt transport/continuation và giữ persist mới, không đưa DROP trở lại.
- [ ] Review diff so với spec; không stage evidence/dirty files có sẵn. Chỉ sau khi người dùng cho phép deploy mới thực hiện thay đổi VPS; plan này không tự deploy.

## Lệnh kiểm chứng

Từ BE trong PowerShell, ép DB disposable ngay trong process trước mọi test có migrate:fresh:

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
$env:CACHE_STORE='array'
$env:QUEUE_CONNECTION='sync'
$env:BROADCAST_CONNECTION='null'
$env:NOTIFICATION_DELIVERY_ENABLED='false'
php vendor/phpunit/phpunit/phpunit --filter 'NotificationHistoryPersistenceTest|ProfileNotificationDeliveryTest|NotificationRetentionPruneTest|NotificationPushQueueTest|NotificationDeliveryOutboxTest|NotificationDeliveryPruneTest|NotificationAndroidCanaryTest|NotificationFcmTransportTest|NotificationDeliveryRegistrationTest|NotificationDeliverySelfTest|P13Notification|NotificationHistoryTimestampTest|NotificationQueueSupervisorTest' --do-not-cache-result
```

Đối với test job routing, test tự fake Queue và cấu hình connection phù hợp trong fixture, không gọi provider thật. Test PostgreSQL concurrency phải dùng process/lệnh riêng với DSN disposable explicit, không dùng DB dev và không chạy chung với SQLite env trên. Kết quả chấp nhận: các test được chạy đều PASS, không bỏ qua lỗi; PG concurrency và Linux supervisor có bằng chứng riêng hoặc ghi rõ còn thiếu.

Từ FE:

```powershell
pnpm exec vitest run src/modules/notifications/utils/notification-burst.test.ts src/modules/notifications/utils/notification-toast.test.ts src/modules/notifications/utils/notification-toast-budget.test.ts src/modules/notifications/hooks/useNotificationSocket.session-replaced.test.tsx --maxWorkers=1
npm run typecheck
```

## Các quyết định cần người dùng duyệt cùng thiết kế

- Tái sử dụng outbox DB, push gửi lần lượt; không gộp/xóa lịch sử hoặc tạo digest push mới.
- Default60 lần thử push/user/phút cộng trên mọi thiết bị; toast3/10 giây và một summary.
- Preference hiển thị không xóa lịch sử kho; retention không tự xóa recipient kho.
- TTL push mới bắt đầu lúc gửi lần đầu; trạng thái provider không chắc chắn vẫn không tự retry.
- Triển khai native trong phiên hiện tại sau khi duyệt; không tự deploy VPS.
