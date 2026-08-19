# WWHN-224 — DEV224-P1-06: chốt nguồn DEV thống nhất (`m1` vs `m1-be-2`)

> Yêu cầu register: *"Formalize unified DEV canonical source: m1 hay m1-be-2; supersede pin
> cũ và ghi exact target/release SHA."*
> Chủ sở hữu quyết định: **PM/Tech Lead**. Phần dưới là bằng chứng DEV dựng để quyết định
> không phải dựa vào trí nhớ.

## 1. Bằng chứng ancestry — không phải hai nhánh song song

```
$ git rev-parse origin/m1-be-2
6081649 9066f9f493596e326dc41116ef32f5c4b

$ git merge-base --is-ancestor origin/m1-be-2 m1 && echo "ancestor"
ancestor

$ git rev-list --count origin/m1-be-2..m1      # m1 đi trước bao nhiêu commit
732

$ git rev-list --count m1..origin/m1-be-2      # m1-be-2 có gì mà m1 không có
0
```

Đọc thẳng: `m1-be-2` là **tổ tiên thuần** của `m1`. Không có một commit nào tồn tại ở
`m1-be-2` mà thiếu ở `m1`. Vì vậy câu hỏi "m1 hay m1-be-2" không phải là chọn giữa hai
nhánh cạnh tranh — chọn `m1-be-2` là **tự nguyện lùi lại 732 commit** và mất toàn bộ phần
đóng P0/P1 của các task sau đó (WWHN-230…WWHN-331).

Bất biến này ổn định (kiểm lại được bất kỳ lúc nào bằng 2 lệnh trên), nên khuyến nghị DEV:

> **Nguồn canonical duy nhất của DEV = nhánh `m1`.** `m1-be-2` giữ nguyên như một mốc lịch
> sử đã được `m1` bao trùm, không nhận commit mới, không dùng làm nguồn đối chiếu contract.

## 2. Pin cũ bị supersede

| Pin cũ | Xuất hiện ở | Vì sao supersede |
|---|---|---|
| SHA review lịch sử `a3fd779bc95ac029e376186c2205549b542444567` | mô tả/nhận xét WWHN-224 các vòng trước | **Không tồn tại.** Kiểm tại chính lần đóng này: `git cat-file -t` → `fatal: Not a valid object name`; `gh api repos/.../commits/a3fd779…` → `422 No commit found for SHA`. Một SHA không tồn tại thì không thể là bằng chứng closure ở bất kỳ vòng nào. |
| "fix nằm ở PR #5/#6" | vòng review 16–18/08 | Cả hai PR **CLOSED, unmerged, `reviews: []`** — xem `PR_DISPOSITION.md` |

## 3. Exact SHA của lần đóng này

| Vai trò | Repo | SHA | Nội dung |
|---|---|---|---|
| Code closure BE | `WMS_Warehouse_Hoa_Nam_BE` | **`937a5c0`** | Import báo cáo nguyên tử + 503 + hợp đồng quyền + register mapping + test |
| Code closure FE | `WMS_Warehouse_Hoa_Nam_FE` | **`90e764e`** | Tách `SkuImportUnavailableError`, UI không hiểu nhầm 503 thành lỗi dữ liệu |
| Hồ sơ đóng BE | `WMS_Warehouse_Hoa_Nam_BE` | ghi ở `ARTIFACT_PIN.md` | Register/QA2 runbook/PR disposition/workflow/verifier |

Ghi chú truy vết trung thực: nội dung `storage/api-docs/api-docs.json|yaml` của lần đóng
này **không nằm trong `937a5c0`**. Nhánh `m1` đang được nhiều phiên làm việc song song
dùng chung một working tree; một phiên khác đã `git add` toàn cây và mang hai tệp artifact
đó vào commit `bd5b5d6` (mang tiêu đề task khác) trước khi commit của tôi chạy. Nội dung
vẫn đúng và kiểm chứng lại được bằng `scripts/sync-wwhn224-import-openapi.php --check` +
hash trong `ARTIFACT_PIN.md`; chỉ là quyền tác giả commit không phản ánh đúng task. Ghi ra
đây để người review không kết luận nhầm rằng artifact chưa được đồng bộ.

## 4. Việc cần PM/Tech Lead làm để đóng mục này

1. Ghi disposition nguồn (`m1` là canonical, `m1-be-2` là lịch sử) vào Jira ở nơi các task
   DEV cùng đọc được — không để mỗi task tự pin một nhánh.
2. Cập nhật release manifest với cặp SHA BE `937a5c0` / FE `90e764e` cho hạng mục WWHN-224.
3. Tuyên bố supersede SHA `a3fd779…` và hai PR #5/#6 để chúng không quay lại ở vòng review sau.
