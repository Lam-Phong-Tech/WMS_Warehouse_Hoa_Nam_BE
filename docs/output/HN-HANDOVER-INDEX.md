# HN-HANDOVER-INDEX — Chỉ mục gói bàn giao (parent WWHN-83)

- ID tài liệu: `HN-HANDOVER-INDEX-001`
- Parent: `WWHN-83` `[HANDOVER] Gói bàn giao sản phẩm, kỹ thuật, QA, release và vận hành`
- Cập nhật: 2026-08-19 (mở sổ ở `WWHN-310` / DEV310-P1-06)
- Owner sổ: BAS · Người xác nhận vận hành: DevOps · Người nhận: theo cột *Recipient*

## 1. Vì sao có file này

Trước đây mỗi ticket bàn giao tự viết gói riêng dưới `docs/output/<PACKAGE-ID>/` và chỉ nói trong
ticket của mình. Không có một chỗ nào trả lời được ba câu hỏi của bên nhận:

1. Có tất cả bao nhiêu gói và gói nào đã bàn giao?
2. Gói đó ứng với **exact revision** nào (không phải "latest m1")?
3. Ai đã xác nhận đã nhận, và xác nhận đó nằm ở đâu?

Sổ này là nơi duy nhất trả lời ba câu đó. Một gói **chỉ được coi là đã bàn giao** khi có đủ: đường
dẫn artifact + pin nguồn (exact SHA) + xác nhận của DevOps + xác nhận của người nhận.

Quy tắc: **không dùng tên nhánh động làm identity.** Mọi dòng phải trỏ tới exact SHA hoặc tới file
pin nguồn chứa exact SHA.

## 2. Chỉ mục gói

| # | Package ID | Nội dung | Jira | Role | Artifact | Pin nguồn (exact SHA) | Trạng thái |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `BE-HN-MIG-001` | Migration pack: template Import Package v1.2, mapping, error file, dry-run/rollback rehearsal | `WWHN-260` (liên quan `WWHN-69`, `WWHN-225`, `WWHN-299`) | BE | `docs/output/BE-HN-MIG-001-migration-pack/` | `docs/evidence/WWHN-260/` | Đã bàn giao — chờ xác nhận người nhận |
| 2 | `BE2-HN-HO-001` | **Data handover**: từ điển dữ liệu/toàn vẹn, migration/seed/nạp dữ liệu cuối, danh mục báo cáo, kiểm chứng/evidence | `WWHN-310` | BE2 | `docs/output/BE2-HN-HO-001-data-handover/` (4 phần) | `docs/evidence/WWHN-310/WWHN-310_SOURCE_PIN.json` | **Đã bàn giao** — vòng 2 đóng DEV310-P1-01..06 |
| 3 | `BE-HN-API-001` | Backend handover: kiến trúc, hợp đồng API, RBAC/permission, vận hành | `WWHN-309` | BE1 | `docs/api-contract/`, `docs/architecture/`, `docs/permissions/`, `docs/docs_task_jira/WWHN-309.md` | `docs/docs_task_jira/WWHN-309.md` §pin | Đã bàn giao |
| 4 | `BE2-HN-TEC-001` | Schema/catalog/report/warranty/PV module evidence | `HN-TEC-001` (temp key) | BE2 | `docs/output/BE2-HN-TEC-001-schema-catalog-report-warranty-pv/` | `04-release-manifest.json` | Đã bàn giao |
| 5 | `BE2-HN-QA-002` | Seed scripts: lệnh, dữ liệu, idempotency/bảo mật, kiểm chứng | `HN-QA-002` (temp key) | BE2 | `docs/output/BE2-HN-QA-002-seed-scripts/` | chưa pin exact SHA — **thiếu**, BAS phải bổ sung khi bàn giao chính thức |
| 6 | `BE2-HN-MC-001` | SKU/Item/Component (bản cũ, đã archive dưới `old_src/`) | — | BE2 | `docs/output/BE2-HN-MC-001-sku-item-component/old_src/` | không pin — **ARCHIVED**, không dùng để nghiệm thu |

## 3. Điều kiện nghiệm thu từng gói (áp cho mọi dòng ở §2)

| Bước | Ai làm | Bằng chứng phải có |
| --- | --- | --- |
| 1. Gói tồn tại trong repo | BE/BE2 | Đường dẫn artifact + exact SHA |
| 2. Nội dung khớp baseline | BA/BAS + Tech Lead | Decision Record / mã amendment + checksum tài liệu nguồn |
| 3. Chạy được thật | DevOps | Lệnh trong runbook chạy trên môi trường đích + log/exit code |
| 4. Người nhận xác nhận | Recipient | Comment trên ticket bàn giao, nêu **đúng** package ID + exact SHA |

Không bước nào được thay bằng lời tự khai của người thực hiện (implementer self-claim).

## 4. Data handover `BE2-HN-HO-001` — chi tiết cho bên nhận

| Câu hỏi | Trả lời |
| --- | --- |
| Bàn giao cái gì? | 4 tệp trong `docs/output/BE2-HN-HO-001-data-handover/`: `00-summary.md`, `01-data-dictionary-and-integrity.md`, `02-migration-and-seed.md`, `03-report-catalog.md`, `04-verification-and-evidence.md` |
| Tài liệu dữ liệu chuẩn nào? | `HN-WMS-ERD-DD-001` bản hiệu lực `1.0+AMD008`, checksum pin ở `docs/contracts/erd-document-pin.json` (giống nhau ở repo BE và FE) |
| Quyết định kèm theo? | `docs/decision-records/DEC-DATA-001-defect-catalogs-naming-and-erd-amendments.md` |
| Vận hành nạp dữ liệu cuối? | `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md` + job `php artisan migration:final-load` (không có endpoint public/admin — `DEC-MIG-API-001`) |
| Bằng chứng chạy thật? | `docs/evidence/WWHN-310/round2/` (dry-run/commit/rollback + đối soát + log PostgreSQL) |
| Cổng kiểm chứng lại được? | `tests/Feature/Wwhn310DataHandoverContractTest.php`, `Wwhn310ErdDocumentPinTest.php`, `Wwhn310Round2ClosureTest.php`, `MigrationFinalDataLoadTest.php` |
| Ai ký gì? | Tech Lead: physical schema/ERD amendment · BA/BAS: baseline tài liệu + sổ này · QA1 (`WWHN-312`): retest độc lập · DevOps: migration/cutover chạy được · Recipient: đã nhận đúng gói |

## 5. Ràng buộc thứ tự với parent

`WWHN-83` không cần Done trước subtask. Nhưng **acceptance của từng gói phải có trước khi parent
đóng**: sổ này chính là danh sách acceptance đó. Một dòng ở §2 thiếu pin nguồn hoặc thiếu xác nhận
người nhận thì parent chưa đủ điều kiện đóng.
