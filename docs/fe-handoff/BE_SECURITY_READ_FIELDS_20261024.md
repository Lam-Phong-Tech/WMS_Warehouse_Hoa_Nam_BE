# BE: P11 Và Dữ Liệu Đọc Rõ Trạng Thái

Ngày 24/10/2026, Asia/Ho_Chi_Minh. **Source candidate, chưa push/deploy, không phải App/DEV UAT PASS toàn bộ.**

## Đã Sửa

| Phần | Thay đổi thực tế |
| --- | --- |
| P11 | Journal riêng cho đổi mật khẩu, logout, thu hồi phiên. Lưu PENDING trước transaction nghiệp vụ; effect và receipt SUCCEEDED commit cùng nhau. UNKNOWN chỉ CHECK_ONLY, không đổi key/body/phiên hoặc bỏ header để né guard. |
| Đối chiếu P11 | Phiên mới phải xác minh lại mật khẩu và MFA đã tồn tại trước lệnh gốc. Proof ràng buộc actor/family/key/factor/credential, hết hạn sau 300 giây. Đổi MFA, đổi credential cùng giây, thu hồi phiên hoặc proof hết hạn đều bị từ chối. Không takeover journal WMS. |
| MFA | Khóa hàng khi kiểm MFA và marker TOTP atomic; mã recovery dùng một lần. Cast UTC cho confirmed_at/last_used_at/locked_until, không cộng/trừ 7 giờ hoặc repair lịch sử. |
| Identity P04/P14/P11 | Phân biệt UUID client gửi với Request-ID do server tự sinh để trace. Server không dùng trace tự sinh thay identity gốc của command. |
| Dữ liệu đọc | Có representation riêng `explicit-read-fields-v1`: giữ số thật, false, 0, danh sách và identity; trường chưa có giá trị được bỏ khỏi value và có trạng thái/nguồn rõ ràng. Không trả null mơ hồ hoặc bịa giá trị. |
| P01/P13 | Admission thêm ACTIVE/NO_ACTIVE_SHIFT từ query owner; handoff thêm RECORDED/NOT_ESTABLISHED/NOT_APPLICABLE/SOURCE_UNAVAILABLE. Thông báo thêm read_at_status, GET không tự mark-read. |
| Dependency | Cập nhật fast-uri thật lên 3.1.9 để xử lý advisory mới. Build/npm test PASS; npm audit vẫn còn 8 cảnh báo trong toolchain Postman/Newman. Không bỏ audit hoặc ép downgrade để báo PASS. |

## FE/App Lấy Representation Không Null

Chỉ trên các GET được công bố, gửi:

```http
X-Read-Fields-Contract: explicit-read-fields-v1
```

Đọc `meta.contract=explicit-read-fields-v1`, `meta.read_fields.owner_contract` nếu owner có contract và `meta.read_fields.missing_fields[]`. Mỗi mục có JSON pointer `path`, `status`, `source`, `value_supplied=false`. **Không đưa body này qua parser nullable cũ của P12/P06.**

- Giá trị có nguồn vẫn giữ nguyên, không thay 0/false/chuỗi rỗng hoặc reindex danh sách.
- NOT_RECORDED/NOT_CONFIGURED/NOT_READ/PARTIAL_SOURCE/FORBIDDEN/REDACTED/NO_ACTIVE_SHIFT khác nhau. UNSPECIFIED_BY_OWNER là gap nguồn thật cần owner bổ sung, không phải dữ liệu đã được điền.
- `total` thiếu do PARTIAL_SOURCE không được lấy `availableTotal` thay thế. `contained_qty` không phải tồn khả dụng hay số đã giữ chỗ.
- Capacity/ô/kệ không tồn tại trong mô hình một kho với hộp thì không tự tạo. Không chuyển PRODUCT thành COMPONENT vì thiếu barcode.
- Mất nguồn, sai quyền hoặc lỗi owner vẫn trả lỗi, không biến thành danh sách rỗng. Null nằm trong một phần tử list làm response fail-closed thay vì xóa dòng.
- `meta.read_fields.owner_precondition.etag` giữ nguyên literal do owner trả. ETag HTTP của body cũ bị gỡ vì bytes đã đổi; không tự dựng ETag, bỏ W/ hay dùng nó để cache. Khi ghi, dùng owner contract và không gửi header representation đọc.
- Không gắn header này bằng interceptor cho mọi request: loại trừ command, native receipt/reconcile và binary download. Các route không được công bố bị từ chối.
- Không có header thì API nullable cũ giữ nguyên để không làm hỏng app đang chạy. Muốn thấy representation mới, FE/App phải nối adapter và release phải được triển khai trước.

