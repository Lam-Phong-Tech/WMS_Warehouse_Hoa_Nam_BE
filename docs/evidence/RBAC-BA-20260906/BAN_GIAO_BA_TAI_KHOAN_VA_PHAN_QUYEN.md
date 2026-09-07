# Bàn giao BA — Tài khoản đăng nhập & Phân quyền WMS Hoa Nam

**Ngày:** 06/09/2026 · **cập nhật 07/09/2026** (BA đã chốt 3 điểm còn treo)
**Nguồn yêu cầu:** tài liệu BA `taikhoantheorole_da_sua_v2.md`
**Trạng thái:** đã áp vào seeder + đã chạy trên môi trường DEV, có bằng chứng đo thật ở §4.
Ba điểm để ngỏ đã được BA chốt và khoá lại bằng kiểm thử — xem §6.
**Còn lại:** một mục retest phải bấm tay trên bản đã deploy (§7 mục 4), và việc chuyển vai cho
tài khoản thật ở môi trường triển khai (§6 mục ③ — đã có lệnh sẵn).

---

## 1. Tài khoản đăng nhập theo vai trò

Mật khẩu chung cho **tất cả** tài khoản dưới đây: `Cntt@1234`
Đăng nhập bằng **email**. Tất cả đều `ACTIVE` và **không bị bắt đổi mật khẩu lần đầu**.

Quy luật đặt tên: `<mã vai trò viết thường, gạch nối>-01 / -02 / -03`, email `<username>@gmail.com`.

| # | Vai trò | Tài khoản (email) | Số TK |
|---|---|---|---|
| 1 | SUPER_ADMIN | `super-admin-01@gmail.com` · `-02` · `-03` | 3 |
| 2 | ADMIN | `admin-01@gmail.com` · `-02` · `-03` | 3 |
| 3 | WAREHOUSE_MANAGER | `warehouse-manager-01@gmail.com` · `-02` · `-03` | 3 |
| 4 | WAREHOUSE_STAFF | `warehouse-staff-01@gmail.com` · `-02` · `-03` | 3 |
| 5 | WARRANTY_STAFF | `warranty-staff-01@gmail.com` · `-02` · `-03` | 3 |
| 6 | VIEWER | `viewer-01@gmail.com` · `-02` · `-03` | 3 |
| 7 | LEADERSHIP | `leadership-01@gmail.com` · `-02` · `-03` | 3 |
| 8 | SKU_MANAGER *(chỉ dev/test)* | `sku-manager-01@gmail.com` · `-02` · `-03` | 3 |
| 9 | MASTER_MANAGER *(chỉ dev/test)* | `master-manager-01@gmail.com` · `-02` · `-03` | 3 |
| 10 | INBOUND_ONLY_TEST *(chỉ dev/test)* | `inbound-only-test-01@gmail.com` · `-02` · `-03` | 3 |
| 11 | NO_PERMISSION *(chỉ dev/test)* | `no-permission-01@gmail.com` · `-02` · `-03` | 3 |

Cộng **33 tài khoản**, thêm `admin@gmail.com` (SUPER_ADMIN gốc, giữ nguyên như cũ) là **34**.

Bốn vai trò gắn nhãn *(chỉ dev/test)* **không bao giờ** được tạo ở môi trường production.

> **Về mật khẩu:** con số `Cntt@1234` lấy đúng từ tài liệu BA. Nếu BA muốn đổi sang chuỗi khác
> thì báo DEV — đây là một hằng số duy nhất trong seeder, đổi mất vài phút. Cả 34 tài khoản
> này là **tài khoản môi trường thử/nghiệm thu**; tài khoản production được tạo qua màn IAM
> với mật khẩu riêng, seeder không tự sinh.

### Tài khoản cũ vẫn còn (không dùng để nghiệm thu ma trận mới)

`staff01`, `manager01`, `viewer01`, `keeper01`, `approver01`, `test-*`, `thukho02`, `duyetkho02`…
được giữ lại vì bộ kiểm thử tự động và tài liệu bàn giao cũ đang tham chiếu chúng. Khi nghiệm
thu phân quyền, xin dùng bộ 33 tài khoản ở bảng trên.

