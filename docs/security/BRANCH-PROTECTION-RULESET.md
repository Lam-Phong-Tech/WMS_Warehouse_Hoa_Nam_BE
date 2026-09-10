# Branch protection / ruleset cho `dev`, `m1`, `main` — khuyến nghị và blocker

**Trạng thái (10/09/2026): REPOSITORY SETTING REQUIRES ADMIN.** Không cấu hình được từ phía code.

## 1. Vì sao chưa áp được (bằng chứng API)

| Repo | Quyền của tài khoản thực hiện | `GET /branches/dev/protection` | `GET /rulesets` |
|---|---|---|---|
| `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` | `admin: false`, `maintain: true` | `404 Not Found` (chưa có protection) | `403 Upgrade to GitHub Pro or make this repository public to enable this feature.` |
| `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE` | `admin: false`, `push: true` | `404 Not Found` | `403` (cùng thông báo) |

Org `Lam-Phong-Tech` đang ở gói **free**, cả hai repo đều **private**. Branch protection và ruleset cho repo private cần gói GitHub Team trở lên, và người áp phải có quyền admin.

**Hệ quả hiện tại:**
- Ai có quyền push đều push thẳng lên `dev`/`m1`/`main`, kể cả force-push.
- Commit mang `[skip ci]` không đi qua cổng nào. Phần lớn drift của mốc test đỏ lọt vào đúng theo đường này (xem `docs/evidence/SEC-20260910`).
- CI cứng chỉ **báo** đỏ, không **chặn** merge.

## 2. Việc cần chủ org/admin làm

1. Nâng org lên GitHub Team, hoặc chuyển repo sang public nếu phù hợp chính sách.
2. Áp ruleset bằng chính các tệp JSON trong thư mục này:
   ```bash
   gh api --method POST repos/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/rulesets --input docs/security/github-ruleset-be.json
   gh api --method POST repos/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE/rulesets --input docs/security/github-ruleset-fe.json
   ```
   Tệp FE nằm ở repo FE, cùng đường dẫn.
3. **Settings → Actions → General:**
   - `Workflow permissions` = Read repository contents;
   - tắt "Allow GitHub Actions to create and approve pull requests";
   - fork PR cần phê duyệt trước khi chạy workflow.
4. Tạo secret còn thiếu cho deploy UAT: `UAT_SMOKE_EMAIL` (smoke đăng nhập chỉ nhận email).
5. Khi gói cho phép: bật secret scanning + push protection. Trong lúc chờ, lớp thay thế là `scripts/check-known-credentials.mjs` (cổng CI cứng ở cả hai repo) và hook pre-commit của FE.

## 3. Nội dung ruleset (vì sao từng luật)

| Luật | Giá trị | Lý do |
|---|---|---|
| Nhánh áp dụng | `dev`, `m1`, `main` | Ba nhánh CI chạy và deploy lấy artifact |
| `deletion` | chặn | Không xoá nhánh chuẩn |
| `non_fast_forward` | chặn | Không force-push viết lại lịch sử (nguyên tắc "Không force-push") |
| `pull_request` | 1 approval; bỏ approval cũ khi có push mới; bắt review của CODEOWNERS; người phê duyệt phải khác người push cuối; mọi thread phải resolve | Thay đổi RBAC, seed và auth không được vào nhánh chuẩn khi chưa có người thứ hai đọc |
| `required_status_checks` (strict) | BE: `ci`. FE: `Enforce all quality gate outcomes` và `Security gates` | Chỉ merge khi cổng cứng xanh, và nhánh phải cập nhật so với nhánh đích. Check tổng FE chạy `if: always()` nên không bao giờ bị skip (GitHub tính check bị skip là đạt). |
| `required_linear_history` | bật | Truy vết exact SHA của evidence dễ hơn |
| `bypass_actors` | rỗng | Không ai được đi tắt, kể cả admin. Nếu cần break-glass, thêm có thời hạn và ghi vào nhật ký thay đổi. |

**Commit `[skip ci]`:** khi `required_status_checks` bật, PR mà check không chạy sẽ không merge được. Lỗ hổng này tự đóng khi ruleset có hiệu lực.

**Sau khi merge các nhánh sửa CI, đối chiếu tên check.** Nếu đổi `name:` của job, phải sửa tệp JSON tương ứng.
- BE `hn-devops-ci-cd.yml`: job `ci`, không đặt `name`.
- FE `ci.yml`: các job `Security gates`, `Static gates (format, lint, typecheck, UI guards)`, `Unit and integration tests`, `Contract and closure gates`, `Build and delivery artifact`, `Enforce all quality gate outcomes`.

## 4. CODEOWNERS (khuyến nghị, cần chủ repo chỉ định người)

Không tạo sẵn tệp `CODEOWNERS` vì cần tên người/đội thật. Đường dẫn nên có chủ review bảo mật:
- **BE:**
  - `Modules/Auth/**`
  - `app/Console/Commands/BootstrapSuperAdmin.php`, `app/Console/Commands/BackendGoLiveCheck.php`
  - `app/Http/Middleware/SecurityHeaders.php`
  - `config/{golive,refresh_token,cors,mfa,audit,rate_limit}.php`
  - `database/golive/**`
  - `Modules/*/database/seeders/**`
  - `.github/workflows/**`, `docker/**`
  - `scripts/check-known-credentials.mjs`, `scripts/sec-*.php`
- **FE:**
  - `src/shared/api/**`, `src/modules/auth/**`, `src/shared/store/auth.store.ts`, `src/shared/lib/memory-storage.ts`
  - `deploy/nginx/**`
  - `.github/workflows/**`, `.husky/**`
  - `scripts/check-*.mjs`, `scripts/audit-dependencies.mjs`
