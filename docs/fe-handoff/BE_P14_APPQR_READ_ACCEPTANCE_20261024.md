# P14 - Xác nhận nghiệm thu đọc AppQR, 24/10/2026

**UAT-R7-01: PASS_READ_LIVE_APPQR_CURRENT_SESSION.** Đây là nghiệm thu lỗi weak ETag của GET close-check qua web/proxy trong đúng phiên hiện tại, không phải toàn bộ P14 hoặc P01-P24 PASS.

Đã đối chiếu gói App `P14_TLS_LOGIN_RETEST_20261024.zip`, SHA256 `3350acb8a9b1ba6273aeb9014579d3a7db493abb36b395c086a023e6d72af9ad`: 9 payload khớp checksum/dung lượng, 10 ZIP entry, không chạy script trong gói. Nguồn bằng chứng là projection authenticated của App; BE không replay HTTP hoặc đăng nhập mới trong lượt xác nhận này.

## Kết quả được xác nhận

Kho `01a06f33-6bff-70b8-a089-3c68a83b11a8`, ca hiện tại `12e4c5b1-518e-4146-b9bf-756ac2f65074`.

| Chế độ | Giờ server Việt Nam | HTTP | ETag / expected_version | Content-Encoding | Request-ID |
| --- | --- | --- | --- | --- | --- |
| Browser mặc định | 24/10 15:18:09 | 200 | `"1"` / 1 | Không có | `2276fe83-e8a6-418a-b597-4267d2c97222` |
| Gzip | 24/10 15:18:33 | 200 | `"1"` / 1 | Không có | `752c0b7f-55ee-421f-a644-877c565a7aad` |
| Identity đối chứng | 24/10 15:18:42 | 200 | `"1"` / 1 | Không có | `9f234a83-2f5a-48d8-a799-2f1e2d7e8186` |

Cả ba giữ `p14-shift-v1`, cùng identity kho/ca hiện tại, `private, no-store, no-transform`; ETag mạnh khớp version server, FE chấp nhận. Không hardcode version2 của ca cũ, không strip W/, không nới validator và không dùng identity override làm workaround. App báo 5 validator test PASS, source fingerprint R7.1 không đổi; không phải BE đã chạy lại full CI hoặc Android E2E.

Health qua proxy trong gói: PHP `a9053a21bc35ece367be0b76db727d4749b59855`, tier dev-test, HTTP200, RID `4e401cc2-06fb-4d33-a8d2-05cde3a19e9b`. Health PHP không tự chứng minh SHA overlay Nginx; provenance triển khai nằm ở các báo cáo BE trước.

## Không mở rộng kết luận

- Ca cũ `5e8e6150-4c76-4313-879f-d0884ee9c085` thuộc family đã bị thay thế, không được retest hoặc resolve bởi bằng chứng ca mới này. Không takeover, xóa journal hoặc tự tạo phiếu/ca bù.
- Server can_end=true, blockers=[] không chứng minh local CLEAN. END vẫn disabled; không tự bật nút hoặc đổi UNKNOWN thành CLEAN.
- Chưa có direct DEV authenticated comparison độc lập, stale-version/TEST END, native hardware hoặc full owner coverage.
- Không coi đăng nhập do người dùng thực hiện trước lượt App retest là bằng chứng BE đã test login/replacement toàn bộ; agent không login thay phiên.
- Không có deploy/rebuild/flag change, CI, migration/seed/repair/restore, grant, START/END hoặc thao tác dữ liệu thật trong lượt BE xác nhận này.
- NFC/P11/full seed và các gate P01-P24 khác giữ trạng thái riêng. Production activation không được mở từ ba GET.

Chi tiết máy đọc: `docs/evidence/P14_APPQR_READ_ACCEPTANCE_20261024.json`. Giữ gói R2 fail và các gói bị chặn phiên như lịch sử, không sửa chúng thành PASS. Không cần deploy mới để ghi nhận nghiệm thu đọc này.
