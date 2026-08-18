# WWHN-309 — Kịch bản retest độc lập cho QA1 (DEV309-P1-04 / WWHN-312)

| Trường | Giá trị |
| --- | --- |
| Người thực hiện | QA1 — DT Soi (**không** phải người viết code) |
| Đối tượng kiểm | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE@m1` tại `candidate_sha` |
| Nguồn SHA | `docs/evidence/WWHN-309/CANDIDATE_MANIFEST.json` |
| DB nghiệm thu | PostgreSQL 16 (SQLite **không** phải căn cứ nghiệm thu) |
| Cập nhật | 2026-08-19 |

Vòng review trước ghi WWHN-312 là *"Blocked/Pending, không evidence"*. Tài liệu này gỡ
đúng cái chặn đó: QA không phải tự thiết kế lại cách kiểm, chỉ cần chạy đúng 7 nhóm
dưới đây rồi dán kết quả thật vào cột cuối.

## 0. Chuẩn bị — khoá đúng revision trước khi kiểm

```bash
git fetch origin m1
git checkout <PIN_COMMIT>               # commit chứa CANDIDATE_MANIFEST.json
node scripts/verify-wwhn-309-candidate-pin.mjs
```

**Vì sao là PIN_COMMIT chứ không phải CANDIDATE_SHA:** manifest pin nội dung artifact
tại `candidate_sha`, nên bản thân file manifest chỉ có thể nằm ở commit kế tiếp. Đây
không phải kẽ hở: cổng kiểm **range invariant** — nếu có bất kỳ commit nào giữa
`candidate_sha` và HEAD chạm vào tập artifact thì cổng ĐỎ. Nói cách khác, PASS đồng
nghĩa "artifact bạn đang cầm giống hệt artifact tại candidate_sha".

Nếu bước cuối FAIL: **dừng lại**. Pin đã mục — trả task về DEV để re-pin, không kiểm
tiếp trên bản đã trôi.

## 1. Ma trận retest

| # | Nhóm | Lệnh / thao tác | Kết quả phải đạt | Kết quả thật |
| --- | --- | --- | --- | --- |
| 1 | Permission catalog | `php artisan evidence:permission-catalog --output=/tmp/pc.json --seed-diff-output=/tmp/pd.json` rồi so với `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json` (bỏ `generated_at_utc`) | Trùng byte. Biểu thức `permission:A\|B` được tách thành `accepted_permissions`, không bị coi là một permission lạ | `PENDING` |
| 2 | Ma trận endpoint / OpenAPI / header | `node scripts/generate-endpoint-permission-matrix.mjs --check` và `node scripts/generate-api-contract-metrics.mjs --check` | Cả hai PASS. Số liệu đọc từ `HN-BE-API-004`, không đọc văn xuôi ở tài liệu khác | `PENDING` |
| 3 | Catalog error code | `node scripts/generate-error-code-catalog.mjs --check` | PASS. Không còn mã nào `first_seen` trỏ vào `CurrentBaselineIfMatch` (đã gỡ theo `DEC-API-IFMATCH-001`) | `PENDING` |
| 4 | Cổng tổng tài liệu | `node scripts/verify-wwhn-309-handover-contract.mjs` | Exit 0. Gồm: đủ tài liệu, không tài liệu nào bị `.gitignore`, README khớp `.env.example`, không có secret, **mọi con số trong tài liệu khớp `HN-BE-API-004`** | `PENDING` |
| 5 | Định nghĩa job nền | `php artisan schedule:list` | Có đủ 4: `inventory:reconcile` (01:30), `audit:reconcile` (mỗi 5 phút), `import:purge-error-files` (02:15), `warranty:purge-pii` (03:10) | `PENDING` |
| 6 | Regression test bàn giao | `php artisan test -c phpunit.pgsql.xml --filter '/(Wwhn309PermissionCatalogEvidenceTest\|Wwhn309ScheduledOperationsTest\|SwaggerRouteSyncTest\|CurrentBaselineIfMatchContractTest)/'` | 0 failed. Ghi rõ số passed/skipped | `PENDING` |
| 7 | Danh tính build | `GET /api/v1/health` trên môi trường đã deploy | `data.build.sha` **bằng** `candidate_sha`; `checks.app/database/migrations/seed` = ok; `checks.cache` cố ý không gate | `PENDING` |

## 2. Kiểm âm bắt buộc (chứng minh cổng thật sự chặn, không chỉ xanh)

Ba phép thử phá hoại có kiểm soát — làm trên bản làm việc rồi `git checkout -- .`:

| # | Thao tác | Cổng phải ĐỎ ở đâu | Kết quả thật |
| --- | --- | --- | --- |
| N1 | Sửa một con số bất kỳ trong `HN-BE-API-001` (ví dụ `231` → `226`) | `verify-wwhn-309-handover-contract.mjs` mục "số liệu tài liệu bàn giao" | `PENDING` |
| N2 | Sửa một byte trong `docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md` | `verify-wwhn-309-candidate-pin.mjs` báo lệch SHA-256 | `PENDING` |
| N3 | Thêm dòng `/docs/` vào `.gitignore` | Cổng tổng báo tài liệu handover bị `.gitignore` loại trừ | `PENDING` |

Nếu một trong ba phép thử vẫn XANH thì cổng đó vô hiệu — đó là defect P1, không phải
ghi chú.

## 3. Đối chiếu contract phía tiêu thụ (FE)

```bash
# trong repo FE
npm run contract:sync -- <CANDIDATE_SHA>
npm run check:api-mapping
```

`contract:sync` sẽ **fail-closed** nếu SHA tải về không khớp pin trong
`docs/api-evidence/BE_CONTRACT_PIN.json` — nghĩa là FE và QA chắc chắn đang đọc cùng
một revision, không phải "bản mới nhất trên máy ai đó".

## 4. Kết luận của QA1

| Trường | Giá trị |
| --- | --- |
| Candidate SHA đã kiểm | `PENDING` |
| Run CI đối chiếu (ID/URL) | `PENDING` — xem `CI_RUN_RECORD.md` |
| Số mục PASS / tổng | `PENDING` |
| Defect mở mới (ID + severity) | `PENDING` |
| Kết luận | `PENDING` |
| Ngày | `PENDING` |

QA1 chỉ ký khi §1 đủ 7 PASS **và** §2 đủ 3 lần ĐỎ đúng chỗ. Thiếu một trong hai thì
ghi `NOT ACCEPTED` kèm lý do, không ghi PASS có điều kiện.