Contract chi tiết: `docs/contracts/explicit-read-fields-v1.json`. Không endpoint aggregate hoặc endpoint ghi kho mới.

## App Nối P11

Giao thức mặc định OFF, chờ security/release review. Ba owner hiện hữu nhận `X-Security-Command-Contract: p11-security-command-v1`, UUID `Idempotency-Key` và UUID `X-Request-ID` gốc. Body/target giữ theo owner cũ.

1. Chỉ lưu key/trace/operation/target và identity phiên cần đối chiếu trong journal App. **Không lưu password/token/MFA code hoặc body chứa secret.**
2. Thành công phải có receipt `data.receipt.state=SUCCEEDED`; giữ receipt tách khỏi hiện trạng tài khoản/phiên.
3. Timeout/UNKNOWN: không gửi lại command, không đổi key/body và không dùng legacy fallback. UNKNOWN HTTP 503 không bị middleware cookie coi là logout thành công.
4. GET `/api/v1/auth/security-requests/{original_key}` bằng phiên hợp lệ. Token đã bị revoke/replaced vẫn bị chặn.
5. Sau đăng nhập lại, POST `/api/v1/auth/security-requests/{original_key}/verify` với mật khẩu hiện tại và TOTP/mã recovery, sau đó GET đúng key. Verification không thực hiện lại command.
6. Old UNKNOWN không có journal/identity hoặc factor bị đổi phải xử lý support có audit. Không suy NOT_FOUND thành chưa thực hiện hoặc tự tạo request bù.

Contract: `docs/contracts/p11-security-command-v1.json`. Schema mới: `2026_10_24_000001_create_p11_security_command_receipts.php`. Không tự migrate hay bật `P11_SECURITY_COMMANDS_ENABLED` trên DEV/production. Không tắt giao thức khi còn family đã dùng native hoặc PENDING chưa đối chiếu; cần phương án rollout/retention/support được owner duyệt.

## Còn Mở

- Security/release review cho route/schema P11, retention và support resolution của UNKNOWN cũ. Source đã có, runtime chưa bật/nghiệm thu.
- Cần FE/App adapter cho representation đọc mới và P11; chưa có kiểm thử điện thoại/live auth của bản này.
- Npm audit còn 7 high + 1 moderate; Composer audit không có advisory. Node-forge chưa có patched version theo [advisory đã kiểm](https://github.com/advisories/GHSA-86w9-cpqp-85rv). Không đánh dấu audit gate PASS.
- Full seed vẫn INCOMPLETE_NOT_PASS: chưa chạy lại hai full chain coverage14 trên clone được duyệt và đối chiếu row/field/ledger/file. Không seed thật để làm đầy các field.
- Các giá trị lịch sử chưa được lưu, hộp/SKU thực tế chưa được đối chiếu và timestamp/receipt thiết bị còn cần raw evidence. Representation không-null không phải backfill hoặc bằng chứng dữ liệu đã đầy đủ.
- Bản candidate còn phụ thuộc migration handoff P13 ngày 23/10 trước đó. Số migration trên DB test không chứng minh schema parity của server hay đã giữ đủ runtime overlays.

Không chạy GitHub CI, push/deploy, migrate/seed/repair/restore DB thật, grant quyền, đăng nhập admin thật, tạo phiếu hoặc thao tác NFC thật. Các XML trong gói là test cô lập, không phải raw DEV evidence. Nhóm test có giao nhau, không cộng thành tổng ca duy nhất.

Nguồn dependency: [fast-uri advisory](https://github.com/advisories/GHSA-w74x-v6f9-j3xg), [node-forge advisory](https://github.com/advisories/GHSA-86w9-cpqp-85rv). Exact SHA, kết quả test và checksum nằm trong manifest gói bàn giao.
