# WWHN-310 / DEV310-P1-02 — Hồ sơ review sửa đổi Data Dictionary / ERD

Mục đích: đưa cho **Tech Lead** và **BA/BAS** đúng phạm vi phải duyệt, kèm checksum và bằng chứng
"không tự sửa baseline", để một comment sign-off là đủ — không phải đọc lại 2.8k dòng tài liệu.

- Jira: `WWHN-310` (data handover) · baseline kỹ thuật `WWHN-31` · Decision `DEC-DATA-001`
- Tài liệu: `HN-WMS-ERD-DD-001` — `docs/HoaNam_ERD_Moi_Nhat.md`
- Bản hiệu lực hiện hành: **`1.0+AMD010`** (ERD-AMD-001..010) — vòng 3, §6 (10/09/2026). Vòng 2 chốt `1.0+AMD008`.

## 1. Checksum chính xác (phải trùng nhau giữa hai repo)

| Tài liệu | Repo | sha256 | Bytes |
| --- | --- | --- | --- |
| `docs/HoaNam_ERD_Moi_Nhat.md` | BE | `219edddf2183fa41919304da9943ad2259db0525ac5e09df0767600e3837c139` | 212432 |
| `docs/HoaNam_ERD_Moi_Nhat.md` | FE | Chưa đồng bộ — FE còn bản vòng 2 (`5209358c…`, 205227 bytes); xem §6.4 | — |
| `docs/contracts/erd-document-pin.json` | BE | `11255a46189fc04244255f2f1c4a17a26a7870aca7e8b5b0a1c3d9b68a3c5fc7` | — |
| `docs/contracts/erd-document-pin.json` | FE | Chưa đồng bộ — xem §6.4 | — |

Kiểm lại bằng một lệnh (chạy ở thư mục cha của hai repo):

```bash
sha256sum HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE/docs/HoaNam_ERD_Moi_Nhat.md HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE/docs/HoaNam_ERD_Moi_Nhat.md
```

Cổng tự động thay cho việc kiểm tay: `tests/Feature/Wwhn310ErdDocumentPinTest.php` (BE) và
`src/test/wwhn-310-erd-document-pin.test.ts` (FE). Hai cổng băm bản ERD trong repo của mình rồi so
với **cùng một** chuỗi pin, nên lệch bản là đỏ ở cả hai phía.

## 2. Phạm vi thay đổi tài liệu — chính xác đến từng dòng

### 2.1. Vòng 1 (tại `127c573a7236ad1c25829eb92011a4914360b840`): +44 / −6 dòng

Xem trực tiếp:

```bash
git show 127c573a7236ad1c25829eb92011a4914360b840 -- docs/HoaNam_ERD_Moi_Nhat.md
```

Sáu dòng bị thay (đây là **toàn bộ** phần không phải thêm mới):

| # | Trước | Sau | Bản chất |
| --- | --- | --- | --- |
| 1 | mục lục entity `15 … defect_catalog` | `15 … defect_catalogs` | ERD-AMD-005 — đổi tên entity cho khớp bảng vật lý canonical |
| 2 | `### 15. defect_catalog` | `### 15. defect_catalogs` | ERD-AMD-005 — cùng lý do |
| 3 | `warranty_cases.defect_id … FK defect_catalog.id` | `… FK defect_catalogs.id` | ERD-AMD-005 — FK trỏ tới bảng thật, không trỏ vào compatibility view |
| 4 | `27. inbound_lines: UNIQUE(document,line_no)` | `UNIQUE(inbound_document_id,line_no)` | ERD-AMD-006 — viết tên cột vật lý thay cho bí danh văn xuôi |
| 5 | `38. outbound_request_lines: UNIQUE(request,line_no)` | `UNIQUE(outbound_request_id,line_no)` | ERD-AMD-006 |
| 6 | `49. component_issue_lines: UNIQUE(document,line_no)` | `UNIQUE(component_issue_document_id,line_no)` | ERD-AMD-006 |

44 dòng thêm mới = **§0.0 Amendment Register** (ghi lại cả 4 lần amend trước đó chưa truy vết được:
ERD-AMD-001..004) + ghi chú "hai repo phải giống hệt nhau".

Ba mệnh đề UNIQUE ở #4/#5/#6 **không đổi ràng buộc**: đúng cặp cột đó đã nằm sẵn ở cột "Index
khuyến nghị" của cùng entity (`UX(inbound_document_id,line_no)`, `UX(outbound_request_id,line_no)`,
`UX(component_issue_document_id,line_no)`). Trước đây chỉ máy không giải được bí danh
`document`/`request` về cột vật lý.

### 2.2. Vòng 2 (ticket này): +30 dòng, không xoá dòng nào

