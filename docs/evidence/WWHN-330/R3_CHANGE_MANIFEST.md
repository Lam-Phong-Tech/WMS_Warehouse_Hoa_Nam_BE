# WWHN-330 R3 — Change manifest (neo truy vết theo tệp, không theo tiêu đề commit)

Nhánh `m1` được nhiều phiên làm việc song song, nên một commit có thể mang tiêu đề của
task khác mà vẫn chứa tệp của WWHN-330 — và ngược lại. Bảng dưới đây là **danh sách tệp
chính xác** thuộc register R3 kèm `sha256` nội dung tại đúng commit candidate, để Tech
Lead/QA1 xác minh được mà không phụ thuộc tiêu đề commit.

## Commit candidate

| Repo | Nhánh | SHA (40 ký tự) |
|---|---|---|
| `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `6916df47b5d9186b799e4a79c64c1b0556b7388d` |
| `WMS_Warehouse_Hoa_Nam_FE` | `m1` | `6c096808447ec6935a0ebe6ac3950c032084f4ab` |

**Cách xác minh một tệp:**

```bash
git -C <repo> show <SHA>:<đường-dẫn> | sha256sum
```

Giá trị in ra phải khớp cột `sha256` bên dưới.

## Backend — `6916df47b5d9186b799e4a79c64c1b0556b7388d` (17 tệp)

| Tệp | sha256 |
|---|---|
| `Modules/Warehouse/app/Exceptions/ImportConcurrencyException.php` | `094e12fc92dec0d07d394fd412659fdcabd2d0ce09ec79625f5bb5338f8a0d25` |
| `Modules/Warehouse/app/Http/Controllers/ImportController.php` | `061df2e11d4a6bf9e1666ac5130e53d591f82f7cd25d57de27c19e352cded32f` |
| `Modules/Warehouse/app/Http/Controllers/ImportReportController.php` | `8774721b5917ff65d90484f2538fa9bd4e06d70170703ac468160ab7972d43c3` |
| `Modules/Warehouse/app/Services/ImportErrorFileService.php` | `91e6f0bdd72fe7770b9cd7592e9d20c162db7c10a8ef3887001536361ebc60b7` |
| `Modules/Warehouse/app/Services/ImportService.php` | `8a883743ebfef14ad6a3ada0576e4c24886eefcb27220b9e2826dfcd3ee1351c` |
| `Modules/Warehouse/app/Support/SkuImportContract.php` | `6b0b3da8f593c57321b6928590d0759e76cf182767a4859d3ad80aa552b68286` |
| `app/Console/Commands/PurgeExpiredImportErrorFiles.php` | `fe82993ae076240eda02b058057670c7c09169232d9169042c4c920a306a377a` |
| `config/warehouse.php` | `d97af313ba073af17232a6dbb3b3029f44c5b1bc3e02dbb9df62eff83a4e4077` |
| `routes/console.php` | `238566a5eebf48bbfc3b5381df85d3f1d3e79d8f862501fe52f03f410c76ea8d` |
| `tests/Feature/Wwhn330ImportR3ClosureTest.php` | `98ae2f7c0ee0f779d7add441c63238b036f01793bac82b53660d2bef3b19c02c` |
| `tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php` | `3328328f33f7ed284f8bc32a503fc96e5855ecfc92c5b6f4966fdc464d19c2d2` |
| `docs/decision-records/DEC-IMP-001.md` | `707ec60edc9f53415f0b50f01437396d5abb96f5b073665eb69fd0bfe1071dbd` |
| `docs/evidence/WWHN-330/EVIDENCE_PACK_R3.md` | `34225b7785de02df11a4d070b55989be1e539a2c612a55faa9d084c830c93056` |
| `docs/evidence/WWHN-330/QA_RUNBOOK.md` | `23604bc62c2c93d621b447a2ed0f5fe420215f81a7710969d824dee667bd43b6` |
| `docs/evidence/WWHN-330/SIGNOFF_COMMENTS.md` | `ccf92f67c1e6cf295e36a109b4149058bccea60a82a2571f6751634f5ab8a3dd` |
| `docs/evidence/WWHN-330/BENCHMARK_RESULT.md` | `dd6ce285b5aee7a3441949fc09a3dbd1bc50c6498f09f7f193711e06ed08e763` |
| `docs/evidence/WWHN-330/BENCHMARK_RAW.json` | `b8fbacc02b2e39c0f2aa27bb571ffefef49ba93a3f96c89bbc268deb27155b1a` |

## Frontend — `6c096808447ec6935a0ebe6ac3950c032084f4ab` (4 tệp)

| Tệp | sha256 |
|---|---|
| `src/modules/master-data/services/sku-import.service.ts` | `b9e417eab3e8117708596f186c094eecd106132cd63b0c8ecaff948c4c96baa9` |
| `src/modules/master-data/services/sku-import.service.test.ts` | `8346e6565cd749ced2a60ac98ce45a28f74cd6d699affdcb42f320bcbaf24d04` |
| `src/modules/master-data/components/SkuImportModal.tsx` | `e0f7f20b1c0152efd94a6ac6eb222d2e6a55a3723508be24485fcb5b7fda2c29` |
| `src/modules/master-data/components/SkuImportModal.test.tsx` | `420fd16a60225d3537022de395f49444df6ae90471d809c860909de9ab58f063` |

## Ghi chú trung thực về trạng thái gate FE tại thời điểm chốt

`npm run build` của FE **đỏ** ở `src/modules/reverse/components/ReversalDetailModal.tsx`
(`TS6133: 'staleRecovered' is declared but its value is never read`) — một tệp **không
thuộc** WWHN-330/331, đang được một phiên làm việc khác sửa dở trên cùng nhánh `m1` tại
thời điểm chạy. Phiên này **không** đụng vào tệp đó để tránh xung đột với công việc đang
diễn ra.

Các gate còn lại đều xanh tại commit candidate: BE full suite 761 test (734 pass, 0 fail,
27 skip); FE `typecheck` pass, `lint` 0 error, `vitest run` 526/526 pass; `eslint` trên 4
tệp FE thay đổi: 0 cảnh báo. Build FE cần chạy lại sau khi phiên kia hoàn tất tệp của họ.
