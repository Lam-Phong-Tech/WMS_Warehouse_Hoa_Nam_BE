# WWHN-187 — Disposition nhánh và đóng băng candidate (`DEV187-P1-02R`)

## 1. Xung đột cần đóng

Register ghi: *"WWHN-187 c14280; m1-be-2 608164…; m1 abb0cea… — Jira pin `m1-be-2`
nhưng remediation nằm ở `m1`; không có PR/CI scoped"*.

## 2. Sự thật đã kiểm chứng bằng git (không suy diễn)

| Ref theo Jira | Có tồn tại trong repo? | Kết luận |
|---|---|---|
| `c14280` | **KHÔNG** — không có branch, tag, hay commit nào khớp ở **cả hai** repo BE và FE (`git branch -a --list "*14280*"` = 0, `git log --all --oneline \| grep 14280` = 0) | Ref không giải được. Phải formal supersede, không được dùng làm nguồn |
| `m1-be-2` | CÓ — `origin/m1-be-2` = `60816499066f9f493596e326dc41116ef32f5c4b` | Còn tồn tại nhưng **không** phải nhánh làm việc |
| `m1` | CÓ — nhánh làm việc hiện hành của cả BE và FE | Nhánh điều khiển |

**Quan hệ giữa `m1-be-2` và `m1`:**

```bash
git merge-base --is-ancestor origin/m1-be-2 m1   # exit 0 → YES
git rev-list --count origin/m1-be-2..m1          # 738
```

`origin/m1-be-2` là **ancestor thuần** của `m1`. Không có commit nào chỉ có ở
`m1-be-2` mà `m1` thiếu — nghĩa là **không tồn tại phân kỳ cần merge**, và không có
nguy cơ mất công việc khi bỏ pin `m1-be-2`.

## 3. Disposition

**`m1` là nhánh điều khiển duy nhất cho WWHN-187 (BE và FE).**

- `m1-be-2` (`6081649`) — **SUPERSEDED**. Toàn bộ nội dung đã nằm trong `m1` theo quan
  hệ ancestor ở trên. Giữ ref để truy vết lịch sử; không nhận thêm commit mới.
- `c14280` — **NO ARTIFACT / SUPERSEDED**. Không có ref tương ứng trong repo canonical;
  mọi tham chiếu tới nó trên Jira phải được thay bằng `m1` + exact SHA.
- Không tạo nhánh riêng cho task này. Remediation nằm trực tiếp trên `m1`, đúng quy ước
  đang áp cho các task cùng đợt.

**Vì sao không mở PR riêng.** `m1` là nhánh tích hợp đang có nhiều task chạy song song;
tách PR cho một task chỉ tạo ra một điểm phân kỳ mới đúng loại đã sinh ra chính xung đột
này. Thay vào đó, cái được đóng băng là **exact SHA** của những commit chạm artifact của
WWHN-187 (mục 4) — reviewer diff đúng khoảng đó thay vì diff cả nhánh.

## 4. Candidate đóng băng

Pin theo **commit cuối chạm artifact của WWHN-187**, không pin theo `HEAD` của `m1`.
`HEAD` di chuyển liên tục vì các task song song, nên pin `HEAD` sẽ hỏng (pin rot) ngay
trong ngày.

| Repo | Nhánh | Candidate SHA | Artifact được đóng băng |
|---|---|---|---|
| BE `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `5adc2341f9dff239f00f0169c981b864d76d7fe6` | `docs/evidence/WWHN-187/**`, `docs/decision-records/DEC-SCAN-AUDIT-001-*.md`, `docs/postman/WWHN-187 Inbound Scan Retry.postman_collection.json`, `scripts/verify-wwhn-187-closure.mjs` |
| FE `WMS_Warehouse_Hoa_Nam_FE` | `m1` | `3f2a5c2f5416be8019e0c1d8b91d1732a103f861` | `src/modules/inbound/services/inboundService.ts`, `src/modules/inbound/hooks/use-inbound.ts`, `src/modules/inbound/pages/MvpInboundScreens.tsx`, `src/modules/inbound/types/index.ts`, `src/modules/inbound/services/inboundService.test.ts` |

**Range invariant** (chống pin rot, theo cách đã dùng ở WWHN-290): điều kiện hợp lệ
không phải "`HEAD` bằng candidate" mà là **"candidate là ancestor của `HEAD` và không có
commit nào sau candidate chạm vào danh sách artifact ở trên"**. `scripts/verify-wwhn-187-closure.mjs`
kiểm đúng bất biến đó, nên gate vẫn chạy được sau khi các task khác commit tiếp lên `m1`.

## 5. Bằng chứng build/test tại candidate

Xem `WWHN-187_QA_EXECUTION_HANDOFF.md`. Tóm tắt tại thời điểm lập hồ sơ:

- BE `php vendor/bin/phpunit --filter "InboundScanConcurrencyBehaviorTest|Wwhn187InboundScanValidationTest|Wwhn188ScanIdempotencyContractTest|FoundationInboundPutawayReadinessTest"`
  → **44 tests / 44 passed / 316 assertions**.
- FE `npx vitest run --pool=threads src/modules/inbound` → **22 tests / 22 passed**
  (2 file: `inboundService.test.ts`, `InboundScanModal.test.tsx`).
- FE `npx tsc --noEmit` → exit 0.
- BE `vendor/bin/pint --test` trên file controller đã chạm → passed.
