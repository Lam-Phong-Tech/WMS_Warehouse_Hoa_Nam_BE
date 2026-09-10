# WWHN-165 contract release 1.0.0 — CHANGELOG

## 1.0.0 (2026-08-20)

Bản release pack đầu tiên cho WWHN-165 (BE authz hardening, traceability WWHN-35 /
WWHN-166). Đóng gói tại source commit `a452e4459fe20aaaf1c23981577b3e0570c13c00`
(nhánh `m1`, commit chứa code fix).

- `openapi.json` / `openapi.yaml`: copy nguyên trạng từ `storage/api-docs/` như
  git lưu tại source commit (không sinh lại từ working tree để tránh lẫn thay đổi
  chưa commit của phiên khác).
- `postman_collection.json`: từ `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json`
  tại source commit, thêm `info.x-document-version=1.0.0` và `info.x-source-commit`.
- `samples/`: 8 error envelope thật (401/403x2/409x2/412/422/428) dump từ proof test
  — xem `samples/README.md`.
- `PERMISSION_CATALOG_REF.md`: trỏ tới catalog publish (schema 1.2.0, integrity PASS).
- Hành vi mới trong code (không đổi hợp đồng HTTP/error vocabulary):
  - Audit `result='DENIED'` cho mọi 403 permission/data-scope (ADR-AUDIT-002,
    best-effort — không bao giờ đổi response 403).
  - No-stack-trace fail-closed: debug payload chỉ tồn tại ở env local/development.

## 1.0.0 — sửa đổi bảo mật (2026-09-10)

- Gỡ giá trị `example` của trường `password` trong request body `POST /api/v1/auth/login`
  (`openapi.json`, `openapi.yaml`). Giá trị đó là mật khẩu thật của tài khoản quản trị seed
  sẵn, đã bị thu hồi (P0 bảo mật 10/09/2026). Hợp đồng HTTP KHÔNG đổi: schema, `required` và
  `format` giữ nguyên, chỉ bỏ một giá trị ví dụ.
- `SHA256SUMS` và `RELEASE_MANIFEST.json` được niêm phong lại theo nội dung mới. Bản gốc vẫn tra
  được trong lịch sử git; mật khẩu trong đó bị hệ thống từ chối (`golive.credential.compromised`).
