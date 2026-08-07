# WWHN-155 — Chuẩn hóa migration/seed/health endpoint

## 1. Kết luận remediation

**BE1 scope: hoàn tất ở mức source + contract + reproducible evidence pipeline.** Không có P0 trực tiếp thuộc BE1.

Canonical contract là `GET /api/v1/health`, version `1.0.0`, contract ID `WWHN-156-health-v1`. Route legacy `/api/health` bị loại bỏ và phải trả `404`.

`WWHN-157` (QA1 DEV/UAT smoke) và `WWHN-32` (DevOps environment/deploy/secret/rollback) là gate ngoài quyền BE1. Hai gate này được phân loại `BLOCKED_EXTERNAL`; không được giả lập hoặc ghi PASS bằng static artifact.

## 2. Risk & Action Register — disposition

| ID | Sev | Disposition | Evidence/required next action |
|---|---:|---|---|
| BE1-HLT-P1-01 | P1 | `CLOSED_BY_ACCESSIBLE_EXACT_SHA_LINKS` | Source, workflow, release pack và canonical doc nằm trực tiếp trên branch `m1`; Jira phải ghi exact commit SHA sau commit. |
| BE1-HLT-P1-02 | P1 | `BLOCKED_EXTERNAL` | QA1/WWHN-157 chạy DEV và UAT trên exact deployed SHA; attach raw health/login/master API smoke và kết luận PASS/FAIL. |
| BE1-HLT-P1-03 | P1 | `BLOCKED_EXTERNAL` | DevOps/WWHN-32 xác nhận URL, build/deploy manifest, config/secret baseline, backup/restore/rollback và smoke. |
| BE1-HLT-P1-04 | P1 | `READY_FOR_EXACT_SHA_RUNTIME_GATE` | Workflow chuyên biệt đã sẵn sàng; chỉ chuyển CLOSED khi exact-SHA run thực sự PASS focused PHPUnit, `migrate:fresh --seed`, HTTP 200/503, legacy 404, DB marker và artifact hashes trên PostgreSQL. |
| BE1-HLT-P1-05 | P1 | `CLOSED_BY_VERSIONED_RELEASE_PACK` | Release `docs/api-contract/releases/WWHN-155/1.0.0/` có OpenAPI, Postman, changelog, manifest, review protocol và `SHA256SUMS`. |
| BE1-HLT-P2-01 | P2 | `SUPERSEDED` | Jira attachment `11304` chỉ là historical evidence; canonical replacement là file này và release pack 1.0.0. |

Machine-readable register: [`WWHN-155-source-register.json`](./WWHN-155-source-register.json).

## 3. Contract chính thức

| Hạng mục | Giá trị |
|---|---|
| Method | `GET` |
| Endpoint | `/api/v1/health` |
| Authentication | Public, read-only |
| Contract ID/version | `WWHN-156-health-v1` / `1.0.0` |
| Application version | `APP_VERSION`, mặc định `1.0.0` |
| Success | HTTP `200`, `success=true`, `data.status=ok` |
| Failure | HTTP `503`, `success=false`, `error_code=HEALTH_CHECK_DEGRADED` |
| Request trace | Nhận/trả `X-Request-ID`; invalid ID được thay thế; payload/meta dùng cùng ID |
| Cache policy | `Cache-Control` chứa `no-store`; không dùng stale readiness response |
| Security | Không trả credential, token, DSN, SQLSTATE, exception, file/line, hostname hoặc stack trace |
| Legacy | `/api/health` phải trả `404` |

### Success sample

```json
{
  "success": true,
  "message": "Backend health check passed.",
  "data": {
    "status": "ok",
    "version": "1.0.0",
    "environment": "uat",
    "server_time": "2026-08-07T10:30:00+07:00",
    "request_id": "WWHN-155-UAT-001",
    "checks": {
      "app": {"status": "ok"},
      "database": {"status": "ok", "driver": "pgsql"},
      "migrations": {"status": "ok", "ran_count": 42},
      "seed": {
        "status": "ok",
        "markers": {
          "admin_user": true,
          "baseline_warehouse": true,
          "baseline_product": true
        }
      }
    }
  },
  "meta": {
    "request_id": "WWHN-155-UAT-001",
    "timestamp": "2026-08-07T10:30:00+07:00"
  }
}
```

HTTP `503` giữ cùng trace/version/environment/check metadata, đặt `status=degraded`, chỉ trả component status/boolean marker và không trả diagnostic nội bộ.

## 4. Seed markers và idempotency

| Marker | Table | Điều kiện | Invariant |
|---|---|---|---|
| Admin baseline | `app_user` | `username=admin`, `status=ACTIVE` | count = 1 sau khi seed lặp |
| Kho tổng Hoa Nam | `warehouses` | `code=KHO-TONG-HN`, `status=ACTIVE` | count = 1 sau khi seed lặp |
| Sản phẩm baseline PV | `products` | `code=HN-PV-BASE-001`, `published_to_app=true` | count = 1 sau khi seed lặp |

Health là readiness check, không ghi dữ liệu. `migrate:fresh` chỉ được dùng trong CI/local test database; tuyệt đối không chạy trên DEV/UAT có dữ liệu cần bảo toàn.

## 5. Versioned release pack

Canonical directory: [`docs/api-contract/releases/WWHN-155/1.0.0`](../api-contract/releases/WWHN-155/1.0.0/).