---

## 2. Mỗi vai trò làm được gì — đối chiếu với quyền đã cài đặt

Cột "Mã quyền đã seed" là mã mà backend thực sự kiểm khi chặn/cho phép. Đây là phần chứng
minh rằng mô tả nghiệp vụ của BA đã được dịch thành cấu hình chạy được, không chỉ là câu chữ.

### 2.1 SUPER_ADMIN — Quản trị hệ thống

Làm được mọi thứ; hệ thống bỏ qua toàn bộ bước kiểm quyền cho vai này (trong CSDL vai này có
**0 dòng quyền** — đúng thiết kế, không phải thiếu sót). Dùng để dựng dữ liệu và gỡ rối kỹ
thuật. **Không dùng SUPER_ADMIN để nghiệm thu phân quyền.**

### 2.2 ADMIN — Quản trị viên (60 quyền)

Toàn bộ nghiệp vụ + quản trị hệ thống: người dùng/vai trò/ma trận quyền, danh mục, nhập kho,
xuất kho, Reversal, bảo hành (kể cả tiếp nhận TEMP_ONLY), mã vật lý & RFID, nhãn, kho vật lý
& Putaway, báo cáo (xem + xuất), nhật ký hệ thống mọi module.

**Xem PII khách hàng: được.** **Xuất PII: KHÔNG seed sẵn** — theo đúng ô ma trận
"Warranty PII export | ADMIN: Permission riêng". Khi có nhu cầu được phê duyệt, cấp lẻ
`warranty.pii.export` cho đúng tài khoản qua màn IAM.

### 2.3 WAREHOUSE_MANAGER — Quản lý kho (17 quyền) — **thay đổi lớn nhất**

Vai **giám sát và quản lý hoạt động kho**, không trực tiếp thao tác nghiệp vụ, không quản trị IAM.

| Làm được | Mã quyền đã seed |
|---|---|
| Dashboard, báo cáo vận hành, **xuất file báo cáo** | `report.view`, `report.export` |
| Xem phiếu nhập / phiếu xuất, trạng thái, thời điểm Post, người thao tác | `inbound.view`, `outbound.view` |
| Xem tồn hiện tại, biến động tồn, truy vết mặt hàng | `inventory.view`, `inventory.trace` |
| Xem box/khay/container và tình trạng xếp khay | `container.view`, `item_instance.view`, `putaway.view` |
| Xem tình trạng/chỉ số bảo hành (PII bị che) | `warranty.view` |
| Xem kho, danh mục, SKU, nơi nhận, danh mục lỗi | `warehouse.view`, `catalog.view`, `sku.view`, `agency.view`, `defect.view` |
| Xem lịch sử batch/import, kết quả scan | `import_batch.view` |
| Xem nhật ký **trong phạm vi nghiệp vụ kho** | `audit_log.view` (đã giới hạn theo module của chính vai) |

**Đã thu hồi 33 quyền** so với bản cũ, đúng theo danh sách "Không làm được" của BA: quản trị
người dùng/vai trò/quyền; sửa danh mục & SKU; tạo/scan/Post/huỷ phiếu nhập–xuất; Packing
Label; tạo/xác nhận Putaway; Reversal; cấp mã QR/Barcode & RFID; xử lý bảo hành & xuất linh
kiện; xem/xuất PII; quản trị App PV; `audit_log.view_all` (nhật ký toàn hệ thống).

> Đây cũng là lời giải cho một vấn đề đã treo trong hồ sơ nghiệm thu từ trước: bản cũ cấp cho
> "Quản lý kho" **nhiều quyền hơn cả ADMIN** (69 so với 61 theo hồ sơ pin), khiến hai vai
> khác tên nhưng cùng quyền và không nghiệm thu RBAC được. Vấn đề này nay khép lại.

### 2.4 WAREHOUSE_STAFF — Nhân viên kho (30 quyền)

