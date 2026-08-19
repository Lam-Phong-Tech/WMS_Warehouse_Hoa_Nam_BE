# WWHN-184 — Runbook deploy candidate (DEV184-P1-02R)

| Field | Value |
|---|---|
| Jira task | `WWHN-184` — `[DEV] Triển khai API chứng từ/trạng thái nhập kho` |
| Task cha | `WWHN-41` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` (không tạo nhánh riêng) |
| Pin | `docs/evidence/WWHN-184/RC_PIN.json` |
| Verifier | `node scripts/verify-wwhn184-candidate-pin.mjs` |
| Gate CI | `.github/workflows/wwhn-184-inbound-contract.yml` |
| Owner thực thi | **DevOps** (DEV không có quyền deploy) |
| Reviewer | QA1 |

---

## 1. Vì sao P1-02R từng OPEN, và cái gì đã được sửa

Register ghi: *"Không có deployed candidate; branch đã trôi 2 commits và có PG posting
migration/service change"*.

Chẩn đoán: **vấn đề không nằm ở việc quên bấm deploy.** `m1` là nhánh dùng chung, nhiều
phiên làm việc song song commit vào cùng lúc. Mọi cách pin candidate theo `HEAD` của `m1`
đều mất hiệu lực trong vài phút — đến lúc DevOps deploy thì `HEAD` đã khác, và không ai
chứng minh được bản đang chạy có đúng là bản đã review hay không. Đó chính là lý do lần
trước "branch đã trôi 2 commits" trở thành một finding.

Cách sửa tận gốc, đã áp dụng:

1. **Pin theo nội dung, không theo HEAD** (`RC_PIN.json`, mô hình `content-hash range
   invariant`, cùng loại đã được chấp nhận ở `HN-PIN-273`). WWHN-184 sở hữu 19 file cụ thể;
   pin ghi `sha256` của từng file.
   - Ticket khác commit lên `m1` → pin **vẫn xanh**, không rot.
   - Ai sửa đúng file của WWHN-184 → pin **đỏ ngay**, buộc review lại.
2. **Cổng exact-SHA lúc deploy**: `--expect-sha=<sha>` bắt buộc bản checkout đang chạy đúng
   bằng SHA mà DevOps tuyên bố đã deploy. Không còn khe hở "deploy bản khác bản đã ký".
3. **Chống rewrite**: verifier kiểm tra `pinned_at_head` vẫn là ancestor của `HEAD`. Nếu
   nhánh bị force-push/rewrite, pin báo đỏ thay vì im lặng.

---

## 2. Xác định exact SHA của candidate

Không dùng `git rev-parse m1`. Candidate là **commit cuối cùng chạm vào tập file WWHN-184
sở hữu**:

```bash
git log -1 --format=%H -- Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php Modules/Warehouse/app/Http/Controllers/CurrentBaselineInboundBoxController.php Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php Modules/Warehouse/routes/api.php app/Support/IdempotencyStore.php app/Support/IdempotencyKeyGuard.php app/Support/ConflictCode.php "docs/postman/WWHN-184 Inbound Contract.postman_collection.json" "docs/postman/WWHN-184 Inbound CI.postman_environment.json" docs/api-contract/WWHN-184-inbound-openapi.json docs/api-contract/WWHN-184-inbound-openapi.yaml scripts/verify-wwhn184-postman-contract.mjs scripts/collect-wwhn184-evidence.sh scripts/wwhn184-post-receipt-runtime-concurrency.php scripts/wwhn184-post-receipt-worker.php tests/Feature/Wwhn184InboundContractParityTest.php tests/Feature/Wwhn184PostReceiptRuntimeConcurrencyTest.php .github/workflows/wwhn-184-inbound-contract.yml
```

Ghi kết quả vào `RC_PIN.json → deployment.deployed_sha`.

---

## 3. Tiền điều kiện — không deploy nếu một trong các mục dưới đây chưa xanh

| # | Điều kiện | Lệnh kiểm chứng | Bằng chứng phải lưu |
|---|---|---|---|
| 1 | Pin nội dung còn nguyên vẹn | `node scripts/verify-wwhn184-candidate-pin.mjs` | stdout `PASS` |
| 2 | Gate CI xanh tại đúng SHA | Run của `WWHN-184 Inbound Contract Evidence Gate` với `headSha` = candidate | `run_id`, `conclusion=success`, artifact digest |
| 3 | Newman full pass | artifact `newman.result.json` | `run.failures = []`, số request = số request khai trong collection |
| 4 | Bất biến version-chaining | artifact `postman-contract-verification.json` | `summary.status = PASS` |
| 5 | Race PostgreSQL | artifact `post-receipt-concurrency-summary.json` | `status = PASS`, mỗi kịch bản `distinct_backend_pid_count = 2` |

---

## 4. Trình tự deploy

```bash
# 4.1 Checkout đúng candidate (không dùng nhánh, dùng SHA)
git fetch origin m1
git checkout <CANDIDATE_SHA>

# 4.2 Cổng exact-SHA — dừng ngay nếu đỏ
node scripts/verify-wwhn184-candidate-pin.mjs --expect-sha=<CANDIDATE_SHA> --out build/evidence/wwhn-184-deploy/pin-check.json

# 4.3 Build
composer install --no-dev --optimize-autoloader --no-interaction
php artisan config:cache && php artisan route:cache

