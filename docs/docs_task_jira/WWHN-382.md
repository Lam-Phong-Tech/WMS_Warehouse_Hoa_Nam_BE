# WWHN-382 — Đóng P0/P1/P2 EXACT FIX ACTION REGISTER R4 (dashboard aggregate)

Đối tượng: `GET /api/v1/reports/dashboard-quick`.
Liên quan: WWHN-88 (parent), WWHN-327 (FE), WWHN-328 (BE), WWHN-329 (QA2 độc lập).
Nhánh: `m1` (không tạo nhánh riêng).
Kết quả vòng trước: **74/100 — chưa đạt**.

## Hồ sơ

| Tệp | Nội dung |
|---|---|
| [`docs/evidence/WWHN-382/R4_CLOSURE.md`](../evidence/WWHN-382/R4_CLOSURE.md) | Bản đóng đầy đủ 9 mục + chẩn đoán vì sao ba vòng trước không đóng được |
| [`docs/evidence/WWHN-382/R4_REGISTER.json`](../evidence/WWHN-382/R4_REGISTER.json) | Register máy đọc được: trạng thái, owner, gate, bằng chứng từng mục |
| [`docs/evidence/WWHN-382/CANDIDATE_PIN.json`](../evidence/WWHN-382/CANDIDATE_PIN.json) | Repo/branch/SHA đủ 40 ký tự BE+FE, phạm vi tệp kèm blob, nguồn superseded |
| [`docs/evidence/WWHN-382/CONTRACT_PIN.json`](../evidence/WWHN-382/CONTRACT_PIN.json) | Fingerprint hợp đồng mức đối tượng |
| [`docs/evidence/WWHN-382/SOURCE_SUPERSESSION.md`](../evidence/WWHN-382/SOURCE_SUPERSESSION.md) | Supersede `m1-be-2` và 6 nguồn khác, kèm lý do |
| [`docs/evidence/WWHN-382/DIFF_SCOPE_MAP.md`](../evidence/WWHN-382/DIFF_SCOPE_MAP.md) | Phạm vi tệp của task bên trong lịch sử commit gộp + disposition direct-commit |
| [`docs/evidence/WWHN-382/QA2_RERUN_RUNBOOK.md`](../evidence/WWHN-382/QA2_RERUN_RUNBOOK.md) | Runbook để QA2 tự chạy lại tại đúng cặp candidate |
| [`docs/evidence/WWHN-328/KPI_MATRIX.md`](../evidence/WWHN-328/KPI_MATRIX.md) | KPI matrix **v1.2** — vật để BA ký |

## Chạy cổng

```bash
node scripts/verify-wwhn-382-closure.mjs
```

```bash
php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn328R4ClosureTest.php
```

## Ba sửa chữa gốc của vòng này

1. **Candidate mang mã Jira**, không còn `gỡ cờ N` — reviewer đọc được phạm vi task từ chính commit.
2. **Pin ở mức đối tượng** thay vì hash cả tệp — `storage/api-docs/api-docs.json` và Postman
   collection là tệp dùng chung, bị sinh lại bởi mọi task chạm route, nên hash tệp chắc chắn rot.
   Fingerprint operation thì không.
3. **Bất biến trên dải commit** thay vì tin vào tên nhánh — `m1` là nhánh chung của nhiều phiên
   làm việc song song; một cái tên di động không phải bằng chứng.

## Bổ sung độ phủ test (5 test mới)

`tests/Feature/Wwhn328R4ClosureTest.php` khoá đúng những khẳng định trước đây chỉ tồn tại dưới
dạng văn bản: ngoại lệ warranty GLOBAL, bất biến đối soát dashboard ↔ RPT-INV-01, trần 50 dòng
mẫu vs tổng thật, OpenAPI đúng bằng field set runtime, và đúng một route aggregate.

Cả hai driver xanh: riêng khoá R4 là 5/5 (25 assertions); đo hợp nhất với contract vòng R3 là 22/22 (86 assertions) trên cả SQLite lẫn PostgreSQL 16. Lượt PostgreSQL đầu tiên đỏ vì
fixture bảo hành vi phạm hai CHECK chỉ có trên PG — đã sửa, ghi lại trong `DIFF_SCOPE_MAP.md` §6.

## Phần cần người ký (DEV không ký thay)

| Mục | Vai |
|---|---|
| DEV328-P1-01R4 — duyệt KPI matrix v1.2 | BA Main |
| DEV328-P1-02R4 / P1-03R4 / P1-04R4 — supersede, phạm vi diff, hợp đồng | Tech Lead |
| DEV328-P1-05R4 / P2-01R4 / P2-02R4 — issue link, narrative, trường Sprint | PM/BAS |
| DEV328-P1-06R4 — chạy lại độc lập, báo cáo trên WWHN-329 | QA2 |
| DEV328-P1-07R4 — chấp nhận cùng một cặp candidate | BA Main + QA2 |