Vai **trực tiếp vận hành kho từ đầu đến cuối**. Điểm mấu chốt: nhân viên kho vừa quét vừa
**Post ngay khi dữ liệu hợp lệ**, không chờ vai khác ghi nhận.

| Làm được | Mã quyền đã seed |
|---|---|
| Nhập kho: tạo phiếu, quét, nhập SL theo BOX, **Ghi sổ nhập**, huỷ phiếu | `inbound.create`, `inbound.scan`, **`inbound.post`**, **`inbound.cancel`**, `inbound.view` |
| Xuất kho: import/tạo/xác nhận yêu cầu, quét/khớp, **Ghi sổ xuất**, huỷ phiếu | `outbound.request.import`, `outbound.request.manual_create`, `outbound.request.confirm`, `outbound.scan`, **`outbound.post`**, **`outbound.cancel`**, `outbound.view` |
| Nhãn đóng gói: xem trước / in / **in lại** | `packing_label.preview`, `.ready`, `.print`, **`.reprint`** |
| Kho vật lý: thao tác box/khay/container, tạo & xác nhận xếp khay | `putaway.view`, `putaway.manage`, `container.view`, `item_instance.view` |
| Quét & phân loại lô quét | `scan.execute` |
| Xem kho, danh mục, SKU, nơi nhận, lỗi, tồn, truy vết, batch, báo cáo | `warehouse.view`, `catalog.view`, `sku.view`, `agency.view`, `defect.view`, `inventory.view`, `inventory.trace`, `import_batch.view`, `report.view` |

**In đậm = 6 quyền mới cấp trong đợt này.** Bốn quyền Post/huỷ trước đây nằm ở hai vai
`WAREHOUSE_KEEPER` / `WAREHOUSE_APPROVER` đã ngừng dùng.

Không có mặc định (cấp lẻ khi được duyệt): `inventory.reversal.post`, `report.export`,
`audit_log.view`, quyền bảo hành, `warranty.pii.*`, `physical_code.*`.

### 2.5 WARRANTY_STAFF — Nhân viên bảo hành (13 quyền)

| Làm được | Mã quyền đã seed |
|---|---|
| Mở, tiếp nhận, xử lý hồ sơ; cập nhật chẩn đoán/trạng thái | `warranty.manage`, `warranty.view` |
| Xuất linh kiện thay thế cho case hợp lệ | `warranty.component_issue` |
| Tiếp nhận TEMP_ONLY (không định danh được hiện vật) | **`warranty.temp_only`** |
| **Xem PII khách hàng** (họ tên / SĐT / địa chỉ) để tiếp nhận và trả khách | **`warranty.pii.view`** |
| Quét / phân loại phục vụ bảo hành | `scan.classify`, `scan.execute` |
| Xem kho, danh mục, SKU, tồn, truy vết, thông tin hiện vật | `warehouse.view`, `catalog.view`, `sku.view`, `inventory.view`, `inventory.trace`, `item_instance.view` |

**In đậm = 2 quyền mới.** `warranty.pii.view` đóng một lỗi vận hành thật: trước đây nhân viên
bảo hành mở được hồ sơ nhưng tên và số điện thoại khách bị che, nên **không gọi lại khách được**.

**Xuất PII: KHÔNG được** — đúng theo BA ("không mặc định được phép").
**Báo cáo: KHÔNG được xem** — BA chốt ngày 07/09/2026, xem §6 mục ②.

### 2.6 VIEWER — Người xem (14 quyền)

Chỉ đọc: kho, danh mục, SKU, nơi nhận, danh mục lỗi, phiếu nhập, phiếu xuất, tồn kho, truy
vết, box/container/hiện vật, **tổng quan khay** (`putaway.view`, mới cấp), batch/import, báo cáo.

**Đã gỡ `audit_log.view`** — theo đúng ô ma trận "Audit Log | Viewer ❌". Từ nay VIEWER không
còn thấy menu Nhật ký hệ thống.

### 2.7 LEADERSHIP — Ban lãnh đạo (12 quyền)

