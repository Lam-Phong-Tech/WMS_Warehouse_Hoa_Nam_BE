# WWHN-310 — Register đóng P1/P2 vòng 2 (đánh giá 75/100)

- Jira: `WWHN-310` `[BE] Hoàn thiện tài liệu dữ liệu/migration/báo cáo` · parent `WWHN-83`
- Ngày: 2026-08-19 · nhánh: `m1` (không tạo nhánh riêng)
- Pin nguồn: `docs/evidence/WWHN-310/WWHN-310_SOURCE_PIN.json`
- Gói bàn giao: `docs/output/BE2-HN-HO-001-data-handover/` · sổ bàn giao: `docs/output/HN-HANDOVER-INDEX.md`

Nguyên tắc của vòng này: **mỗi hạng mục phải đóng bằng một artifact chạy lại được**, không đóng bằng
lời của người thực hiện. Cổng tự kiểm: `tests/Feature/Wwhn310Round2ClosureTest.php` —
test này đọc chính các artifact dưới đây và đỏ nếu register nói một đằng, evidence một nẻo.

---

## DEV310-P1-01 — Traceability: supersede `m1-be-2`, pin một final candidate

**Đã làm**

| Việc | Kết quả |
| --- | --- |
| Formal supersede `m1-be-2` | `m1-be-2` @ `6081649` được chứng minh là **ancestor lịch sử thuần** của `m1`: `git merge-base --is-ancestor 6081649 m1` = true; `git rev-list --count 6081649..m1` = **719**; `git rev-list --count m1..6081649` = **0**. Không có commit nào của WWHN-310 nằm ngoài `m1`. |
| Pin exact SHA | `docs/evidence/WWHN-310/WWHN-310_SOURCE_PIN.json` — `code_candidate.sha` (vòng 1), `final_candidate.sha` (vòng 2), `frontend.sha` (repo FE) |
| File set + hash | 20 file thuộc WWHN-310 kèm **blob hash** tại code candidate. Cần vì commit `127c573` là commit gộp 80 file của một phiên song song và **không mang tên WWHN-310** — identity không thể suy từ commit message |
| Sửa câu "chưa commit" | `docs/docs_task_jira/WWHN-310.md` §6 nay là mục "Pin nguồn (DEV310-P1-01)" ghi đúng trạng thái đã commit |
| Disposition PR/direct-commit | **DIRECT-COMMIT**, ghi chính thức trong pin: `m1` là nhánh làm việc chung nhiều phiên; không có PR nên ranh giới review = *file set + blob hash tại exact SHA*, reviewer duyệt bằng comment trên WWHN-310 |

**Xác minh**

```bash
git -C <BE_repo> merge-base --is-ancestor 60816499066f9f493596e326dc41116ef32f5c4b origin/m1 && echo SUPERSEDE_OK
git -C <BE_repo> rev-parse 127c573a7236ad1c25829eb92011a4914360b840:Modules/Warehouse/database/migrations/2026_08_18_193000_align_defect_catalogs_to_erd_entity_15.php
```

---

## DEV310-P1-02 — Domain/Contract: review ERD/Data Dictionary + không tự sửa baseline

**Đã làm**: `docs/evidence/WWHN-310/ERD_AMENDMENT_REVIEW_RECORD.md` — hồ sơ review gọn cho Tech Lead
và BA/BAS, gồm:

1. **Checksum chính xác** của ERD ở cả hai repo (`5209358cb0e9d91ced71b3b2ebaeb6e50d7f4304c03618a81a6a6a0adabf12e2`, 205227 bytes) và của file pin.
2. **Phạm vi thay đổi đến từng dòng**: vòng 1 `+44/−6`; sáu dòng đổi được liệt kê từng dòng một, kèm lý do và mã ERD-AMD. Vòng 2 `+30/−0`.
3. **Bằng chứng không tự sửa baseline**: `docs/HN_SRS_FRD_v1.12_FULL.md` ở BE là `new file mode` (thêm vào repo, 0 dòng bị xoá) và **byte-for-byte bằng** bản của BA ở repo FE (`sha256 02cd010c…`); wireframe không bị chạm.
4. **Công thức báo cáo**: chỉ `RPT-LABEL-01` đổi, ghi rõ trước/sau/lý do; 8 báo cáo còn lại không đổi.

