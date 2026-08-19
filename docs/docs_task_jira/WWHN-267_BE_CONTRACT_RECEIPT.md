# WWHN-267 — Biên nhận phần contract/error/sample/data (BE2)

- Task: `WWHN-267` · Parent: `WWHN-70` · Nguồn QA: `HN-QA-005-ST01`
- Nhánh: `m1` (không tạo nhánh riêng) · Nhánh `m1-be-2` được supersede, xem §4
- Vai trò giao nộp: **BE2** · Vai trò nhận và xác nhận độc lập: **QA1** (`WWHN-264`), **QA2** (`WWHN-265`)
- Trạng thái: `ĐÃ GIAO PHẦN THUỘC WWHN-267 — CHỜ QA RETEST ĐỘC LẬP + 2 DISPOSITION CỦA BA`
- Candidate SHA: BE `9ea9c18` · FE `a2b555b` (repo `HoaNam_FE`). Bất biến kiểm chéo: candidate là commit trên `m1` chạm `docs/evidence/WWHN-267/**` — dùng bất biến này khi SHA bị rebase. Ranh giới SHA: `9ea9c18` là commit **phần mã + artifact**; các commit sau chỉ điền SHA/ghi chú, không đổi hành vi.

## 1. Vấn đề gốc

Register review nêu bảy mục, trong đó `DEV267-P1-01` và `DEV267-P1-03` là gốc: không
tồn tại defect input canonical cho WWHN-267, và không truy được root cause → code →
fix → bằng chứng.

Nguyên nhân sâu hơn không phải "chưa làm" mà là **phần contract mà BE2 phải giao chưa
bao giờ tồn tại dưới dạng thứ máy kiểm được**. SRS §8.2 là một bảng ví dụ trong tài
liệu; SRS §3 là một bảng actor. Không có gì trong repo canh cho hai bảng đó, nên mỗi
vòng review lại đọc bằng mắt và lại kết luận khác nhau — đúng cái vòng lặp đã làm
`WWHN-156`, `WWHN-292`, `WWHN-268` bị chấm "generic".

## 2. Đã giao gì

| Artifact | Vai trò |
|----------|---------|
| `docs/evidence/WWHN-267/DEFECT_REGISTER.json` | Register defect canonical: 6 dòng, mỗi dòng đủ TC / module / endpoint / severity / expected / actual / repro / build-SHA-env / root cause / fix / bằng chứng trước-sau / lệnh retest / routing / impact hợp đồng-dữ liệu |
| `docs/evidence/WWHN-267/HTTP_ERROR_CODE_PAIRING.json` | Hợp đồng ghép cặp HTTP ↔ mã lỗi của §8.2 + register khác biệt tên gọi + 3 mã dual-status có điều kiện |
| `docs/evidence/WWHN-267/ROLE_BASELINE_PIN.json` | Ánh xạ 11 role seed ↔ 6 actor §3, pin sha256 tập quyền, 3 delta có chủ sở hữu |
| `docs/evidence/WWHN-267/SOURCE_AND_BRANCH_DISPOSITION.json` | `DEV267-P1-02` (Stocktake) + `DEV267-P1-04` (nhánh) + `DEV267-P2-01` (metadata vai trò) |
| `docs/evidence/WWHN-267/QA_RETEST_PLAN.json` | `DEV267-P1-05`: 7 ca retest máy đọc được, giao QA1/QA2 |
| `docs/evidence/WWHN-267/RUN_EVIDENCE.md` | Số đo trước/sau, gồm cả mutation check chứng minh cổng bắt được lỗi thật |
| `tests/Feature/Wwhn267ContractClosureTest.php` | 8 ca / 120 assertion khoá 4 mệnh đề contract |
| `scripts/verify-wwhn-267-closure.mjs` | Cổng hồ sơ fail-closed |
| `.github/workflows/wwhn-267-be-contract-closure.yml` | Lượt chạy CI trên `GITHUB_SHA`, PostgreSQL 16, upload artifact |

## 3. Sáu defect đã tìm ra bằng cách chạy