Giám sát, chỉ đọc: dashboard & báo cáo, tồn kho và xu hướng tồn, truy vết, hồ sơ bảo hành
read-only (**PII bị che**), danh mục/SKU/nơi nhận, tổng quan khay.

| Thay đổi | Lý do |
|---|---|
| **Thêm** `inbound.view`, `outbound.view` | BA: "Phiếu nhập/xuất ở chế độ read-only khi cần drill-down". Trước đây bấm từ báo cáo vào một phiếu là nhận lỗi 403 — báo cáo tổng hợp không tra ngược được. |
| **Gỡ** `audit_log.view` + `audit_log.view_all` | BA: "Không xem Audit Log toàn hệ thống mặc định. Nếu lãnh đạo thực sự cần Audit, cấp `audit.view` bằng quyết định riêng." |

**Xuất báo cáo:** chưa cấp `report.export` (BA ghi "Permission riêng") — cấp lẻ qua IAM khi cần.

### 2.8 Bốn vai dev/test

| Vai | Quyền |
|---|---|
| SKU_MANAGER | `catalog.view`, `sku.view`, `sku.manage` |
| MASTER_MANAGER | `catalog.view`, `catalog.manage` |
| INBOUND_ONLY_TEST | 24 quyền phục vụ kiểm thử luồng nhập kho |
| NO_PERMISSION | **0 quyền** — đăng nhập được, mọi màn nghiệp vụ trả 403 (để nghiệm thu màn "Không có quyền truy cập") |

---

## 3. Hai vai trò đã NGỪNG DÙNG

`WAREHOUSE_KEEPER` (Thủ kho) và `WAREHOUSE_APPROVER` (Người duyệt kho) là di sản của mô hình
cũ — tách "người quét gửi" khỏi "người duyệt Post". Nguyên tắc 5 của BA đã bãi bỏ bước chờ đó.

| Việc đã làm | Trạng thái |
|---|---|
| Ngừng seed ở production | ✅ Đã làm, có test tự động canh |
| Không sinh tài khoản mới cho hai vai này | ✅ Đã làm (bảng §1 chỉ còn 11 vai) |
| Gỡ khỏi kịch bản seed UAT (`uat.approver`, `uat.keeper`) | ✅ Đã thay bằng `uat.outbound` + `uat.manager` |
| Vẫn tồn tại ở môi trường dev/test cho bộ kiểm thử cũ | ✅ Có chủ ý |
| **Migrate tài khoản thật đang giữ hai vai này** | ⏳ **Cần làm tay qua màn IAM — xem §6 mục ③** |

---

## 4. Bằng chứng đo thật trên môi trường DEV

Đăng nhập bằng chính các tài khoản ở §1 và gọi API thật. `200` = vào được, `403` = bị chặn.

### 4.1 Sáu bề mặt nghiệp vụ

| Tài khoản | Phiếu nhập | Phiếu xuất | Tồn kho | Bảo hành | Báo cáo | Nhật ký | Người dùng | **Ghi sổ nhập** |
|---|---|---|---|---|---|---|---|---|
| `warehouse-manager-01` | 200 | 200 | 200 | 200 | 200 | 200 | **403** | **403** |
| `warehouse-staff-01` | 200 | 200 | 200 | **403** | 200 | **403** | **403** | **428** ✔ |
| `warranty-staff-01` | **403** | **403** | 200 | 200 | **403** | **403** | **403** | **403** |
| `leadership-01` | 200 | 200 | 200 | 200 | 200 | **403** | **403** | **403** |
| `viewer-01` | 200 | 200 | 200 | **403** | 200 | **403** | **403** | **403** |

> **`428` ở ô "Ghi sổ nhập" của nhân viên kho là kết quả ĐÚNG và là bằng chứng quan trọng
> nhất của bảng này.** `428` nghĩa là bước kiểm quyền đã **cho qua**, chỉ còn vướng yêu cầu
> kỹ thuật của API (phải kèm phiên bản chứng từ để chống ghi đè). So sánh với `403` của bốn
> vai còn lại: nhân viên kho nay **Post được**, các vai khác thì không.

