# WWHN-180 — CI runtime credential setup

## Mục đích

Workflow `.github/workflows/wwhn-180-api-posting-contract.yml` không lưu mật khẩu trong Git. Newman chỉ nhận credential ở runtime thông qua GitHub Actions secrets.

## Repository secrets bắt buộc

| Secret | Tài khoản seed tương ứng | Yêu cầu |
|---|---|---|
| `WWHN180_PASSWORD` | `manager01@gmail.com` | Giá trị phải khớp mật khẩu của manager evidence account trong môi trường CI |
| `WWHN180_NO_PERMISSION_PASSWORD` | `test-no-permission@hoanam.com` | Giá trị phải khớp mật khẩu của no-permission evidence account trong môi trường CI |

Không đưa giá trị secret vào issue comment, artifact text, Postman collection/environment, workflow YAML hoặc tài liệu Jira.

## Preflight behavior

Workflow dừng ngay tại bước `Validate runtime-only Newman credentials` nếu thiếu một trong hai secrets. Trường hợp này là **configuration blocker**, không được dùng diagnostics artifact để đóng P0/P1.

## Cách chạy evidence gate

1. Cấu hình hai repository secrets ở GitHub Actions.
2. Chạy workflow `WWHN-180 API Data Contract Evidence Gate` bằng `workflow_dispatch` trên branch `m1`, hoặc push một commit mới lên `m1` bằng actor có khả năng kích hoạt Actions.
3. Chỉ dùng artifact `api-data-evidence-<exact_commit_sha>` từ run thành công.
4. Verify:

```bash
sha256sum --check SHA256SUMS
sha256sum --check SHA256SUMS.sha256
```

5. Đối chiếu `build-metadata.json.commit_sha` với exact SHA được FE1, QA2 và BA review.

## Trạng thái approval

Thiết lập secrets và workflow PASS chỉ tạo BE/CI evidence. Nó không thay thế independent sign-off của FE1, QA2 hoặc BA.