- Khối "**Bản hiệu lực (effective revision)**" trong §0.0: định nghĩa hậu tố `+AMD<nnn>`, quy tắc
  ba bước khi sửa ERD, và hai cổng chặn.
- Một dòng **ERD-AMD-008** trong sổ đăng ký, mô tả đúng thay đổi governance nói trên.

Kiểm: `git diff 127c573a7236ad1c25829eb92011a4914360b840 -- docs/HoaNam_ERD_Moi_Nhat.md` → `44
insertions` của vòng 1 cộng `30 insertions` của vòng 2, `0 deletions` ở vòng 2.

## 3. Bằng chứng "không tự sửa baseline"

| Nguồn baseline | Kiểm chứng | Kết quả |
| --- | --- | --- |
| `docs/HN_SRS_FRD_v1.12_FULL.md` (BE) | `git log --diff-filter=M -- docs/HN_SRS_FRD_v1.12_FULL.md` | Chỉ có **một** commit chạm file và là `new file mode` (1597 dòng thêm, 0 dòng xoá): bản BE được **thêm vào** repo, không phải sửa nội dung |
| SRS BE vs SRS FE | `sha256sum` hai bản | Trùng nhau: `02cd010cdc40036b62ec23a0d3aa645e4cce813497edf8b0e851ba6fcb512ff2` — bản BE byte-for-byte bằng bản baseline của BA ở repo FE |
| `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` | `git log -- <file>` trong repo BE | Không có commit nào của WWHN-310 chạm tới |
| ERD | §2 ở trên | Chỉ đổi tên entity + viết đúng tên cột + thêm sổ đăng ký; **không** đổi entity/field/requiredness/uniqueness/authority/stock semantics |

Kết luận cho reviewer: không có Change Request nghiệp vụ nào cần thiết; toàn bộ sửa đổi là
*documentation-to-physical alignment* + governance.

## 4. Công thức báo cáo bị ảnh hưởng (BA/BAS đọc phần này)

Chỉ **một** báo cáo đổi công thức: `RPT-LABEL-01` (Log in nhãn).

| Cột | Trước | Sau | Vì sao |
| --- | --- | --- | --- |
| `failed_count` | đếm `status = PRINT_FAILED` | đếm cả `PRINT_FAILED` và `FAILED` | Trigger chuẩn hoá status sự kiện in về bộ ERD (`FAILED`), nên trên DB tham chiếu cột này luôn bằng 0 — không bao giờ có bằng chứng in lỗi |
| `reprint_count` | `total_attempts − 1` | (số lần in **thành công**) − 1 | Một lần in lỗi rồi in lại thành công bị báo là "đã in lại 1 lần", sai định nghĩa §13.10 |
| `attempt_no`, `event_type` | gán cứng `1` / `PRINT` | suy từ lịch sử của cùng nhãn | Cột gán cứng thì không phân biệt được lần in đầu và lần in lại |

Tám báo cáo còn lại của §13.10: không đổi cột, không đổi filter, không đổi permission.

## 5. Cần duyệt gì (checklist cho comment sign-off)

| Người duyệt | Duyệt đúng những mục này |
| --- | --- |
| **Tech Lead** (`WWHN-31`) | §2.1 (6 dòng đổi), §2.2, bốn migration `2026_08_18_193000/193100/193200/193300`, FK canonical `warranty_cases.defect_id` và `reversal_lines.*_movement_id`, partial UNIQUE `inbound_scan_entries`, `timestamptz`, `version` DEFAULT 0, varchar(80)/(200), và pin checksum §1 |
| **BA / BAS** | ERD-AMD-005/006/008 không đổi business semantics; §3 (không sửa baseline); §4 (công thức RPT-LABEL-01); `DEC-DATA-001` là quyết định chính thức |

## 6. Vòng 3 (10/09/2026) — ghi bù ERD-AMD-009 / ERD-AMD-010

### 6.1. Vì sao có vòng này

Tài liệu ERD đã bị sửa hai lần ngoài quy trình §0.0. Cả hai commit đều `[skip ci]` nên cổng không
chạy lúc landing:

| Commit | Ngày | Thay đổi trong ERD | Thiếu |
| --- | --- | --- | --- |
| `368943c` | 27/08/2026 | Thêm `### 60. document_attachments` (+49 dòng) | dòng ERD-AMD, hậu tố bản hiệu lực, cập nhật pin |
| `b6d9a34` | 30/08/2026 | Bảng 16 `skus`: thêm `min_stock`, `max_stock` và CHECK `chk_skus_stock_thresholds` (+5 / −1 dòng) | như trên |

Hệ quả: `ErdCompilerSourcePinTest` (59 ≠ 60) và `Wwhn310ErdDocumentPinTest` (`table_count`, `sha256`)
đỏ từ `368943c`.

### 6.2. Phạm vi thay đổi của vòng này

