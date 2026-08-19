# WWHN-195 — Exact SHA pin (DEV195-P1-03R2)

Register R2 ghi: *"61859e… relevant nhưng không pin; current HEAD khác; CI failure"*.
Bản pin dưới đây tách đúng phần WWHN-195 ra khỏi dòng commit chung của nhánh `m1`.

## 1. Candidate

| Repo | Candidate SHA | Nhánh |
|---|---|---|
| BE `HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE` | **`ea93dd6e391bad3e2fe1e9da064e9ced8db9f1c1`** | `m1` |
| FE `HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE` | **`74f8be7f6b770f5135ef28a4c5d183c4a7b56f72`** | `m1` |

**Pin theo commit-cuối-chạm-artifact, không pin theo HEAD.** Nhánh `m1` có nhiều
phiên làm việc song song commit liên tục, nên HEAD nhảy liên tục và không phải mốc
ổn định của một task. Kiểm chứng lại được bằng:

```bash
# BE: commit cuối chạm artifact của WWHN-195 phải đúng bằng candidate
git log --oneline -1 -- \
  docs/api-contract/WWHN-195-outbound-openapi.yaml \
  Modules/Warehouse/routes/api.php \
  Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php \
  tests/Feature/Wwhn195OutboundContractAlignmentTest.php
# → ea93dd6

# và candidate phải nằm trên dòng lịch sử hiện tại
git merge-base --is-ancestor ea93dd6 HEAD && echo OK
```

## 2. Artifact digest (SHA-256)

| File | SHA-256 |
|---|---|
| `docs/api-contract/WWHN-195-outbound-openapi.yaml` | `699df4b2551f5d271c1c5ba04fc68ec375b42ac04d1babd6e42f6f39f4bcd41f` |
| `docs/api-contract/WWHN-195-outbound-openapi.json` | `8b8640f31fdfa3bb0c3e73c59f94a37331d33bfa8bf0b7b25da8c14179e52c00` |
| `docs/postman/WWHN-195 Outbound Contract.postman_collection.json` | `ea96657372ddfc697ecae70da11d23330b385fe3badce735ddfdaa15ee64783c` |
| `docs/testing/WWHN-195-outbound-test-cases.json` | `104487bbdf217d92b1fe69e4247c1d0fddd403488d9c91e0897c5d8f0c4828e2` |
| `scripts/verify-wwhn-195-outbound-contract.mjs` | `ac2e2498d217b00e617f91af7c988c10b1963444699b88bef40403d5d8fdd2f0` |

```bash
sha256sum docs/api-contract/WWHN-195-outbound-openapi.yaml \
          docs/api-contract/WWHN-195-outbound-openapi.json \
          "docs/postman/WWHN-195 Outbound Contract.postman_collection.json" \
          docs/testing/WWHN-195-outbound-test-cases.json \
          scripts/verify-wwhn-195-outbound-contract.mjs
```

## 3. Cách chạy lại toàn bộ gate tại đúng candidate

```bash
git -C HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE checkout ea93dd6
git -C HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE checkout 74f8be7

# BE — contract verifier (không cần DB)
node scripts/verify-wwhn-195-outbound-contract.mjs        # 6/6

# BE — bộ test phạm vi WWHN-195 trên sqlite (đúng driver CI)
php vendor/bin/phpunit --filter="Wwhn195OutboundContractAlignmentTest|OutboundDocumentFlowTest|PostIssueConcurrencyBehaviorTest|PostIssueAuditLogTest|OutboundPermissionTest|Flow06PackingLabelAlignmentTest|AnnexCOutboundLifecycleTest|CurrentBaselineFlowContractSourceTest|OutboundDocsAlignmentContractTest"
# → 52 passed / 52

# BE — cùng bộ đó trên PostgreSQL
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hn_wwhn195 \
DB_USERNAME=postgres DB_PASSWORD=postgres php artisan migrate --force
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hn_wwhn195 \
DB_USERNAME=postgres DB_PASSWORD=postgres php vendor/bin/phpunit --filter="…cùng filter…"
# → 52 passed / 52

# FE
npm run typecheck   # sạch
npm test            # 90 file / 701 test pass
```

## 4. Về "CI failure" mà register nêu

Full suite BE trên sqlite tại candidate: **1086 passed / 1126**, 35 skipped, **4 failed**.

Bốn đỏ đó **đã được chứng minh là tồn tại sẵn**, không do WWHN-195: dựng worktree
tách rời tại `HEAD` sạch (commit `0c2d954`, không chứa thay đổi WWHN-195) và chạy đúng
bốn test đó → tái hiện y hệt. Chi tiết + chủ sở hữu từng đỏ ghi ở `07-RUN-RESULTS.md` §5.

Vì vậy gate Build/Release của WWHN-195 pin theo **bộ test phạm vi WWHN-195 + verifier +
bằng chứng PostgreSQL**, và ghi rõ 4 đỏ ngoài phạm vi kèm owner thay vì tuyên bố
"full suite xanh" khi thực tế không phải vậy.