**Root cause đã sửa, không chỉ ghi nhận**: tài liệu ERD đã amend 7 lần mà trường `Version` vẫn là
`1.0`, nên câu "đã review ERD" không chỉ được vào bản nào. Vòng này thêm:

| Thành phần | Vai trò |
| --- | --- |
| §0.0 "Bản hiệu lực" `1.0+AMD008` | Mã bản có hậu tố bằng đúng mã ERD-AMD lớn nhất |
| `ERD-AMD-008` | Ghi chính thay đổi governance đó vào sổ đăng ký |
| `docs/contracts/erd-document-pin.json` | Pin `effective_revision` + `sha256` + `bytes` + danh sách amendment — **cùng một file, cùng giá trị, ở cả hai repo** |
| `tests/Feature/Wwhn310ErdDocumentPinTest.php` (BE) | Băm ERD trong repo, so với pin; kiểm sổ đăng ký khớp danh sách pin; kiểm hậu tố = mã lớn nhất |
| `src/test/wwhn-310-erd-document-pin.test.ts` (FE) | Cùng phép kiểm ở repo FE |

Vì hai repo pin **cùng một chuỗi sha256**, ERD lệch nhau giữa hai repo là đỏ ở cả hai phía — lệch
từng xảy ra (bản FE còn quy tắc "PRODUCT Post Issue yêu cầu active RFID" đã bị SRS v1.12 thay thế)
và không cổng nào bắt được.

---

## DEV310-P1-03 — Test Ready: chạy lại trên PostgreSQL tại exact candidate

**Đã làm**: chạy trên PostgreSQL 16 (container `wms_warehouse_hoa_nam_be-postgres-1`), database riêng
cho từng tiến trình.

| Phép đo | Kết quả | Raw |
| --- | --- | --- |
| `migrate:fresh --seed --force` (PostgreSQL, DB `hn_wwhn310_fdl`) | **PASS** (exit 0) — chạy lại nhiều lần trong phiên, lần cuối sạch | `docs/evidence/WWHN-310/round2/fdl-migrate-fresh-seed.log` |
| Nhóm cổng dữ liệu (ERD parity + baseline contract + business invariant + runtime mirror + Wwhn310*) | PLACEHOLDER_GATE | `docs/evidence/WWHN-310/round2/pg-focused-gate.log` |
| FE `typecheck` + `lint` + `test` | typecheck **PASS**; `vitest run` **654/656 pass**; 2 đỏ thuộc ticket khác (xem danh sách bên dưới) | `docs/evidence/WWHN-310/fe-gate.log` (repo FE) |

**Danh sách đỏ được phép tồn tại tại revision này** (QA1 dùng đúng danh sách này ở §7 của
`docs/evidence/WWHN-310/QA1_RETEST_PACKET.md`):

PLACEHOLDER_KNOWN_FAILURES

Quy tắc chống "implementer self-claim": mỗi đỏ ở trên phải có **owner ticket** và **cách quy trách
nhiệm bằng máy** (commit/migration gây ra nó, hoặc lệnh tái lập). Đỏ nào không có thì không được
tính là known failure.

---

## DEV310-P1-04 — FEATURE_DATA hard gate: DRY_RUN → COMMIT → ROLLBACK thật

**Đã làm**: diễn tập đầy đủ trên **safe target dataset** — database PostgreSQL riêng `hn_wwhn310_fdl`
(không phải DB dev/app, không phải DB test), dựng bằng `migrate:fresh --seed --force` ngay trước khi
chạy. Script chạy lại được: `docs/evidence/WWHN-310/round2/run-final-load-rehearsal.sh`.