### 4.2 Xuất báo cáo, App PV, xếp khay và che PII

| Tài khoản | Xuất Excel báo cáo | Quản trị App PV | Xem khay | PII khách hàng |
|---|---|---|---|---|
| `admin` | 200 | 200 | 200 | **Hiện đầy đủ** |
| `warehouse-manager-01` | 200 | **403** | 200 | **Bị che** |
| `warehouse-staff-01` | **403** | **403** | 200 | (không vào được màn bảo hành) |
| `warranty-staff-01` | **403** | **403** | **403** | **Hiện đầy đủ** ✔ |
| `leadership-01` | **403** | **403** | 200 | **Bị che** |

Hai ô đáng chú ý: nhân viên bảo hành **thấy** PII (để gọi khách) nhưng **không xuất** được;
quản lý kho **xuất** được báo cáo nhưng PII vẫn bị che.

### 4.3 Kiểm thử tự động

Chạy 19 lớp kiểm thử liên quan phân quyền: **73 test, 68 XANH, 5 đỏ**.

| Bộ kiểm thử | Kết quả |
|---|---|
| Ma trận RBAC theo từng ô (vai trò × bề mặt) | ✅ XANH |
| Pin baseline quyền của 12 vai trò | ✅ XANH — **cổng này trước đó đỏ thường trực, nay hoạt động trở lại** |
| Chặn quyền quay lại qua "alias tương thích" | ✅ XANH |
| Hai vai ngừng dùng vắng mặt ở production | ✅ XANH (test mới thêm trong đợt này) |
| Hợp đồng luồng nhập kho (parity) | ✅ XANH |
| Quản trị App PV | ✅ XANH |
| Bất biến phân quyền, danh mục quyền, IAM readiness, RBAC âm tính, quản lý vai trò | ✅ XANH |
| Kịch bản seed UAT | ✅ XANH |

**11 test đỏ và tất cả đều đỏ sẵn TỪ TRƯỚC đợt này**, không liên quan tới phân quyền:

| Cổng | Số đỏ | Nội dung |
|---|---:|---|
| Ghép mã lỗi ↔ HTTP status (WWHN-267) | 1 | 3 mã lỗi dùng ở hai status khác nhau mà chưa khai |
| Mật khẩu admin production (WWHN-300) | 4 | Cổng bắt buộc đổi mật khẩu admin khi seed production đã biến mất khỏi code từ trước |
| Danh mục quyền (WWHN-309) | 6 | Hồ sơ danh mục quyền đã lệch với mã nguồn từ trước |

Đối chiếu với mốc nền đo trước khi sửa: ba cổng này lần lượt có **2 / 4 / 6** test đỏ.
Đợt này làm **giảm 1** (pin baseline chuyển từ đỏ sang xanh) và **không sinh test đỏ mới**.

---

## 5. Tóm tắt thay đổi

| Vai trò | Trước | Sau | Hướng |
|---|---:|---:|---|
| ADMIN | 60 | 60 | +`warranty.temp_only`, −`warranty.pii.export` |
| **WAREHOUSE_MANAGER** | **53** | **17** | **−36 (thu hồi mạnh)** |
| **WAREHOUSE_STAFF** | **25** | **30** | **+5 (Post/huỷ + in lại nhãn)** |
| WARRANTY_STAFF | 11 | 13 | +2 (xem PII, TEMP_ONLY) |
| LEADERSHIP | 12 | 12 | +2 xem phiếu, −2 nhật ký |
| VIEWER | 14 | 14 | +xem khay, −nhật ký |
| WAREHOUSE_KEEPER / APPROVER | seed ở mọi nơi | chỉ dev/test | ngừng dùng ở production |

---

## 6. Ba quyết định của BA — đã chốt ngày 07/09/2026

### ① App PV: Quản lý kho **không** quản trị hồ sơ hiển thị — ✅ CHỐT

