# WWHN-201 — Runbook đóng DEV201-P1-01R … P1-05

Mọi thứ trong thư mục này đều **chạy lại được**. Không có kết luận nào ở đây dựa
trên việc đọc tay rồi suy diễn.

## 1. Bản đồ finding → artifact

| Finding | Đóng bằng | Chủ chạy |
|---|---|---|
| `DEV201-P1-01R` (ma trận idempotency/race qua HTTP) | `tests/Feature/Wwhn201PostIssueErrorContractTest.php` + `Wwhn201PostIssueAtomicNonNegativeStockTest.php` (deterministic, 6 ô) và `scripts/wwhn201-runtime-concurrency.php` (race thật ≥2 connection PostgreSQL) | DEV dựng, QA1 chạy |
| `DEV201-P1-02R` (artifact bất biến) | `scripts/wwhn201-evidence-manifest.php` → `MANIFEST.json` + `MANIFEST.sha256`, kiểm bằng `scripts/verify-wwhn-201-evidence.mjs --gate` | DEV + DevOps → QA1 |
| `DEV201-P1-03R` (pin candidate) | `scripts/wwhn-201-candidate-pin.mjs` → `CANDIDATE_PIN.json` | DEV + DevOps → Tech Lead |
| `DEV201-P1-04R` (khoá contract + bind Postman) | `scripts/wwhn-201-outbound-contract-lock.mjs` → OpenAPI + Postman + `WWHN-201-outbound-contract-lock.json`, kiểm bằng `scripts/verify-wwhn-201-contract.mjs` | BA + Tech Lead → FE + QA1 |
| `DEV201-P1-05` (409 vs 422) | `docs/decision-records/DEC-OUT-STOCK-HTTP-001-insufficient-stock-http-status.md`, cưỡng chế bằng verifier contract + test BE + `src/shared/api/post-issue-contract.test.ts` (FE) | BA + Tech Lead + DEV → QA1 |

## 2. Chạy gate (đúng thứ tự)

```bash
# (1) Deterministic — không cần DB ngoài
./vendor/bin/phpunit tests/Feature/Wwhn201PostIssueErrorContractTest.php \
                     tests/Feature/Wwhn201PostIssueAtomicNonNegativeStockTest.php

# (2) Hợp đồng Outbound + Postman + bảng lỗi §8.2
node scripts/verify-wwhn-201-contract.mjs

# (3) Race THẬT trên PostgreSQL (cần credential DevOps)
DB_CONNECTION=pgsql php scripts/wwhn201-runtime-concurrency.php storage/app/wwhn-201-evidence
DB_CONNECTION=pgsql php scripts/wwhn201-evidence-manifest.php storage/app/wwhn-201-evidence

# (4) Pin candidate — CHỈ chạy khi working tree đã sạch
node scripts/wwhn-201-candidate-pin.mjs

# (5) Kiểm toàn bộ bằng chứng ở chế độ đóng gate
node scripts/verify-wwhn-201-evidence.mjs --gate
```

## 3. Hai chế độ của verifier bằng chứng

- **STRUCTURE** (mặc định): digest nguyên vẹn, pin trỏ commit, behavior/harness SHA
  của MANIFEST khớp pin. Chạy được ở mọi máy.
- **CLOSURE** (`--gate`): đòi thêm `db_evidence_status = OK`, driver `pgsql`,
  summary `PASS`, và **cả hai** scenario phải chứng minh
  `two_distinct_backend_connections` + `real_contention_proven` +
  `balance_never_negative`, kèm bằng chứng index của
  `inventory_balances` / `stock_documents` / `idempotency_keys`.

STRUCTURE xanh **không** đóng được gate. Chỉ CLOSURE xanh mới đóng `DEV201-P1-02R`.

## 4. Trạng thái hiện tại (2026-08-19)

| Hạng mục | Trạng thái |
|---|---|
| Test deterministic (8 test / 65 assertion) | ✅ xanh |
| `verify-wwhn-201-contract.mjs` | ✅ PASS ở contract `2.0.0-R2` |
| FE `post-issue-contract.test.ts` (9 test) | ✅ xanh |
| `runtime-concurrency-summary.json` | ✅ `PASS` (2 scenario), đã đóng gói kèm digest |
| `db_evidence_status` | ⚠️ `UNAVAILABLE` — máy soạn artifact không có credential PostgreSQL. **DevOps phải chạy lại bước (3)** để điền version/migration/index. |
| `CANDIDATE_PIN.json` | ⚠️ **chưa hợp lệ** — xem mục 5. |

## 5. Vì sao pin chưa đóng được (và cách đóng)

`verify-wwhn-201-evidence.mjs` đang báo đúng một lỗi:

> Pin nhóm behavior không hợp lệ: còn thay đổi CHƯA COMMIT
> (`CurrentBaselineStockFlowController.php`, `CurrentBaselineStockService.php`,
> `routes/api.php`).

Đây **chính là** `DEV201-P1-03R` ("branch tiếp tục chạy") ở dạng máy phát hiện được:
ba file quyết định hành vi Post Issue đang bị task khác sửa dở trên cùng nhánh `m1`.
Pin trỏ vào working tree là pin vô nghĩa.

Cách đóng — không cần sửa code WWHN-201 thêm:

1. Đợi task đang sửa ba file trên commit xong (`git status --short` sạch cho tập file
   đó).
2. Chạy lại `node scripts/wwhn-201-candidate-pin.mjs`.
3. Chạy lại `php scripts/wwhn201-evidence-manifest.php` để MANIFEST và pin cùng SHA.
4. `node scripts/verify-wwhn-201-evidence.mjs --gate` phải xanh.

Ngoài ra `behavior_drift_since_reviewed` đang liệt kê **4 commit** đã chạm hành vi kể
từ SHA `74100825…` mà register nhắc tới (`WWHN-234`, `WWHN-252`, `WWHN-289`,
`0b32578` cache). Đó là phần delta Tech Lead cần duyệt — pin ghi sẵn danh sách để
không phải duyệt lại toàn bộ. SHA `7241621…` đã được đánh dấu **superseded** ngay
trong pin.
