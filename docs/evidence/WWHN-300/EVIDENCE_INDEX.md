# WWHN-300 — Evidence index (DEV300-P2-01)

Nhánh `m1`. Commit code của vòng này:

```
commit  = 671cc4cc05c39703d0c96f534193c49ddbdbbba5
tree    = 1ef76ac0345a8ddc423d48423b5bf35c2e6ee9a1
message = fix(WWHN-300): dong DEV300-P1-01..P1-04 - gate readiness fail-closed
```

## Digest từng tệp tại commit trên

| Tệp | sha256 |
|---|---|
| `.env.example` | `62f236462d828ac199bf686b9d770ff1d984adbae00681f6a2a017ee431d852d` |
| `.github/workflows/wwhn-300-backend-golive-check.yml` | `001426b8ccc37a55caa0564b06bb7c8e8c66067c1f63f4d8f84ec76dd00637bf` |
| `Modules/Auth/database/seeders/AuthDatabaseSeeder.php` | `a9688b0cf59760f8c3ab5137da20744e805ca623032f1ccde81470f3c41cdcc6` |
| `Modules/Auth/database/seeders/WmsPermissionCompatibilitySeeder.php` | `ed51f1e7b7be0a28eebc974206a94bdac30dae00d2442d8f988373247edcab48` |
| `app/Console/Commands/BackendGoLiveCheck.php` | `50cbabd3c47a0c9f98da9542a93d545fb11d1a9ee3ab29696f418719e84ed5c1` |
| `config/golive.php` | `f6c24aa805e23ceb0865a5b0683fa6ad4963a2767aae72a818e80c3cfbcef09c` |
| `database/golive/fail_forward_allowlist.php` | `bd0d6df7e07ea566ff4a2643f3dbaebc65f67cf10f83db2b39ed8b60e4b10bfb` |
| `database/golive/route_permission_contract.php` | `cc946571d62b07e80b2718cdca1ffaca80fcc329f53ba52abe87de1dbcbac3cc` |
| `docs/evidence/WWHN-300/BACKEND_GOLIVE_CHECK.md` | `090cf2092974668bf49c19ee78070aa5228cb57ae6892a528ae88b4314344dd6` |
| `docs/evidence/WWHN-300/DEV300_P1_P2_CLOSURE.md` | `7cf0df0e486cd61de02d6fe010b419cd6b54f8bd707a953f8650c30d5ee0b3d7` |
| `docs/evidence/WWHN-300/P0_P1_P2_ACTION_REGISTER.md` | `3761c4f9dbf2729fa5d7b068b6500bed98b29ede5526b09b78b167a3988a6fcd` |
| `docs/evidence/WWHN-300/SIGNOFF_COMMENTS.md` | `c16def1822bb4c9a7e395994027fb2cf21d6003aaeaa6563c42a1fb4b5e6ac8e` |
| `docs/evidence/WWHN-300/SIGNOFF_COMMENTS_R2.md` | `61a9e6facb8947b30fcba6c3f86e5dea0c0b2a5d39b9d6ad9f5439e77f46fbac` |
| `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json` | `c7f9e15bb4dd28f66b0356bba792d19eeab3c3bd417b5f92a05af425ea362c40` |
| `scripts/collect-wwhn300-golive-evidence.sh` | `1cf41f49b03a3971dfd628aff6fe65a63d17d8f3afa28aa99437da9086ab658c` |
| `tests/Feature/Wwhn300BackendGoLiveCheckTest.php` | `5fc22691b91c467e0aaa39fb40b8d447b4121df6051d3263944489fe8cfe1368` |
| `tests/Feature/Wwhn300ProductionSafeSeedingTest.php` | `c19e2ced4c846d1df8a8b94e2997fede43f5ac7e96848b7802bcb6fe58506922` |

## Kết quả chạy thật tại commit trên

| Hạng mục | Lệnh | Kết quả |
|---|---|---|
| Gate WWHN-300 + seeding an toàn | `./vendor/bin/phpunit --filter 'Wwhn300BackendGoLiveCheckTest\|Wwhn300ProductionSafeSeedingTest\|HoaNamErdSchemaDropRelationTest\|Wwhn309PermissionCatalogEvidenceTest'` | **34/34 PASS**, 1200 assertion |
| Ma trận route đã pin | `php artisan wms:golive-check --write-route-contract` | **231 route** |
| Allowlist fail-forward | so tập thực tế ↔ allowlist | **75/75**, undispositioned = 0, stale = 0 |
| Định dạng | `./vendor/bin/pint --test <8 tệp>` | PASS |

Trong đó **13 case là negative / fault-injection** (mỗi case phải làm lệnh exit 1):
mật khẩu mặc định trên tài khoản ACTIVE; tài khoản/role fixture còn sống;
`--allow-debug` trên production; gỡ unique index idempotency; xóa `audit_outbox`;
xóa `reconciliation_incident`; gắn sai permission cho `post-issue`; mất ma trận đã pin;
mất allowlist fail-forward; 3 case seeder từ chối credential (thiếu / trùng mặc định / quá ngắn).

## Còn phải thực hiện để đóng DEV300-P1-05 (không thuộc BE1)

```bash
git push origin m1                     # đẩy commit 671cc4c
gh workflow run wwhn-300-backend-golive-check.yml --ref m1
gh run list --workflow=wwhn-300-backend-golive-check.yml --limit 1
```

Sau đó lưu: URL run, digest artifact (`SHA256SUMS` trong artifact
`wwhn-300-golive-evidence-<sha>`), và bật lại branch protection + required check cho `m1`.

## Kiểm chứng lại digest

```bash
git show 671cc4cc05c39703d0c96f534193c49ddbdbbba5:<đường-dẫn> | sha256sum
```