| ID | Sev | Tóm tắt | Trạng thái |
|----|-----|---------|-----------|
| `BE267-D01` | P1 | Không có hợp đồng ghép cặp HTTP↔mã lỗi: 247 mã trên 12 status, §8.2 chỉ có 9 row, 3 mã dùng 2 status mà không nơi nào giải thích | FIXED |
| `BE267-D02` | P2 | Fallback middleware suy `INSUFFICIENT_STOCK` từ chuỗi tiếng Việt và phát ở **HTTP 400** — §8.2 xếp mã này ở 422 | FIXED (code) |
| `BE267-D03` | P1 | Register khác biệt tên mã chỉ phủ 403; thực tế lệch cả 401/422/500 và 3 status không có row §8.2 | FIXED hồ sơ, chờ BA |
| `BE267-D04` | P1 | Role baseline không có ánh xạ sang §3 và không được pin: `WAREHOUSE_MANAGER` 68 quyền > `ADMIN` 61 và giữ `iam.user.manage`/`iam.role.manage` mà §3 đánh D cho Quản lý kho | PIN xong, chờ BA |
| `BE267-D05` | P1 | Cổng 403 của `WWHN-268` xanh khi chỉ 1/6 bề mặt từ chối; đo thật `LEADERSHIP` chỉ bị chặn 2/6 | FIXED (ma trận 18 ô) |
| `FE267-D06` | P2 | `WarehouseConfigScreen` chỉ nhận `ACCESS_DENIED`; `isNoPermissionError` thiếu `WAREHOUSE_SCOPE_DENIED` | FIXED (repo FE) |

`P0 = 0` giữ nguyên như đánh giá của register: không có bằng chứng mất dữ liệu, bypass
xác thực, double effect hay sai tồn do WWHN-267.

## 4. Nguồn và nhánh

- **Stocktake** (`DEV267-P1-02`): neo vào **`REQ-SRS-037`**, không neo vào
  `DEC-NO-STOCKTAKE-001` — trong §1.6 Supersession Register, mã quyết định đó nằm ở
  cột "nguồn kiểm soát mới" kèm trạng thái `SUPERSEDED` của mệnh đề cũ, trích nhầm cột
  là cách nhanh nhất để reviewer kết luận ngược lại. Ca kiểm kê vật lý phải được đánh
  `SUPERSEDED_BY_REQ-SRS-037`, **không** đánh FAIL/BLOCKED.
- **Nhánh** (`DEV267-P1-04`): `m1` là canonical. Chứng minh:
  `git merge-base --is-ancestor origin/m1-be-2 m1` → `origin/m1-be-2` (`6081649`) là
  ancestor thuần của `m1`; ahead 738, **behind 0**. Supersede không mất công việc,
  không cần cherry-pick.

## 5. Điểm còn mở, không tự đóng

| Cổng | Nội dung | Vai trò | Kiểm soát bù đang giữ rủi ro |
|------|----------|---------|------------------------------|
| `W267-EXT-01` | Disposition danh mục tên mã §8.2 (401/403/422/500) | BA + Tech Lead | FE nhận diện 401/403 theo HTTP status; danh mục mã ở FE gom về một nguồn |
| `W267-EXT-02` | Disposition role baseline vs §3 (`DELTA-01/02/03`) | BA + Tech Lead | Pin fail-closed chặn nới quyền im lặng; `PreventSelfRoleAssignment` chặn tự leo quyền; PII bảo hành sau permission riêng |
| `W267-EXT-03` | Regression độc lập trên build đã deploy | QA1 / QA2 | `QA_RETEST_PLAN.json` giao phạm vi tối thiểu |
| `W267-EXT-04` | PR URL + exact SHA + build ID + CI log của lượt chốt | DEV + DevOps | Workflow chạy trên `GITHUB_SHA` và upload artifact |
| `W267-EXT-05` | `tests/Feature/Wwhn310Round2ClosureTest.php` khai `json()` protected gây fatal khi nạp cả suite | chủ ticket `WWHN-310` | WWHN-267 chạy theo đường dẫn file cụ thể; không sửa file của ticket khác |

## 6. Kết quả chạy tại chỗ

```
php artisan test tests/Feature/Wwhn267ContractClosureTest.php
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":120}

node scripts/verify-wwhn-267-closure.mjs
WWHN-267 closure verifier: PASS

npx vitest run src/shared/api/__tests__/wwhn-267-forbidden-code-contract.test.ts   # repo FE
Test Files 1 passed · Tests 7 passed
```

Lưu ý khi chạy lại tại chỗ: nếu cây làm việc đang mang thay đổi seeder chưa commit
của phiên khác (xem `W267-EXT-06`), ca `test_role_baseline_matches_the_pin` sẽ đỏ —
đó chính là hành vi mong muốn của pin, không phải hồi quy. Trên trạng thái đã commit,
bộ test xanh 8/8.

Ranh giới sự thật: đây là lượt chạy in-process trên DB test tại máy dev. Bằng chứng
trích dẫn ra ngoài phải lấy từ lượt chạy CI trên `GITHUB_SHA` của `m1`.
