# WWHN-240 — Formal supersede nguồn: `m1-be-2` → `m1` (DEV240-P1-03R)

## Kết luận

Nguồn chính thức (source of truth) cho code WWHN-240 là nhánh **`m1`**, không phải
`m1-be-2`. `m1-be-2` là nhánh làm việc tạm thời đã được đưa vào `m1` từ lâu và nay đã
lạc hậu hoàn toàn phía sau `m1`.

## Bằng chứng ancestry (đọc trực tiếp từ git, không suy đoán)

- `git merge-base --is-ancestor 9640091 HEAD` → `YES`
- `git rev-list --count 9640091..HEAD` = **125** commit (đo lúc HEAD = `e02903f1fc6b7635c2deac84a0284f49ddfb0fdd`, 2026-08-19) — tăng dần vì `m1` vẫn đang được nhiều phiên khác commit tiếp, không phải số cố định.
- Chain bắt buộc từ ancestor tới candidate: `c855851` → `f81f7b7` (evidence WWHN-244) → `b64cc90` (evidence WWHN-254) → `9640091` (code WWHN-240 chính, `buildXnt`/`traceSource`/`correctionChain`/`config/l5-swagger.php`).
- `git fetch origin m1-be-2 m1 --quiet` rồi `git rev-list --left-right --count origin/m1-be-2...origin/m1` = `0  814` → **`m1-be-2` có 0 commit vượt trước `m1`**, tức mọi thay đổi trên `m1-be-2` đã nằm trong `m1` từ lâu (không phân kỳ), còn `m1` đã đi trước `m1-be-2` 814 commit.

## Trạng thái PR

`gh pr list --repo Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE --search "WWHN-240" --state all`
và `--head m1-be-2 --state all` → **0 kết quả** cho cả hai truy vấn (đã sanity-check `gh`
CLI hoạt động đúng bằng truy vấn không filter trước khi kết luận 0 là thật, không phải do
lỗi lệnh). Không có PR nào từng tồn tại cho `m1-be-2`. Đây khớp với quy ước hiện hành của
repo: phần lớn các task WWHN khác trên `m1` cũng merge thẳng bằng commit trực tiếp, không
qua PR — nên "không có PR" không phải sai lệch quy trình riêng của WWHN-240, mà là cách
làm nhất quán toàn dự án tại thời điểm này. Nếu muốn đổi sang bắt buộc PR, đó là quyết định
quy trình cần Tech Lead/PM ban hành áp dụng chung, không phải fix riêng cho WWHN-240.

## Vì sao candidate "không có CI status"

`m1-be-2` chưa từng được push thành PR nên không có run CI nào gắn với nó — đúng như kỳ
vọng, không phải defect. CI (`current-baseline-doc-contract.yml`) chạy trên `m1` mỗi lần
push lên nhánh này; phiên làm việc hiện tại **chủ động không** trigger thêm run mới (ngân
sách Actions đã dùng hết 3000/3000 phút) — nên xác nhận trạng thái xanh gần nhất phải lấy
từ run CI đã có sẵn trên `m1` (không tự chạy thêm ở đây).

## Việc cần Tech Lead xác nhận (sign-off, không cần code mới)

Chấp nhận `m1` là nguồn chính thức duy nhất cho WWHN-240 kể từ nay, đóng băng việc còn pin
theo `m1-be-2` trong Jira/tracking.
