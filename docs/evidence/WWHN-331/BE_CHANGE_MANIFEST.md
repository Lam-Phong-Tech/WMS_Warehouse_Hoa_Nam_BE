# WWHN-331 — Manifest thay đổi phía BE (neo truy vết + digest)

Ngày 18/08/2026. Repo `WMS_Warehouse_Hoa_Nam_BE`, nhánh **`m1`** (không có nhánh riêng).

## Commit mang nội dung

| Hạng mục | Giá trị |
|---|---|
| Commit chứa toàn bộ thay đổi WWHN-331 phía BE | **`93b1e71f0e01c8a7c77670e0c8f0af38d2e68470`** |

**Ghi chú trung thực về commit này:** nhánh `m1` được nhiều luồng công việc dùng chung và
commit `93b1e71` mang tiêu đề của một task khác (`docs(WWHN-273)`); nó gộp cả thay đổi
WWHN-331 vào cùng lượt commit. Nội dung WWHN-331 nằm đủ và đúng trong commit đó — bảng
digest bên dưới cho phép kiểm chứng từng tệp mà không cần tin vào tiêu đề commit:

```bash
git -C <repo> show 93b1e71:<đường-dẫn> | sha256sum
```

## Digest từng tệp (sha256, tại `93b1e71`)

| Tệp | sha256 |
|---|---|
| `Modules/Warehouse/app/Http/Controllers/ImportController.php` | `3e5ec1ac9ac467122b69059a992bf596c188cde86cd579e1a3a39fe41de13ec9` |
| `Modules/Warehouse/app/Http/Controllers/ImportReportController.php` | `0f0927d6c7220346f2666e922fa502c916ef96826b0594ab0d501951b6c2679c` |
| `Modules/Warehouse/app/Models/ImportReport.php` | `bec34cf85d1108380fa5c73aa8c0ed2ecfcc656033ac975e40167a516394a808` |
| `Modules/Warehouse/app/Services/ImportErrorFileService.php` | `e9ad9d7e54afc2b57593f44713642c1519bd153574a0714688c3a5880b74398e` |
| `Modules/Warehouse/app/Services/ImportService.php` | `97052a3b5ccae01ca4a8e9d15cc1e0d32c7960c2e87fce8f1d32cac16764f41d` |
| `Modules/Warehouse/app/Support/SkuImportContract.php` | `8d265ebaec1fb755475d0911d48e6f92d16ae2e99dd61fea9438ff303ef82815` |
| `Modules/Warehouse/database/migrations/2026_08_18_190000_add_options_fingerprint_to_import_reports.php` | `5e07df8a22d94948b6b589316e972bd9b8bf5da9867f0cca93d2001cd5e28ff6` |
| `config/warehouse.php` | `dcc110b88b5a41091ed4d308adc019b56f660eae321e102b4f23ad7ce0573156` |
| `docs/decision-records/DEC-IMP-001.md` | `9c7c361023de150ef227db6e73d72eb1c44902b373a73d3ea2ddd38ba6741799` |
| `tests/Feature/Wwhn331ImportContractClosureTest.php` | `f76bd7e5326217421d98ce3d5269ea91022ab8927d534d3ab1cc6ffbfb2d351f` |
| `tests/Feature/Wwhn330ImportResidualHardeningTest.php` | `819b83f3055622675cfef60d969d41dbffa76d08235775d2e3bff2d5581420b9` |

## Vai trò từng tệp

| Tệp | Đóng mục nào |
|---|---|
| `config/warehouse.php` (`warehouse.sku_import`) | FE331-P1-03R3 — nguồn sự thật duy nhất của hạn mức |
| `SkuImportContract.php` | FE331-P1-02R3/03R3/06R3 — vân tay tùy chọn, khối `constraints`, giải data-scope |
| `ImportService.php` | FE331-P1-02R3/03R3 — định danh replay + khoá chống đua theo đủ định danh; hạn mức đọc từ config |
| `ImportErrorFileService.php` | FE331-P1-03R3 — hạn dùng file lỗi đọc từ hợp đồng |
| `ImportController.php` | FE331-P1-02R3/03R3 — rule upload theo hợp đồng, `constraints` trong mọi response, lưu `options_fingerprint` |
| `ImportReportController.php` | FE331-P1-06R3 — data-scope cho cả 3 route; `meta.constraints` + `meta.data_scope` |
| `ImportReport.php` | FE331-P1-02R3 — `options_fingerprint` fillable |
| migration `2026_08_18_190000_*` | FE331-P1-02R3 — cột `options_fingerprint` + index tra cứu replay |
| `DEC-IMP-001.md` | Quyết định chính thức cho cả ba mục |
| `Wwhn331ImportContractClosureTest.php` | Test khoá cả ba mục (8 test / 60 assertion) |
| `Wwhn330ImportResidualHardeningTest.php` | Cập nhật khoá chống đua theo định danh mới |

## Kết quả kiểm thử tại commit này

| Lệnh | Kết quả |
|---|---|
| `php vendor/bin/phpunit tests/Feature/Wwhn331ImportContractClosureTest.php` | PASS — 8 test / 60 assertion |
| `php vendor/bin/phpunit` (full suite, sqlite theo `phpunit.xml`) | PASS — 724 test / 702 pass / **22 skip** / **0 fail**, 8 413 assertion |
| `php vendor/bin/pint --test` (các tệp trong bảng trên) | PASS |

22 skip là các gate chỉ chạy trên PostgreSQL (physical contract, ERD parity…), không phải
test bị bỏ qua vì hỏng.

Evidence pack đầy đủ nằm ở repo FE: `docs/evidence/WWHN-331/EVIDENCE_PACK.md`.