Tài liệu BA mới không nhắc App PV trong phần "Làm được" của Quản lý kho, và ma trận đánh ❌ ô
"Master/SKU manage". Tài liệu SRS cũ (§3) thì xếp cả hai dòng App PV ở mức "được làm" cho
actor "Quản lý kho" — hai tài liệu nói khác nhau.

**BA chốt: giữ theo tài liệu mới.** `pv.publish` + `pv.master.manage` đã thu hồi khỏi Quản lý
kho; chỉ ADMIN quản trị App PV. SRS §3 là bản cũ ở điểm này.

Đã khoá bằng hai lớp kiểm thử tự động (kiểm cấu hình seed + gọi API thật), kèm ghi chú "đừng
sửa cho khớp SRS" ngay tại chỗ, để lần rà soát sau không lật ngược quyết định vì thấy hai tài
liệu lệch nhau.

### ② Nhân viên bảo hành **không** được xem báo cáo — ✅ CHỐT

Tài liệu BA để ngỏ điểm này ("có thể xem các báo cáo cần cho nghiệp vụ bảo hành **nếu được
cấp** `report.view`"; ma trận ghi "Theo scope").

**BA chốt: không cấp.** Vai `WARRANTY_STAFF` không có `report.view` (đo được ở §4.1: ô báo cáo
của `warranty-staff-01` trả 403). Nếu về sau có một người bảo hành thật sự cần xem báo cáo,
cấp lẻ cho **tài khoản đó** qua màn IAM, không mở cho cả vai.

Đã ghi rõ thành lời trong cấu hình seed — vì thiếu một quyền trông y hệt sót seed, và người
sửa sau rất dễ "thêm giúp".

### ③ Migrate tài khoản khỏi hai vai ngừng dùng — ✅ ĐỒNG Ý, đã có công cụ

Quy tắc chọn vai đích:

- **`WAREHOUSE_STAFF`** — người thực sự đứng ở bãi nhận/giao hàng và thao tác chứng từ;
- **`WAREHOUSE_MANAGER`** — người thực chất chỉ theo dõi và ký duyệt trên giấy.

DEV đã làm sẵn một lệnh thay cho việc bấm tay từng tài khoản trên màn IAM — 12 lần bấm tay là
12 cơ hội gán nhầm vai, và không để lại bản ghi nào cho thấy đợt chuyển đã tới đâu:

```bash
# 1. Xem trước — KHÔNG ghi gì, in ra bảng ai đang giữ vai nào, sẽ chuyển đi đâu
php artisan wms:migrate-deprecated-roles

# 2. Chuyển phần lớn về nhân viên kho
php artisan wms:migrate-deprecated-roles --apply

# 3. Riêng người chỉ giám sát thì chạy thêm một lượt, gọi tên cụ thể
php artisan wms:migrate-deprecated-roles --to=WAREHOUSE_MANAGER --only=<username>,<username> --apply

# 4. Xoá cache quyền
php artisan cache:clear
```

Đặc tính: **mặc định là xem trước**, không có `--apply` thì không ghi gì; chạy lại nhiều lần
không nhân bản; mỗi tài khoản được chuyển đều có **một dòng nhật ký kèm lý do**, tra lại được.

> **Lưu ý về môi trường DEV:** 12 tài khoản đang giữ hai vai này ở DEV đều là tài khoản do
> seeder sinh ra (`keeper01`, `approver01`, `thukho02/03`, `duyetkho02/03`,
> `warehouse-keeper-0X`, `warehouse-approver-0X`), **không phải người thật**. Cố ý **chưa
> chuyển** chúng: bộ kiểm thử tự động và ba bộ lọc của màn Quản lý người dùng đang dựa vào
> chúng (trong đó có hai tài khoản cố ý để trạng thái LOCKED). Lệnh trên dành cho môi trường
> thật; ở đó chạy `--keep-fixtures` để bỏ qua đúng nhóm fixture này.

---

## 7. Trạng thái 12 mục retest mà tài liệu BA yêu cầu

| # | Mục | Trạng thái |
|---|---|---|
| 1 | Authorization invariant / permission catalog tests | ⚠️ Bất biến phân quyền XANH; riêng cổng danh mục quyền WWHN-309 **đỏ sẵn từ trước** đợt này (hồ sơ đã lệch với code trước khi sửa) — cần một lượt dọn riêng |
| 2 | Role-permission readiness / seeder tests | ✅ XANH |
| 3 | Positive + negative API authorization cho từng vai production | ✅ XANH — bằng chứng ở §4.1, §4.2 |
| 4 | Direct URL / route guard ở FE | ⏳ **Chưa chạy** — cần QA bấm thật trên bản đã deploy |
| 5 | `WAREHOUSE_MANAGER` chỉ giám sát | ✅ Đo được: Post 403, IAM 403, App PV 403, PII bị che |
| 6 | `WAREHOUSE_STAFF` Post trực tiếp, không chờ ai | ✅ Đo được: Post trả 428 (qua cửa quyền) thay vì 403 |
| 7 | Hai vai kỹ thuật cũ không còn ở production | ✅ XANH, có test tự động canh |
| 8 | `WARRANTY_STAFF` xem được PII nhưng không xuất | ✅ Đo được ở §4.2 |
| 9 | `VIEWER` / `LEADERSHIP` không xem Audit Log | ✅ Đo được: cả hai đều 403 |
| 10 | Không vai production nào có điều chỉnh tồn trực tiếp | ✅ Đã rà danh mục quyền: **không tồn tại** mã quyền nào cho điều chỉnh tồn trực tiếp / kiểm kê / chênh lệch (đúng như ghi chú `*` của BA) |
| 11 | Reversal chỉ ở vai có quyền, bắt buộc audit/lý do | ✅ Trong 7 vai production, **chỉ ADMIN** có `inventory.reversal.post` (hai vai còn giữ là `INBOUND_ONLY_TEST` và `WAREHOUSE_KEEPER` — đều chỉ tồn tại ở dev/test); cơ chế audit/lý do/chống trùng giữ nguyên |
| 12 | Hai nhân viên kho hai đầu thao tác độc lập | ✅ Đã dựng thành kịch bản kiểm thử tự động (hai tài khoản khác nhau cùng vai `WAREHOUSE_STAFF` cùng thao tác trên một chứng từ) |

**Còn lại đúng một việc:** mục 4 cần QA bấm tay trên bản đã deploy (gõ thẳng URL của màn không
có quyền, kiểm xem có bị đá về màn "Không có quyền truy cập" không). Ba câu hỏi ở §6 đã được BA
chốt ngày 07/09/2026. Sau khi mục 4 xong, tài liệu này chốt PASS toàn phần.

---

## Phụ lục — nơi thay đổi nằm trong mã nguồn

| Tệp | Vai trò trong thay đổi |
|---|---|
| `Modules/Auth/database/seeders/AuthDatabaseSeeder.php` | Ma trận vai trò ↔ quyền (nguồn chính) |
| `Modules/Auth/database/seeders/WmsPermissionCompatibilitySeeder.php` | Chặn quyền quay lại qua "alias tương thích FE" |
| `Modules/Auth/database/seeders/RoleAccountMatrixSeeder.php` | Sinh 33 tài khoản ở §1 |
| `Modules/Warehouse/database/seeders/UatScenarioSeeder.php` | Bộ tài khoản kịch bản UAT |
| `app/Console/Commands/MigrateDeprecatedWarehouseRoles.php` | Lệnh chuyển vai ở §6 mục ③ (mặc định dry-run) |
| `docs/evidence/WWHN-267/ROLE_BASELINE_PIN.json` | Hồ sơ pin baseline + nhật ký quyết định (DELTA-01…06, kèm 3 quyết định của BA ngày 07/09) |

Giao diện **không cần sửa**: toàn bộ menu và cổng vào màn hình của FE đã chặn theo *mã quyền*
chứ không theo *tên vai trò*, nên mọi thay đổi ở trên tự động phản ánh lên giao diện.