| # | Bước | Artifact | Kết quả |
| --- | --- | --- | --- |
| 1 | DRY_RUN (package hợp lệ, 16 dòng) | `docs/evidence/WWHN-310/round2/10-dry-run.json` | `PASSED` · total 16 = valid 16 + invalid 0 + duplicate 0 · `invariant_holds = true` |
| 2 | COMMIT | `docs/evidence/WWHN-310/round2/11-commit.json` | `COMMITTED` · `source_checksum` **trùng** dry-run (`c94756d9…`) · 5/5 phép đối soát PASS |
| 3 | Đối soát sau COMMIT | `docs/evidence/WWHN-310/round2/14-reconcile-after-commit.txt` | `migration_runs` có operator **và** approver + `APPROVAL_REF`; `migration_errors` rỗng; balance ↔ ledger không lệch dòng nào; serial-tracked: opening 2↔2 Item, 1↔1 Item; SKU `QUANTITY` 9 với 0 Item; audit `MIGRATION_DRY_RUN_COMPLETED` / `MIGRATION_COMMIT_COMPLETED` / 19 × `MIGRATION_MASTER_SNAPSHOT` |
| 4 | ROLLBACK | `docs/evidence/WWHN-310/round2/12-rollback.json` | `ROLLED_BACK` · **`residual_opening_qty = 0`** · `residual_master_rows = 0` · `missing_rows` rỗng |
| 5 | Đối soát sau ROLLBACK | `docs/evidence/WWHN-310/round2/15-reconcile-after-rollback.txt` | OPENING `+12` bị trung hoà bởi REVERSAL `−12` ⇒ tổng ròng **0**; ledger vẫn append-only (không dòng nào bị xoá); balance của package rỗng; SKU bị ledger ghim còn lại ở trạng thái `INACTIVE` đúng như `disposed_rows` khai báo |
| 6 | DRY_RUN package có dòng sai | `docs/evidence/WWHN-310/round2/13-dry-run-rejected.json` | `FAILED` · total 21 = valid 15 + invalid 5 + duplicate 1 · phủ `REQUIRED`, `INVALID_ENUM`, `DUPLICATE_CODE`, `UNKNOWN_SKU`, `UNKNOWN_FK`, `RECON_MISMATCH` |
| 7 | COMMIT sau dry-run FAILED | `docs/evidence/WWHN-310/round2/16-commit-blocked.json` | `BLOCKED` · `DRY_RUN_NOT_PASSED` · `migration_run_id = null` (không để lại run rác) |
| 8 | Dòng bị từ chối trong DB | `docs/evidence/WWHN-310/round2/17-rejected-rows.txt` | 8 dòng `migration_errors` đúng `dataset/row_no/field_name/error_code/severity`, `raw_value_masked` theo §13.8 |

Package dùng cho diễn tập nằm trong repo để QA/DevOps chạy lại đúng dữ liệu đó:
`docs/evidence/WWHN-310/round2/package/manifest.json` (hợp lệ) và
`docs/evidence/WWHN-310/round2/package-rejected/manifest.json` (mẫu bị từ chối).
Truy vấn đối soát tách riêng thành `docs/evidence/WWHN-310/round2/14-reconcile-after-commit.sql`
và `docs/evidence/WWHN-310/round2/15-reconcile-after-rollback.sql` để chạy độc lập với script.

**Ghi chú nghiệp vụ quan trọng**: rollback **không xoá** movement — ledger `stock_movements` là
append-only, nên hoàn tác được ghi bằng movement `REVERSAL` đối ứng. "Residual = 0" vì thế phải đọc
là *tổng ròng* của run bằng 0, không phải "không còn dòng nào". SKU đã bị ledger tham chiếu không bị
xoá mà chuyển `INACTIVE` (`reason = LEDGER_PINNED`) — đúng bất biến toàn vẹn tham chiếu.

---

