# WWHN-330 DEV330-P1-02R3 — Benchmark raw result

Sinh tự động bởi `tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php`.
KHÔNG sửa tay: chạy lại test để cập nhật. Số thô dạng máy đọc: `BENCHMARK_RAW.json`.

```bash
php vendor/bin/phpunit tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php
```

## Môi trường đo (ghi lại từ tiến trình thật, không phải mô tả tay)

| Thuộc tính | Giá trị |
|---|---|
| Thời điểm chạy | 2026-08-18T22:11:02+07:00 |
| PHP | 8.3.30 |
| OS | Windows |
| `memory_limit` | 512M |
| DB driver | `sqlite` |
| DB connection | `sqlite` (:memory:) |
| Hạn mức đang áp dụng | 1,000 dòng dữ liệu; upload 10,240 KB |
| Trần quét dòng vật lý | 20,040 (bội số 20×) |
| Hợp đồng | DEC-IMP-001 |

## Kết quả đo

| Case | Dòng dữ liệu | Kích thước file | Thời gian | Peak memory | HTTP |
|---|---:|---:|---:|---:|---:|
| dry_run/100 rows | 100 | 8.0 KB | 51 ms | 162.00 MB | 200 |
| dry_run/500 rows | 500 | 15.7 KB | 190 ms | 162.00 MB | 200 |
| dry_run/1000 rows | 1,000 | 25.0 KB | 322 ms | 162.00 MB | 200 |
| reject/1001 rows (limit 1000) | 1,001 | 25.1 KB | 214 ms | 164.00 MB | 422 |
| sparse dry_run/1000 rows (2x dòng vật lý) | 1,000 | 24.3 KB | 462 ms | 164.00 MB | 200 |
| commit/1000 rows | 1,000 | 25.1 KB | 3,207 ms | 166.00 MB | 200 |
| reject/20000 rows (limit 1000) | 20,000 | 379.6 KB | 1,728 ms | 206.00 MB | 422 |
| reject/oversize upload (cap 11 KB) | 300 | 11.9 KB | 30 ms | 204.00 MB | 422 |

## Ngân sách hiệu năng đã duyệt (DEC-IMP-001 §4)

| Kịch bản | Ngân sách | Ý nghĩa khi vượt |
|---|---:|---|
| Dry-run ở hạn mức | 15,000 ms | Test ĐỎ — nghi vấn hồi quy ở bước đọc/validate |
| Commit ở hạn mức | 60,000 ms | Test ĐỎ — nghi vấn hồi quy ở bước ghi DB (N+1) |
| Từ chối file vượt hạn mức | 15,000 ms | Test ĐỎ — bounded-read có thể đã hỏng |

## Cách đọc

Số đo phụ thuộc máy: cùng bộ test chạy trên CI, máy dev và máy production-like sẽ ra ba
bảng khác nhau. Vì vậy bảng này dùng để so sánh TƯƠNG ĐỐI giữa các case trong CÙNG một
lần chạy, và để phát hiện thay đổi bậc độ phức tạp — **không** phải cam kết SLA.

Lần chạy này dùng driver `sqlite`. sqlite in-memory theo `phpunit.xml`: chi phí ghi DB thấp hơn PostgreSQL thật, nên case commit ở đây là cận DƯỚI.
Peak memory là đỉnh của cả tiến trình PHP (đã reset trước mỗi phép đo), nên phần nền là
chi phí bootstrap Laravel + test, không phải chi phí của riêng import.

Bốn điều bảng này chứng minh:

1. **Trong hạn mức**: từ 100 → hạn mức, thời gian và bộ nhớ tăng ôn hoà; chi phí chủ yếu
   của commit là ghi DB chứ không phải parse file.
2. **Biên đúng chỗ**: đúng hạn mức thì chạy, hạn mức + 1 dòng thì 422
   `SKU_IMPORT_ROW_LIMIT_EXCEEDED` — ranh giới nằm ở đúng con số đã công bố.
3. **File thưa**: đủ hạn mức dòng dữ liệu nhưng gấp đôi dòng vật lý vẫn import đủ, tức
   hạn mức đếm theo dòng DỮ LIỆU và bội số quét vật lý đủ rộng cho file thật.
4. **Bounded read**: file gấp 20 lần hạn mức bị từ chối mà peak memory KHÔNG tăng tỉ lệ
   với số dòng. Nếu code còn `IOFactory::load()->toArray()` như trước khi vá thì toàn bộ
   số dòng đó phải nằm trong memory trước khi bị từ chối.

Biên kích thước file được kiểm bằng cách hạ trần qua đúng hợp đồng rồi nộp file vượt trần
mới (cùng một đường chặn, xác định, không tốn hàng trăm MB RAM để dựng file 10 MB khó nén).
Con số tuyệt đối 10 MB do QA1 xác minh trên môi trường thật — xem `QA_RUNBOOK.md`.

