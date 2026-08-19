# WWHN-382 — Source disposition & supersession (DEV328-P1-02R4, DEV328-P2-01R4)

Danh sách máy đọc được: [`CANDIDATE_PIN.json`](./CANDIDATE_PIN.json) → khoá `superseded`.
Mọi SHA ở đó là **đủ 40 ký tự** và do script sinh, không gõ tay.

## 1. Nguồn hiện hành (canonical)

| Thành phần | Repo | Branch | Cách lấy SHA |
|---|---|---|---|
| Backend | `https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE.git` | `m1` | `CANDIDATE_PIN.json` → `candidate.backend.sha` |
| Frontend | `https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE.git` | `m1` | `CANDIDATE_PIN.json` → `candidate.frontend.sha` |

SHA cố ý **không** được chép tay vào tài liệu này. Chép tay là cách mà các vòng trước tạo ra hai
con số khác nhau cho cùng một candidate; ở đây chỉ có một nguồn phát hành duy nhất.

## 2. Nguồn bị thay thế — và vì sao

| Nguồn | Loại | Vì sao không còn là nguồn đánh giá |
|---|---|---|
| `refs/heads/m1-be-2` @ `60816499066f9f493596e326dc41116ef32f5c4b` | nhánh lịch sử (luồng BE2) | Không chứa data-scope dashboard, không chứa KPI matrix. Mọi kết luận gắn với nhánh này thuộc về giai đoạn trước khi khiếm khuyết P0 được phát hiện. |
| `b8cfcf01eb0ef01661bb58a7c1bb08825e35e8f3` | commit BE | Sau SHA này runtime dashboard đổi **hai lần**: Valkey application caching (`0b32578c679383b0c145547207e35f579cd725d9`), rồi data-scope + cache key + aggregate. Kết quả test gắn với nó không còn mô tả hệ thống hiện tại. |
| `6db0965940a6ae8b22a591d50705f258326b172d` | commit BE | Candidate vòng R3. Bị thay vì fixture `Wwhn328DashboardQuickContractTest` tạo kho ACTIVE thứ hai ⇒ 17/17 đỏ trên PostgreSQL (SQLite xanh giả). |
| `127c573a7236ad1c25829eb92011a4914360b840` | commit BE | Candidate R4 đầu vòng. Bị thay vì ba lý do độc lập: (a) là commit gộp `gỡ cờ 13`, không mang mã Jira nên không đọc được phạm vi task; (b) WWHN-254 sau đó đổi `dashboardWarehouseScope` → `reportWarehouseScope`, khiến matrix v1.1 trỏ vào method không tồn tại; (c) chưa có test khoá ngoại lệ warranty GLOBAL và bất biến đối soát. |
| `3ddce525e2e3b3af29723d1ec97e3fc9eff175b3` | commit FE | Candidate FE vòng R3, trước khi UI khai ngoại lệ warranty GLOBAL. Giao diện khi đó nói "giới hạn N kho" trong khi thẻ Bảo hành vẫn GLOBAL — quy kết sai số liệu ở tầng UI. |
| `99dff42fb4272ed8930e8fb9bf4da6368f4cfd96` | commit FE | **Không phải bị loại — là nguồn đầu vào đã hợp nhất.** Đây là output WWHN-327 được tiêu thụ (ngoại lệ warranty + gate closure FE). Ghi ở đây để DEV328-P1-05R4 có mốc "đúng output nào đã được dùng". |
| `4d11989a1b9499dd388ca1e75f408bfc27b183cc` | commit FE | Candidate FE R4 đầu vòng, commit gộp `gỡ cờ 13`, cùng lý do (a) như bản BE. |

## 3. Historical trace — vì sao không xoá (DEV328-P2-01R4)

Register R4 yêu cầu chuyển narrative sang unified DEV/Quang và giữ BE2/Duy/`m1-be-2` **dưới mục
historical**. Hồ sơ này giữ nguyên yêu cầu đó, và nói thêm lý do kỹ thuật cho việc *giữ* thay vì
*xoá*:

- Các comment vòng R2/R3 đang trích dẫn kết quả test gắn với `b8cfcf0` và `m1-be-2`. Xoá hai
  mốc đó khỏi hồ sơ thì những comment ấy trở thành trích dẫn treo — người đọc lại vòng sau không
  có cách nào biết con số cũ đến từ đâu và vì sao không dùng được nữa.
- `m1-be-2` vẫn tồn tại trên remote. Một nhánh còn sống mà hồ sơ im lặng thì lần review sau sẽ
  lại hỏi đúng câu hỏi này. Khai nó ở trạng thái *superseded, không dùng làm nguồn đánh giá* là
  cách duy nhất đóng câu hỏi vĩnh viễn.

Phân vai hiện hành: **DEV/Quang** là owner thực thi duy nhất của WWHN-328/WWHN-382 trên `m1`.
Mọi nhắc tới BE2/Duy Khánh trong description cũ thuộc về historical trace, không phải phân công
hiện hành. Thao tác sửa field trên Jira thuộc PM/BAS.

## 4. Chống "moving m1" mà không cần đóng băng nhánh

Yêu cầu "không dùng moving `m1` làm evidence cuối" thường được đáp bằng cách tạo nhánh/tag đóng
băng. Ở dự án này cách đó không dùng được: nhiều phiên làm việc song song trên cùng `m1`, thêm ref
chỉ tạo thêm thứ phải đồng bộ. Cách dùng ở đây là **bằng chứng theo nội dung**:

1. `CANDIDATE_PIN.json` ghi `semantic_anchor` — commit **cuối cùng chạm ngữ nghĩa** dashboard
   (không phải commit cuối chạm tệp; tệp bị chạm liên tục bởi task khác).
2. Chứng minh bất biến trên toàn dải, chạy lại được bất cứ lúc nào:

   ```bash
   node scripts/wwhn-328-contract-fingerprint.mjs \
     --check docs/evidence/WWHN-382/CONTRACT_PIN.json \
     --range <semantic_anchor.commit>..HEAD
   ```

   Lệnh in ra số commit đã kiểm và đỏ ngay tại commit đầu tiên làm lệch hợp đồng.

Hệ quả: `m1` chạy tiếp bao nhiêu cũng không làm hồ sơ hết hạn, vì cái được pin là **hợp đồng**,
không phải vị trí con trỏ nhánh. Ngược lại, ai sửa ngữ nghĩa dashboard mà không nâng KPI matrix
thì cổng đỏ — đúng thứ mà "pin theo tên nhánh" không bao giờ làm được.