## DEV310-P1-05 — QA hard gate: QA1 retest độc lập (WWHN-312)

**Đã làm**: `docs/evidence/WWHN-310/QA1_RETEST_PACKET.md` — gói để QA1 kết luận PASS/FAIL dứt khoát:

- Revision chính xác phải kiểm (đọc từ pin), không dùng "latest m1".
- Lệnh cho từng nhóm: constraint/schema · migration + nạp dữ liệu cuối · permission/data-scope/PII
  của báo cáo · tính đúng RPT-LABEL-01 · checksum/pin tài liệu · đối soát tồn kho.
- Bảng "phải thấy gì" cho từng artifact dry-run/commit/rollback.
- Quy tắc kết luận về known failures: đỏ ngoài danh sách ⇒ FAIL; không nhận waiver dạng tự khai.
- Mẫu kết luận phải ghi vào WWHN-312 (revision, môi trường, kết quả từng mục, PASS/FAIL).

Phần thuộc QA1, **không** phải BE2 tự làm thay: thực thi và ký kết luận.

---

## DEV310-P1-06 — Handover/Release: đưa Data handover vào Handover Index

**Đã làm**: mở sổ `docs/output/HN-HANDOVER-INDEX.md` (`HN-HANDOVER-INDEX-001`) — trước đây **không tồn tại**;
mỗi gói chỉ nằm rải rác trong ticket của mình nên không ai trả lời được "có bao nhiêu gói / gói nào
ứng với revision nào / ai đã nhận".

Sổ gồm: 6 gói bàn giao hiện có kèm Jira/role/artifact/pin nguồn/trạng thái; 4 bước nghiệm thu bắt
buộc cho mọi gói (tồn tại → khớp baseline → chạy được → người nhận xác nhận); phần chi tiết riêng
cho `BE2-HN-HO-001` để bên nhận không phải đọc lại toàn bộ; và quy tắc parent `WWHN-83` không cần
Done trước subtask nhưng **acceptance từng gói phải có trước khi parent đóng**.

Sổ cũng ghi thẳng chỗ còn thiếu thay vì tô hồng: `BE2-HN-QA-002` chưa pin exact SHA;
`BE2-HN-MC-001` là bản archived không dùng để nghiệm thu.

Phần thuộc DevOps/BAS/recipient: xác nhận vận hành được và xác nhận đã nhận đúng gói.

---

## DEV310-P2-01 — Governance: owner hiện tại vs mô tả cũ

**Đã làm**: ghi rõ trong `docs/docs_task_jira/WWHN-310.md` và pin nguồn rằng lịch sử ticket thuộc
placeholder `HN-BE-02` (mô tả cũ ghi BE2/Duy) còn **assignee hiện tại là Quang** và toàn bộ thay đổi
đi qua vai trò DEV hợp nhất. Giữ nguyên lịch sử BE2 để không mất truy vết, không viết lại quá khứ.

Phần thuộc PM/BAS: sửa Description/label trên Jira.

---

## Phụ lục — vì sao vòng 1 chỉ đạt 75/100

| Nguyên nhân | Cách vòng 2 chặn tái diễn |
| --- | --- |
| Pin nguồn mơ hồ (hai nhánh, "working tree chưa commit") | Pin JSON + supersede chứng minh bằng `merge-base`; test đòi exact SHA 40 ký tự, không nhận placeholder |
| "Đã review ERD" không chỉ vào bản nào | Bản hiệu lực `1.0+AMD<nnn>` + checksum pin + hai cổng test ở hai repo |
| Số liệu test không gắn với revision | Log raw trong repo + `QA1_RETEST_PACKET.md` bắt kết luận phải ghi revision |
| Hard gate dữ liệu chỉ là mô tả | Diễn tập thật, artifact JSON + đối soát SQL + script chạy lại được |
| Không có sổ bàn giao | `HN-HANDOVER-INDEX.md` với điều kiện nghiệm thu 4 bước |
