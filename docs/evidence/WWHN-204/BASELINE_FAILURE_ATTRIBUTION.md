# WWHN-204 — Quy trách nhiệm 18 lỗi đỏ của full suite (không mục nào thuộc WWHN-204)

Chạy full suite trên sqlite (driver mặc định của `phpunit.xml` và của CI
`current-baseline-doc-contract.yml`) tại thời điểm đóng ticket:

```
tests=1053  passed=1006  failed=17  errors=1  skipped=29
```

Log thô: [`logs/sqlite-full-suite.log`](logs/sqlite-full-suite.log).

> Suite phải chạy với một config tạm loại trừ `tests/Feature/Wwhn310Round2ClosureTest.php`.
> File đó **chưa được commit** (untracked, thuộc phiên WWHN-310 đang làm song song) và chứa
> **fatal error** `Access level to Wwhn310Round2ClosureTest::json() must be public`, giết cả
> suite ngay ở bước nạp class trước khi bất kỳ test nào chạy. Không sửa file đang dở của phiên
> khác; đã báo lại để chủ file xử lý.

---

## 1. Bằng chứng cứng: thay đổi của WWHN-204 không thể làm đỏ test nào khác

WWHN-204 đưa vào 3 thứ có thể ảnh hưởng test khác:

| Thay đổi | Có thể ảnh hưởng test khác không? |
|---|---|
| `InventoryProjectionRebuildService` (class mới) | Không — chỉ được gọi từ `inventory:reconcile --rebuild` |
| Cờ `--rebuild` / `--reason` trên lệnh sẵn có | Không — opt-in; đường chạy cũ không đổi (đã xanh: `Wwhn219InventoryReconcileCommandTest`) |
| Trigger append-only cho **sqlite** (`2026_08_19_050100`) | **Chỉ** chặn `UPDATE`/`DELETE` trên `stock_movements` |

Quét toàn bộ `tests/` tìm mọi lệnh `UPDATE`/`DELETE`/`TRUNCATE` lên `stock_movements`:

```
tests/Feature/Wwhn204LedgerProjectionRebuildTest.php:161  ->update(['qty_delta' => 999])   # test của chính WWHN-204
tests/Feature/Wwhn204LedgerProjectionRebuildTest.php:172  ->delete()                        # test của chính WWHN-204
```

**Không còn file nào khác.** File test duy nhất trong toàn repo mutate ledger chính là test của
WWHN-204, và nó *kỳ vọng* bị từ chối. Do đó trigger mới không thể là nguyên nhân của bất kỳ lỗi
đỏ nào bên dưới.

## 2. Quy trách nhiệm từng lỗi

| # | Test | Nguyên nhân | Thuộc về |
|---|---|---|---|
| 1 | `DocumentationBaselineContractTest::test_generated_swagger_has_route_parity_and_baseline_error_responses` | `get /api/v1/health missing HTTP 400` — `HealthController.php` + `Swagger/Processors/RouteSyncProcessor.php` đang sửa dở, `app/Swagger/HealthSchemas.php` untracked | **WWHN-310** (phiên song song) |
| 2–4 | `Wwhn195OutboundContractAlignmentTest` (3 case) | File test **untracked**, chưa từng commit | WWHN-195 (phiên song song) |
| 5–6 | `Wwhn198PackingLabelContractAlignmentTest` (2 case) | File test **untracked** | WWHN-198 (phiên song song) |
| 7 | `Wwhn205InvariantClosureContractTest::test_register_pins_three_commit_roles_and_all_four_p1_items` | Test **untracked**; báo `docs/evidence/WWHN-205/raw/B2-candidate-plus-repair-pg16-target-suite.log` chưa tồn tại | WWHN-205 (phiên song song) |
| 8–9 | `Wwhn266ProbeTest` (2 case) | File probe **untracked**; chỉ `COUNT`/`pluck` trên `stock_movements`, không mutate ⇒ không dính trigger mới | WWHN-266 (phiên song song) |
| 10–16 | `Wwhn300BackendGoLiveCheckTest` (7 case) | Tất cả cùng một gốc: `wms:golive-check` FAIL ở `migrations.reversible` — `irreversible_count = 76` vs `dispositioned_count = 75`, đúng **1** migration chưa có disposition: `2026_08_19_000050_repair_erd_mirror_projection_casts` với `down()` rỗng (*"down() is an empty fail-forward no-op"*) | **WWHN-310/299** (phiên song song) |
| 17 | `Wwhn309PermissionCatalogEvidenceTest::test_published_catalog_document_is_not_stale` | Hash catalog lệch vì `Modules/Warehouse/routes/api.php` đang bị sửa trong working tree | WWHN-309/310 (phiên song song) |
| 18 (error) | `Wwhn198PackingLabelContractAlignmentTest::test_partial_scan_creates_no_label_and_full_match_creates_exactly_one_draft_label` | `Undefined array key "OutboundScanResult"` — file **untracked** | WWHN-198 (phiên song song) |

**Tổng: 18/18 thuộc ticket khác. 0/18 thuộc WWHN-204.**

### Vì sao mục 10–16 chắc chắn không phải của WWHN-204

Cổng `migrations.reversible` chỉ đếm migration có `down()` **thiếu hoặc rỗng**. So sánh trực tiếp:

```php
// 2026_08_19_050100 (WWHN-204) — down() THẬT, gỡ đúng thứ up() tạo ra
public function down(): void
{
    if (DB::getDriverName() !== 'sqlite') { return; }
    foreach (array_keys(self::TRIGGERS) as $name) {
        DB::statement("DROP TRIGGER IF EXISTS {$name}");
    }
}

// 2026_08_19_000050 (phiên khác) — down() RỖNG, chỉ có comment
public function down(): void
{
    // Fail-forward có chủ ý: ...
}
```

Migration của WWHN-204 **reversible**, nên không vào tập `irreversible` và không cần mục
allowlist nào. Nếu gỡ migration của WWHN-204 ra thì `migrations.reversible` vẫn FAIL y nguyên.

## 3. Số liệu thuộc phạm vi WWHN-204 (đều xanh)

| Bộ | Driver | Kết quả |
|---|---|---|
| 7 class ledger/balance/reconcile/rebuild | sqlite | **35 test / 733 assertion / 0 fail** |
| 4 class ledger/balance/rebuild | PostgreSQL 16.14 | **24 test / 637 assertion / 0 fail** |
| Bằng chứng DB thô 16 bước | PostgreSQL 16.14 | Toàn bộ mốc M1..M6 PASS |
| `node scripts/verify-wwhn-204-closure.mjs` | — | PASS |

## 4. Việc cần chuyển cho chủ sở hữu (không thuộc WWHN-204)

1. **Chặn cả suite:** `tests/Feature/Wwhn310Round2ClosureTest.php` — đổi `json()` thành `public`
   (hoặc đổi tên method) để full suite chạy lại được. Đây là mức nghiêm trọng nhất trong danh
   sách: mọi ticket đang mất khả năng chạy hồi quy toàn bộ.
2. **Chặn GoLive gate:** `2026_08_19_000050_repair_erd_mirror_projection_casts` — hoặc viết
   `down()` thật, hoặc thêm mục vào `database/golive/fail_forward_allowlist.php` kèm lý do.
3. Publish lại permission catalog sau khi `Modules/Warehouse/routes/api.php` chốt
   (`evidence:permission-catalog`).
