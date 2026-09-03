# Bộ đo tải WMS Hoà Nam (k6)

Thư mục này **không** thuộc mã chạy thật. Không có gì trong `app/`, `Modules/`
hay `config/` phụ thuộc vào nó; xoá cả thư mục đi thì hệ thống vẫn chạy nguyên
vẹn.

## Chạy nhanh

```bash
docker compose up -d
```

```bash
docker compose -f load/docker-compose.k6.yml run --rm k6 run -e PROFILE=smoke //scripts/wms-load.js
```

> Dấu `//scripts` (hai gạch chéo) là cách chặn Git Bash trên Windows tự đổi
> `/scripts/...` thành đường dẫn Windows. Trên PowerShell/Linux dùng
> `/scripts/wms-load.js` như bình thường.

## Bốn hồ sơ đo

| `PROFILE`  | Dùng để làm gì | Thời lượng |
|-----------|----------------|-----------|
| `smoke`    | Kiểm tra script và môi trường trước khi đo thật | 30 giây |
| `baseline` | Mức nền 1/4 tải mục tiêu — dùng để so sánh trước/sau khi tối ưu | ~4,5 phút |
| `target`   | Đúng tải của `USERS` người dùng, giữ 5 phút | ~7,5 phút |
| `stress`   | Đẩy tới 2 lần tải mục tiêu để tìm điểm gãy | ~7,5 phút |

## Tham số

| Biến | Mặc định | Ý nghĩa |
|------|----------|---------|
| `USERS` | `3000` | Số người dùng đồng thời cần mô phỏng |
| `THINK` | `8` | Số giây trung bình giữa hai thao tác của một người |
| `BASE_URL` | `http://nginx` | Đích đo. Mặc định gọi thẳng container nginx trong mạng docker |
| `P95_MS` | `800` | Ngưỡng đạt: 95% request phải nhanh hơn mức này |
| `ERROR_RATE` | `0.01` | Ngưỡng đạt: tỉ lệ lỗi tối đa |
| `LOAD_USERS` | `admin,manager01,...` | Danh sách tài khoản lấy JWT trong `setup()` |
| `LOAD_PASSWORD` | `Cntt@1234` | Mật khẩu của các tài khoản trên |

Tải mục tiêu suy ra từ hai tham số đầu:

```
số request mỗi giây = USERS / THINK
```

3000 người với think-time 8 giây tạo ra **375 request/giây**.

## Vì sao dùng mô hình mở (open model)

`k6` được cấu hình theo `ramping-arrival-rate`: bộ phát giữ đúng số request mỗi
giây bất kể hệ thống trả lời nhanh hay chậm.

Mô hình đóng (N luồng, mỗi luồng gửi xong mới gửi tiếp) tự động giảm tải khi hệ
thống chậm lại — nghĩa là nó **che mất** điểm quá tải: hệ thống càng chậm, phép
đo càng nhẹ tay. Mô hình mở giữ nguyên áp lực, nên khi vượt ngưỡng ta nhìn thấy
đúng ba dấu hiệu: `p95` tăng vọt, `dropped_iterations` khác 0, và tỉ lệ lỗi tăng.

## Phạm vi phủ

`k6/lib/flows.js` liệt kê 31 endpoint đọc, chia theo nhóm nghiệp vụ và có trọng
số phản ánh tần suất dùng thật: bảng điều khiển, tồn kho, dữ liệu nền, chứng từ
nhập/xuất/bảo hành, báo cáo nặng, quản trị người dùng, chuông thông báo, phiên
đăng nhập và cổng công khai.

**Toàn bộ là request đọc.** Đây là lựa chọn có chủ ý: chạy kịch bản ghi ở mức
vài trăm request/giây sẽ sinh hàng chục nghìn phiếu rác vào cơ sở dữ liệu. Muốn
đo cả đường ghi thì trỏ stack sang bản sao dữ liệu (`PERF_DB_DATABASE`) rồi mới
bật — xem `.env.perf.example`.

## Lưu ý khi đọc kết quả trên máy dev

Trình sinh tải chạy **cùng một máy** với hệ thống bị đo, nên ở mức tải cao chính
k6 cũng giành CPU với backend. Con số thu được là **cận dưới** của năng lực
thật. Muốn số chính xác thì chạy k6 từ một máy khác:

```bash
docker run --rm -i -v "$PWD/load/k6:/scripts:ro" grafana/k6 run -e BASE_URL=http://<ip-server>:8080 -e PROFILE=target //scripts/wms-load.js
```
