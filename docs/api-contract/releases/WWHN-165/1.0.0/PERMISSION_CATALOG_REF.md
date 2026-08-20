# WWHN-165 — Permission catalog reference

Release pack này KHÔNG copy permission catalog; nguồn duy nhất (single source of
truth) là file đã publish trong repo:

- Path: `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json`
- `schema_version`: `1.2.0`
- `generated_at_utc`: `2026-08-20T02:55:25+00:00` (regenerate trong WWHN-165 vì
  source hash của `Modules/Auth/app/Http/Middleware/CheckPermission.php` thay đổi
  khi thêm deny-audit — nội dung permission/role/route matrix KHÔNG đổi)
- `integrity`: `active_permission_count=83`, `route_permission_count=57`,
  `missing_route_permissions=[]`, `status=PASS`
- sha256 (nội dung as-git-stored tại source commit `a452e4459fe20aaaf1c23981577b3e0570c13c00`):
  `dbabbf941f482c916195f8bf66db473272f2cf2be7eedf92414ddb6bac23342a`

Cách verify lại:

```
git show a452e4459fe20aaaf1c23981577b3e0570c13c00:docs/permissions/WMS_PERMISSION_CATALOG_BE1.json | sha256sum
```

Guard chống stale: `tests/Feature/Wwhn309PermissionCatalogEvidenceTest::test_published_catalog_document_is_not_stale`
so `source_hashes` trong catalog với hash file nguồn hiện hành — catalog lệch code
là test đỏ.
