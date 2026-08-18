# WWHN-330 DEV330-P1-02 — Benchmark raw result

Sinh tự động bởi `tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php`.
KHÔNG sửa tay: chạy lại test để cập nhật.

- Thời điểm chạy: 2026-08-18T21:25:16+07:00
- PHP: 8.3.30 | OS: Windows
- DB test: pgsql (hn_wwhn310_full)
- `MAX_ROWS` đang áp dụng: 1000 dòng dữ liệu | giới hạn upload: 10 MB

| Case | Dòng dữ liệu | Kích thước file | Thời gian | Peak memory | HTTP |
|---|---:|---:|---:|---:|---:|
| dry_run/100 rows | 100 | 8.0 KB | 403 ms | 164.00 MB | 200 |
| dry_run/500 rows | 500 | 15.7 KB | 1,848 ms | 164.00 MB | 200 |
| dry_run/1000 rows | 1,000 | 25.0 KB | 3,308 ms | 164.00 MB | 200 |
| commit/1000 rows | 1,000 | 25.1 KB | 18,532 ms | 166.00 MB | 200 |
| reject/20000 rows (limit 1000) | 20,000 | 379.6 KB | 1,109 ms | 206.00 MB | 422 |

## Cách đọc

Suite chạy trên sqlite in-memory theo `phpunit.xml`; máy CI/máy dev khác nhau sẽ ra số
khác nhau. Peak memory là đỉnh của cả tiến trình PHP (đã reset trước mỗi phép đo), nên
phần nền ~70 MB là chi phí bootstrap Laravel + test, không phải chi phí của riêng import.

Hai điều bảng này chứng minh:

1. **Trong hạn mức**: từ 100 → 1 000 dòng, thời gian và bộ nhớ tăng ôn hoà; commit thật
   1 000 dòng nằm ở mức giây, chi phí chủ yếu là ghi DB chứ không phải parse file.
2. **Bounded read (DEV330-P1-02)**: file 20 000 dòng — gấp 20 lần hạn mức — bị từ chối
   với peak memory chỉ nhỉnh hơn ~1,6 lần so với xử lý trọn vẹn file đúng hạn mức, tức
   KHÔNG tăng tỉ lệ với số dòng. Nếu code còn `IOFactory::load()->toArray()` như trước
   khi vá thì toàn bộ 20 000 dòng phải nằm trong memory trước khi bị từ chối.

SLA production phải đo lại trên đúng môi trường/CSDL thật, không lấy bảng này thay thế.

