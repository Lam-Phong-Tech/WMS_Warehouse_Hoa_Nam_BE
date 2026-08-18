# DEC-BR-290-001 — Branch decision record: `m1` là closure/canonical source của WWHN-290

- Decision ID: `DEC-BR-290-001`
- Jira: `WWHN-290` (parent `WWHN-77`, QA `WWHN-291`)
- Review item: `DEV290-P1-02R` — *"PM/Tech formalize `m1` là closure/canonical source với effective SHA/date/supersedes hoặc port immutable package sang `m1-be-2`; pin frozen candidate"*
- Decision owner (bắt buộc ký): **PM/PO** và **Tech Lead**
- Người soạn: DEV/BE2 — **DEV không tự ký quyết định này**
- Effective date: **2026-08-19**
- Effective SHA: xem `WWHN-290_PINS.json` → `frozen_candidate.sha` (một chỗ duy nhất, có test chặn lệch)
- Trạng thái: `PENDING_PM_TECHLEAD_SIGNOFF`
- Machine-readable: `docs/evidence/wwhn-290/WWHN-290_PINS.json`

## 1. Nội dung quyết định

1. Nhánh **`m1`** của repo `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` là **nguồn implementation và
   closure source duy nhất** của WWHN-290, hiệu lực **2026-08-19**.
2. Nhánh **`m1-be-2`** @ `60816499066f9f493596e326dc41116ef32f5c4b` bị **supersede bằng containment**:
   nó là **ancestor thuần** của `m1`. Không port, không merge, không mở PR sang `m1-be-2`.
3. Gói closure của WWHN-290 được **đóng băng (frozen candidate)** theo §3: một SHA cố định cộng một
   digest nội dung, để review lặp lại được dù `m1` tiếp tục chạy.
4. Repo FE là **consumer evidence only**, không phải nguồn implementation BE.

## 2. Vì sao chọn phương án "formalize `m1`" thay vì "port sang `m1-be-2`"

Reviewer cho hai lựa chọn. Lựa chọn được quyết định bằng đo đạc, không bằng ưu tiên cá nhân:

| Phép đo | Lệnh | Kết quả |
|---|---|---|
| Ancestry | `git merge-base --is-ancestor origin/m1-be-2 m1` | exit `0` → `m1-be-2` là ancestor thuần của `m1` |
| Merge base | `git merge-base m1 origin/m1-be-2` | `60816499066f9f493596e326dc41116ef32f5c4b` |
| `m1-be-2` HEAD | `git rev-parse origin/m1-be-2` | `60816499066f9f493596e326dc41116ef32f5c4b` |
| `m1` behind `m1-be-2` | `git rev-list --count m1..origin/m1-be-2` | `0` (invariant — đây mới là điều kiện quyết định) |
| `m1` ahead `m1-be-2` | `git rev-list --count origin/m1-be-2..m1` | `> 0`, verifier in ra giá trị sống tại thời điểm chạy |

Merge base **bằng đúng HEAD của `m1-be-2`** ⇒ `m1` đã chứa toàn bộ `m1-be-2`, còn `m1-be-2` không chứa gì
mà `m1` thiếu. Do đó:

- **Port sang `m1-be-2` bị loại:** thao tác merge là no-op, và đẩy gói closure sang nhánh cũ sẽ tạo **bản
  sao thứ hai, cũ hơn** của closure source — đúng rủi ro duplicate-source mà review muốn chặn.
- **Formalize `m1` được chọn:** `m1` canonical do containment; thứ thực sự còn thiếu là **bản ghi quyết
  định có chủ sở hữu** — chính là tài liệu này, cộng chữ ký PM/Tech Lead trên ticket.

Số `ahead` **cố ý không được pin** vào tài liệu: `m1` là nhánh dùng chung, con số đó tăng mỗi ngày và một
pin như vậy sẽ tự mục (pin rot). Điều kiện đúng vĩnh viễn là `behind = 0`.

## 3. Frozen candidate — gói closure được đóng băng

"Frozen candidate" ở đây có nghĩa chặt chẽ: **một commit cố định + một digest nội dung của gói closure**.

| Thành phần | Nguồn | Ý nghĩa |
|---|---|---|
| `frozen_candidate.sha` | `WWHN-290_PINS.json` | commit chứa nguyên vẹn gói closure; không đổi khi `m1` chạy tiếp |
| `frozen_candidate.package_files` | `WWHN-290_PINS.json` | danh sách file cấu thành gói |
| `frozen_candidate.package_digest` | `WWHN-290_PINS.json` | `sha256` trên manifest `sha256(file)  path` của các file trên, sắp xếp theo path |
| Lệnh kiểm | `bash scripts/verify-wwhn290-closure.sh` | tự tính lại digest **tại đúng `frozen_candidate.sha`** bằng `git cat-file` |

Vì digest được tính lại **từ chính object của commit đã đóng băng**, nó không thể mục theo thời gian:
`m1` có thêm bao nhiêu commit cũng không đổi nội dung của một commit đã tồn tại. Đây là điểm khác biệt so
với cách pin sai ở vòng 1 (pin blob tuyệt đối của `storage/api-docs/api-docs.json`, bị ticket khác
regenerate làm mục trong cùng ngày).

Reviewer kiểm frozen candidate bằng 3 lệnh:

```bash
git fetch origin m1 m1-be-2
git rev-parse "$(php -r 'echo json_decode(file_get_contents("docs/evidence/wwhn-290/WWHN-290_PINS.json"),true)["frozen_candidate"]["sha"];')"
bash scripts/verify-wwhn290-closure.sh
```

## 4. Compare, build và bằng chứng đi kèm

| Yêu cầu của review | Bằng chứng |
|---|---|
| Branch decision | tài liệu này (`DEC-BR-290-001`) |
| Exact SHA | `WWHN-290_PINS.json` → `frozen_candidate.sha`, `change_range.base/final`, `superseded_branch.head` |
| Compare | `https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/compare/57370ed29f0749235c06ab8aef2990ff6bac6a0f...f8942321f2a673137eb9e16c9fc86d013140f84a` và mục 4/4b của verifier (kiểm theo range **và** theo từng commit) |
| Build | `WWHN-290_EXACT_SHA_GATE_RUN.md` — kết quả cổng `WWHN-290 Exact SHA Closure` tại frozen candidate, kèm log thô trong `docs/evidence/wwhn-290/runs/` |

## 5. Điều kiện vô hiệu (tự huỷ, không cần ai nhớ)

Quyết định này **hết hiệu lực ngay** nếu bất kỳ điều nào xảy ra, và verifier sẽ đỏ đúng chỗ:

1. `git rev-list --count m1..origin/m1-be-2` khác `0` (tức `m1-be-2` có commit mà `m1` chưa có) — lúc đó
   supersession-by-containment không còn đúng và phải xử lý bằng port/merge thật.
2. `frozen_candidate.package_digest` tính lại tại `frozen_candidate.sha` không khớp pin.
3. Có defect UAT thật gắn vào WWHN-290 làm gói closure phải mở lại.

## 6. Ô ký (DEV để trống — chỉ chủ sở hữu quyết định mới điền)

| Vai trò | Tên | Ngày | Kết luận |
|---|---|---|---|
| PM/PO | | | `PENDING` |
| Tech Lead | | | `PENDING` |