- ERD §0.0: thêm hai dòng ERD-AMD-009 và ERD-AMD-010; bản hiệu lực `1.0+AMD008` → `1.0+AMD010`.
  **Không** sửa nội dung bảng 16 hay bảng 60 — chỉ ghi bù sổ đăng ký (18 dòng thêm, 2 dòng đổi là
  hậu tố bản hiệu lực).
- `docs/contracts/erd-document-pin.json`: `effective_revision`, `effective_at`, `amendments`, `sha256`,
  `bytes`, `table_count` 59 → 60, và khối `amendment_signoff` (sign-off của cả hai amendment: PENDING).
- `tests/Feature/ErdCompilerSourcePinTest.php`: khẳng định 59 → 60 (vẫn pin cứng một con số), đổi tên
  test để không còn chữ "signed" khi sign-off chưa có.

### 6.3. Register trỏ tới digest ERD — đã rebind

Theo bước (3) của quy tắc pin và tiền lệ vòng 2 (`5e6fee2` rebind WWHN-230/233/236/237 trong cùng
commit), các register mà `Wwhn310ErdDocumentPinTest::test_khong_register_nao_con_pin_checksum_erd_cu`
báo pin digest cũ được đổi `5209358c…` → `219edddf…`. Mỗi register chỉ đổi đúng một dòng digest.
Không register nào trong danh sách neo nội dung của bảng 60 hay hai cột mới của bảng 16 (đã quét
`document_attachments|min_stock|max_stock|skus` trong từng file: không có).

| Register | Ngữ nghĩa của digest trong register |
| --- | --- |
| `docs/evidence/WWHN-187/WWHN-187_MASTER_DATA_BASELINE.md` | dòng "ERD hiện hành" |
| `docs/evidence/WWHN-230/CLOSURE_EVIDENCE.json` | `baselineDigests` |
| `docs/evidence/WWHN-233/SOURCE_REGISTER.json` | nguồn CORROBORATING |
| `docs/evidence/WWHN-236/SOURCE_REGISTER.json` | `"version": "latest"`, neo §0.1 + bảng 32 |
| `docs/evidence/WWHN-237/SOURCE_REGISTER.json` | `baselines` |
| `docs/evidence/wwhn-290/WWHN-290_PINS.json` | `baseline_doc_sha256_at_pin_time` — chính register tự khai "Lineage only", danh tính xác nhận bằng marker sống |
| tài liệu này, §1 | checksum hiện hành |

Không đổi (cổng không coi là binding sống, giữ nguyên làm lịch sử của lần chạy tương ứng):
`docs/evidence/WWHN-236/logs/be-source-register.log`, `docs/evidence/WWHN-237/CONTRACT_NEGATIVE_LOCK.json`,
`docs/evidence/WWHN-267/SCOPE_AND_SOURCE_MATRIX.json`, `docs/evidence/WWHN-310/WWHN-310_ROUND2_REGISTER.md`.

### 6.4. Việc còn lại ở repo FE (ngoài phạm vi của commit BE này)

1. Copy byte-for-byte `docs/HoaNam_ERD_Moi_Nhat.md` từ BE sang FE (sha256 phải là `219edddf…c139`,
   212432 bytes).
2. Copy `docs/contracts/erd-document-pin.json` từ BE sang FE (sha256 `11255a46…5fc7`).
3. `src/test/wwhn-310-erd-document-pin.test.ts` đang khẳng định cứng `table_count` = 59: đổi thành 60
   cùng lúc (giống thay đổi ở `ErdCompilerSourcePinTest` bên BE), rồi chạy lại gate FE; register nào
   phía FE còn pin `5209358c…` thì rebind như §6.3.

### 6.5. Cần duyệt gì (sign-off — CHƯA có, không được coi là đã duyệt)

| Người duyệt | Mục | Trạng thái |
| --- | --- | --- |
| **BA / BAS** | ERD-AMD-009: bảng 60 `document_attachments` — nguồn baseline nghiệp vụ (hiện chưa có trong repo) và mệnh đề ở mục Constraint: REVERSAL bắt buộc ≥1 chứng từ ACTIVE trước Post, INBOUND bắt buộc khi thiếu `source_name`, OUTBOUND tuỳ chọn | PENDING |
| **BA / BAS** | ERD-AMD-010: định mức tồn theo SKU, toàn hệ thống; rule `min_stock >= 0`, `max_stock >= 1`, `max_stock > min_stock`; NULL = chưa đặt | PENDING |
| **Tech Lead** (`WWHN-31`) | Hình dạng vật lý của hai amendment (migration `2026_08_27_120000_create_document_attachments_table`, `2026_08_29_100000_promote_sku_min_max_stock_to_physical_columns`), pin checksum §1, việc rebind §6.3 | PENDING |
