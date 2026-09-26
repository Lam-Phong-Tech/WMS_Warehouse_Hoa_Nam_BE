# Testing Quote — Public Storefront 1.2.0

## Trạng thái và phạm vi

Contract 1.2.0 giữ nguyên. Bổ sung sandbox 21 SKU và kiểm thử, không đổi catalogue
vận hành, không thêm endpoint reset công khai, không bỏ kiểm tra Origin/consent.
Commit trên GitHub và `contract_version` KHÔNG chứng minh đã deploy lên Testing.
URL ở dưới chỉ tồn tại trên máy thực sự khởi chạy sandbox; không phải URL remote
đã được bàn giao. DevOps phải xác nhận bản chạy và địa chỉ trước khi FE gửi POST.

## BE/QA khởi chạy sandbox tách biệt

Dùng checkout sạch của nhánh `dev`, Docker và Docker Compose. Không dùng thư mục
có dữ liệu/secret vận hành, không truyền .env của hệ thống thật. Các lệnh dưới chỉ
khởi chạy compose project `hn-public-quote-sandbox`, không sửa compose dự án kho.

```sh
git switch dev
git pull --ff-only origin dev
test -z "$(git status --porcelain)" || exit 1
export HN_SANDBOX_BUILD_SHA="$(git rev-parse HEAD)"
docker build -f docker/php/Dockerfile -t hn-public-sandbox-runtime .
docker compose -f docker/public-quote-sandbox/compose.yml up --build -d
# Đợi log báo API đã khởi chạy; migrate chạy trên SQLite riêng, KHÔNG db:seed.
docker compose -f docker/public-quote-sandbox/compose.yml logs --tail=30 api
# Chỉ JSON manifest không bí mật; KHÔNG gửi keys.env cho FE.
docker compose -f docker/public-quote-sandbox/compose.yml exec -T api \
  sh docker/public-quote-sandbox/console.sh wms:public-quote-sandbox manifest > sandbox-manifest.json
curl --fail http://127.0.0.1:18080/api/v1/public/config
node scripts/verify-public-quote-sandbox.mjs sandbox-manifest.json \
  --allow-sandbox-writes public-quote-smoke.json
```

Sandbox chỉ bind loopback `127.0.0.1:18080`; FE ở máy khác dùng SSH tunnel được
DevOps phê duyệt, không tự mở cổng công khai. Docker network `internal` chặn egress;
mailer=array, broadcast=null, queue=sync; DB/khóa riêng trong volume của sandbox.
Không có worker hay tài khoản vận hành. `APP_BUILD_SHA` được đóng vào image;
`declared_build_sha` trong manifest vẫn là khai báo, cần đối chiếu image đang chạy.

Chuẩn bị bằng CLI trực tiếp ngoài Docker chỉ được phép ở local/testing, opt-in
`PUBLIC_QUOTE_SANDBOX_ENABLED=true`, mailer=array/queue=sync/broadcast=null và DB
`hn_public_quote_test` (PG) hoặc `hn_public_quote_test.sqlite`. Cấm dùng DB kho.
Lệnh từ chối khi có SKU ngoài bộ sandbox. CLI không tự bảo đảm egress của hạ tầng:
BE/DevOps phải chặn tích hợp đi ra ngoài như compose sandbox đã cấu hình.

## FE cần nhận và kiểm thử

Nhận **base URL đã xác minh**, commit/image đang chạy, `sandbox-manifest.json` và
bằng chứng smoke. Manifest chứa **21 ID khác nhau** đã được public read model đọc
lại, tên test `SYNTHETIC UAT ONLY`, điện thoại `0000000000` chỉ dùng trong sandbox.
Đây không phải thông tin liên hệ thật hay bộ ảnh chuẩn pixel-compare.

Giữ mapping `data.page_info.{total,next_cursor,has_more,limit}` cho response 200
Products/Search/Related có phân trang; `ids[]` và response lỗi không có page_info.
Total đếm SKU theo filter, không lấy tổng domain làm tổng search. Đọc Quote limits
và privacy version từ config; không lấy contract_version làm privacy_version.

Smoke tạo 2 yêu cầu test: 1 dòng và 20 dòng. Replay cùng key+payload trả 200 cùng
`data.request_id`; cùng key đổi note trả 409; 21 ID khác nhau trả 422. Dùng khóa bắt
đầu `hn-uat-quote-` để reset có scope. Khi timeout giữ nguyên khóa và payload; không
đổi items/privacy hoặc sinh khóa mới. 429/503 không chứng minh lần trước chưa lưu.

Reset Quote chỉ khi không còn request đang chạy và đã lưu bằng chứng:

```sh
docker compose -f docker/public-quote-sandbox/compose.yml exec -T api \
  sh docker/public-quote-sandbox/console.sh wms:public-quote-sandbox reset-quotes \
  --confirm=RESET_SANDBOX_QUOTES
```

Chỉ xóa Quote có prefix test và sản phẩm thuộc scenario; giữ nguyên SKU và các
request không có prefix đó. Muốn thử lại sau reset dùng khóa mới. Không reset
trong lúc đang nghiệm thu idempotency. Dừng sandbox bằng `docker compose -f
docker/public-quote-sandbox/compose.yml down` (giữ volume).

## Bằng chứng và giới hạn

Workflow public dùng runner labels của dự án, DB test riêng/random host port,
chạy suites hiện có + guard sandbox + Origin. SQLite job còn build sandbox và
thực hiện HTTP thật qua loopback rồi lưu `public-quote-smoke.json`. Không deploy
và không gọi Quote trên dev/live. Kết quả CI cần đọc theo đúng SHA, không suy ra
PASS chỉ vì đã thêm test. Nghiệm thu Testing từ FE vẫn là bước riêng.

Scenario 7 model/SKU cùng ảnh chuẩn do BA/Thiết kế/QA chốt riêng. Không lấy 21 SKU
synthetic này thay cho canonical reference và không cắt catalogue live về 7.