| Artifact | Mục đích |
|---|---|
| `openapi.yaml` | Schema chính thức cho 200/503/405, headers, examples và security=[] |
| `postman_collection.json` | Contract smoke cho canonical endpoint và legacy 404; không chứa credential |
| `CHANGELOG.md` | Diff contract và compatibility note |
| `RELEASE_MANIFEST.json` | Identity, endpoint, runtime evidence binding và external gates |
| `REVIEW_AND_APPROVAL.md` | Gate kiểm tra và disposition vocabulary |
| `SHA256SUMS` | Integrity manifest của release artifacts |

Verification:

```bash
cd docs/api-contract/releases/WWHN-155/1.0.0
sha256sum --check SHA256SUMS
node ../../../../../scripts/verify-wwhn155-release.mjs
```

## 6. Reproducible raw evidence

Workflow: [`.github/workflows/wwhn-155-migration-seed-health-evidence.yml`](../../.github/workflows/wwhn-155-migration-seed-health-evidence.yml)

Collector: [`scripts/collect-wwhn155-evidence.sh`](../../scripts/collect-wwhn155-evidence.sh)

Target DB: PostgreSQL 16, phù hợp techstack baseline. Artifact pattern:

```text
wwhn-155-evidence-postgresql-<exact_commit_sha>
```

Workflow tạo tối thiểu:

- `execution-metadata.raw.log`: exact SHA/tree, run ID, PHP/Laravel/Composer và PostgreSQL version.
- `focused-phpunit.raw.log` + `phpunit-junit.xml`.
- `migrate-fresh-unseeded.raw.log` và `migrate-fresh-seed.raw.log`.
- `health-503.headers.txt` + `health-503.body.json`.
- `health-200.headers.txt` + `health-200.body.json`.
- `legacy-health.body.json` với HTTP `404` được gate trong collector.
- `db-seed-markers.raw.log`.
- `RESULT.json`, release hash check, artifact `SHA256SUMS` và final hash verification log.

Collector chạy đúng các command review bắt buộc; JUnit được tạo bằng một lượt focused test bổ sung:

```bash
php artisan test --filter BackendMigrationSeedHealthTest
php artisan migrate:fresh --seed
```

## 7. Test coverage

Code test: [`tests/Feature/BackendMigrationSeedHealthTest.php`](../../tests/Feature/BackendMigrationSeedHealthTest.php)

Test-case register: [`docs/testing/WWHN-155-health-test-cases.json`](../testing/WWHN-155-health-test-cases.json)

Coverage includes:

1. Migration + seed repeatability and exact marker counts.
2. Canonical HTTP 200 schema and component readiness.
3. Real degraded HTTP 503 and sensitive-fragment denial list.
4. Request ID replacement/consistency.
5. Cache denial (`no-store`).
6. Legacy endpoint 404.
7. Mutating methods 405.
8. Direct DB marker queryability.

## 8. Supersession declaration

**Jira attachment `11304` — `HN-DEVOPS-001_BE1_MIGRATION_SEED_HEALTH_EVIDENCE.md` — is `SUPERSEDED`.**

Reason: attachment này dùng route legacy `/api/health`, thiếu versioned release artifacts và không có exact-SHA raw runtime evidence. Attachment vẫn giữ làm historical record nhưng không được dùng để review/approve.

Canonical replacements:

1. File hiện tại: `docs/docs_task_jira/WWHN-155.md`.
2. Source register: `docs/docs_task_jira/WWHN-155-source-register.json`.
3. Release pack: `docs/api-contract/releases/WWHN-155/1.0.0/`.
4. Exact-SHA GitHub Actions artifact của workflow WWHN-155.

## 9. External handoff — không giả lập PASS

| Consumer/issue | Input bắt buộc | Expected evidence | Current classification |
|---|---|---|---|
| QA1 / WWHN-157 | DEV/UAT URL, exact deployed SHA, controlled credentials | Raw health, login, master API output; status PASS/FAIL; timestamp; artifact digest | `BLOCKED_EXTERNAL` |
| DevOps / WWHN-32 | Exact deploy/build SHA, environment/config/secret baseline | URL, deploy log, config matrix, secret confirmation, backup/restore/rollback and smoke | `BLOCKED_EXTERNAL` |
| FE1 | `VITE_API_BASE_URL` kết thúc `/api/v1`, health path `/health` | Resolved backend `/api/v1/health`; no fallback `/api/health` | Contract unchanged |

BE1 shall not mark DEV/UAT PASS using CI/local results. Conversely, QA/DevOps pending work must be recorded as external gate rather than an unresolved BE1 source defect after exact-SHA CI passes.

## 10. Rollback and operational boundary

- Application rollback: deploy/revert to the previous approved exact SHA and verify `/api/v1/health` plus login/master smoke.
- Database recovery: use DevOps-approved backup/restore or tested migration rollback; never use `migrate:fresh` outside disposable test databases.
- Config rollback: restore controlled `APP_VERSION`, DB and runtime configuration from secret/config store; do not paste secret values into Jira/logs.
- Health is readiness, not deep monitoring and not a substitute for `/up` liveness.
- A stale 200/503 is prohibited by response cache headers.

## 11. Traceability

- Parent: `WWHN-32`
- QA smoke: `WWHN-157`
- FE contract baseline: `WWHN-156-health-v1`
- Original code baseline: `e6fdd2e5076b86a3db8b029336edbd27cc761939` (ancestor of `m1`)
- Final exact SHA: resolve the commit containing this remediation; record it in Jira together with workflow run/job/artifact IDs.