# 4.4 Trạng thái migration TRƯỚC khi chạy — lưu lại nguyên văn
php artisan migrate:status | tee build/evidence/wwhn-184-deploy/migrate-status-before.log

# 4.5 Chạy migration
php artisan migrate --force | tee build/evidence/wwhn-184-deploy/migrate-run.log
php artisan migrate:status | tee build/evidence/wwhn-184-deploy/migrate-status-after.log
```

> **Lưu ý PostgreSQL** (register nêu "có PG posting migration/service change"): trên PG,
> hàng `stock_documents` đã POSTED được trigger bảo vệ khỏi mọi UPDATE sau đó. Vì vậy
> `postDocument()` bump `version` **trong cùng transaction, trước khi** status thành POSTED.
> Nếu một migration nào đó thay đổi trigger này, `Wwhn184PostReceiptRuntimeConcurrencyTest`
> sẽ đỏ ở kịch bản `post_vs_cancel` — đó là cảnh báo sớm, không được bỏ qua.

---

## 5. Health + smoke sau deploy

```bash
# 5.1 Health (readiness tách khỏi business health: 503 vẫn chứng minh HTTP server sống)
curl -sS -o build/evidence/wwhn-184-deploy/health.json -w '%{http_code}\n' https://<host>/api/health \
  | tee build/evidence/wwhn-184-deploy/health-http-status.txt

# 5.2 Smoke nghiệp vụ luồng nhập — chạy chính collection contract trên môi trường đã deploy
npx newman run "docs/postman/WWHN-184 Inbound Contract.postman_collection.json" \
  -e "docs/postman/WWHN-184 Inbound CI.postman_environment.json" \
  --env-var "base_url=https://<host>" \
  --env-var "managerEmail=<manager>" --env-var "managerPassword=<secret>" \
  --env-var "staffEmail=<staff>"   --env-var "staffPassword=<secret>" \
  --env-var "viewerEmail=<viewer>" --env-var "viewerPassword=<secret>" \
  --reporters cli,json --reporter-json-export build/evidence/wwhn-184-deploy/newman-smoke.result.json \
  2>&1 | tee build/evidence/wwhn-184-deploy/newman-smoke.raw.log
```

Smoke chỉ được coi là đạt khi `run.failures = []` **và** số request đã chạy bằng số request
khai trong collection. Một lần chạy một phần không phải bằng chứng closure.

---

## 6. Config phải xác nhận trên môi trường đã deploy

| Key | Giá trị bắt buộc | Vì sao |
|---|---|---|
| `APP_ENV` | `production` / `staging` (không phải `local`) | `AuthDatabaseSeeder` chỉ tạo tài khoản fixture ở môi trường dev; ở production chúng là backdoor (WWHN-300 / DEV300-P1-01) |
| `WMS_SEED_DEMO_ACCOUNTS` | không set hoặc `false` | như trên |
| `DB_CONNECTION` | `pgsql` | Bằng chứng race và ràng buộc posting được chứng nhận trên PostgreSQL |
| `APP_DEBUG` | `false` | §8.2: response lỗi không được lộ stack trace |
| `QUEUE_CONNECTION` | theo chuẩn môi trường | Post Receipt commit đồng bộ, không phụ thuộc queue |

---

## 7. Rollback

Luồng nhập **không có migration destructive trong phạm vi WWHN-184** — thay đổi của ticket
này nằm ở controller/contract/test/evidence. Nhưng candidate có thể được deploy chung với
migration của ticket khác trên `m1`, nên quy tắc vẫn là:

1. **Không** dùng `migrate:rollback` mù trên production. Xác định chính xác batch mà lần
   deploy này tạo ra bằng cách so `migrate-status-before.log` với `migrate-status-after.log`.
2. Rollback ứng dụng bằng cách deploy lại SHA trước đó (`<PREVIOUS_SHA>`), rồi chạy lại
   `verify-wwhn184-candidate-pin.mjs --expect-sha=<PREVIOUS_SHA>` để chứng minh đã về đúng bản cũ.
3. Nếu buộc phải rollback schema: chỉ rollback đúng số bước đã ghi ở mục 1, và chỉ sau khi
   DBA xác nhận không có dữ liệu nghiệp vụ mới phụ thuộc. Dữ liệu `stock_movements` là
   append-only (REQ-SRS-013) — **không được xoá movement đã Post để "dọn" rollback**; nếu
   cần đảo tác động phải dùng Reversal.
4. Sau rollback, chạy lại health + smoke ở mục 5 và lưu bằng chứng vào cùng thư mục.

---

## 8. Ô DevOps điền sau khi chạy xong

Cập nhật `RC_PIN.json → deployment`:

```json
"deployment": {
  "status": "DEPLOYED",
  "environment": "<DEV|STAGING>",
  "deployed_sha": "<40-hex>",
  "deployed_at": "<ISO-8601>",
  "runbook": "docs/evidence/WWHN-184/DEPLOY_RUNBOOK.md",
  "build_id": "<run id / build id>",
  "migration_status": "<no-op | applied: N migrations>",
  "health_http_status": <int>,
  "smoke": {"newman_failures": 0, "requests_executed": <int>},
  "rollback_tested": <true|false>
}
```

Và `gate.green_at_sha` = SHA của run gate đầu tiên có `conclusion=success`.
