# WWHN-289 — Supersession của FE consumer pin (đóng phần FE của `DEV289-P1-02R` / `DEV289-P2-01`)

- Ngày đo: 2026-08-19 (ICT)
- Repo FE: `WMS_Warehouse_Hoa_Nam_FE`, nhánh `m1`
- FE consumer pin của WWHN-289: `404267083d2c0ca79507f7fc1fdf05170cdae48e`
  — `feat(WWHN-289): FE nhận diện 429 RATE_LIMIT_EXCEEDED và 403 DATA_SCOPE_DENIED`
- FE HEAD tại thời điểm đo: `082d23327bb2a0d711e31fa7d66aa0815188740f`

## 1. Vì sao có tài liệu này

`DEV289-P1-02R` đòi "exact-build evidence chain" phải sạch. Chuỗi bằng chứng của WWHN-289
có **hai** đầu: BE (nơi sửa) và FE (nơi tiêu thụ mã lỗi mới). Verifier
`scripts/verify-wwhn289-closure.mjs` chạy trong repo BE nên chỉ kiểm được pin rot của BE;
phía FE, pin `404267…` vẫn resolve được nhưng **không còn là commit cuối chạm lớp mã lỗi**
mà WWHN-289 dựa vào. Nếu không ghi lại, reviewer đọc pin FE sẽ thấy một SHA đúng-mà-không-đủ
— đúng loại lệch mà vòng 73/100 đã bắt ở phía BE.

Đây là **supersession, không phải defect**: hành vi WWHN-289 vẫn còn nguyên tại FE HEAD, và
một trong các commit sau pin còn mở rộng nó.

## 2. Commit chạm lớp mã lỗi FE sau pin WWHN-289

| Commit | Ticket | Chạm gì | Ảnh hưởng tới hành vi WWHN-289 |
|---|---|---|---|
| `b7cf48859f5d4d14860b8b61f2ea6b5f361d03d4` | WWHN-252 | dựng module App PV (`src/modules/pv`) tiêu thụ 429 của nhóm PV công khai | Mở rộng phạm vi dùng 429; không đổi cách nhận diện |
| `89deb8c7e33092129c817e51ccb4674872d0a7b2` | WWHN-252 | If-Match theo version hồ sơ PV | Không chạm 429/403 |
| `a2b555b28e3c680a1c303873c04d7e64d71bb7d2` | WWHN-267 | `isNoPermissionError` không còn chép tay danh mục 403 | **Mở rộng**: thêm `WAREHOUSE_SCOPE_DENIED` vào cùng hàng 403 với `DATA_SCOPE_DENIED` |

## 3. Đo lại hành vi WWHN-289 tại FE HEAD (không tin lời, chạy thật)

```
npx vitest run src/shared/api/__tests__/wwhn-289-rate-limit-and-data-scope.test.ts \
               src/shared/api/__tests__/wwhn-267-forbidden-code-contract.test.ts
```

Kết quả tại `082d23327bb2a0d711e31fa7d66aa0815188740f`:

| Bộ test | Kết quả |
|---|---|
| `wwhn-289-rate-limit-and-data-scope.test.ts` | 6/6 pass |
| `wwhn-267-forbidden-code-contract.test.ts` | 7/7 pass |
| Tổng | **13/13 pass, 0 fail** |

Nghĩa là: 429 `RATE_LIMIT_EXCEEDED` (kèm `Retry-After`) và 403 `DATA_SCOPE_DENIED` vẫn được
FE nhận diện đúng hạng lỗi tại HEAD, sau cả ba commit trên.

## 4. Kết luận pin

- Pin FE của WWHN-289 giữ nguyên `404267083d2c0ca79507f7fc1fdf05170cdae48e` — đó là commit
  **tạo ra** hành vi, và là mốc đúng để trace finding → fix.
- `082d23327bb2a0d711e31fa7d66aa0815188740f` là revision FE mà hành vi đó được **đo lại và
  còn đúng**. Ai retest phía FE nên dùng revision này, không cần checkout về pin cũ.
- Không có hạng mục FE nào của WWHN-289 phải mở lại.
