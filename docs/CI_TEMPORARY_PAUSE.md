# Tạm ngừng CI tự động

Ngày áp dụng: 2026-10-30. GitHub Actions hiện không khởi động runner vì lỗi thanh toán hoặc giới hạn chi tiêu; các check đỏ trong vài giây không phải kết quả chạy test. Ví dụ: [frontend CI run 41006266990](https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE/actions/runs/41006266990).

## Phạm vi

Trên nhánh chứa thay đổi này, các workflow không còn trigger `push`, `pull_request` hoặc `pull_request_target`. Trigger `workflow_dispatch` được giữ hoặc bổ sung để có thể yêu cầu chạy tay. Các trigger khác như `schedule` được giữ nguyên. Việc push mã lên GitHub vẫn hoạt động; push không đồng nghĩa deploy lên VPS.

Sau khi thay đổi được merge, các commit tiếp theo chỉ cần commit/push bình thường; không cần gắn `[skip ci]`. Trước khi merge, commit mang `[skip ci]` có thể tránh workflow cũ chạy trên PR, nhưng GitHub có thể để required check ở trạng thái Pending. Xem [hướng dẫn GitHub](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/skip-workflow-runs).

## Kiểm tra và chạy tay

- Trong thời gian tạm ngừng, chạy test/typecheck/build phù hợp ở máy phát triển và ghi kết quả vào PR.
- Khi tài khoản GitHub Actions hoạt động lại, dùng tab Actions hoặc `gh workflow run <workflow-file> --ref <branch>`. Runner/billing và điều kiện của từng job vẫn phải đáp ứng; việc giữ trigger chạy tay không tự làm một job deploy đủ điều kiện.
- Backend có nhánh mặc định `main` hiện không chứa `.github/workflows`. GitHub yêu cầu workflow có trên nhánh mặc định để dùng `workflow_dispatch`; cần khôi phục workflow cần chạy tay trên `main` trước khi dựa vào nút Run workflow cho `dev`/`m1`.
- Deploy là quy trình riêng. Kiểm tra release SHA trên VPS sau deploy; không suy ra từ trạng thái push hoặc artifact CI.

## Bật lại

Sau khi xử lý billing, revert commit tạm ngừng CI này hoặc khôi phục trigger tự động trong các workflow rồi chạy toàn bộ gate trước khi deploy. Rà soát required checks/ruleset trước khi bật lại. Thay đổi này áp dụng theo nhánh; nhánh khác cần cập nhật riêng.
