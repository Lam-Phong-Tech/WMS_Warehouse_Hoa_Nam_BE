<?php

namespace Tests\Feature;

use App\Support\ConflictCode;
use App\Support\IdempotencyStore;
use Tests\TestCase;

/**
 * WWHN-234 (DEV234-P1-02) — hợp đồng "một tình huống, một mã lỗi".
 *
 * Lỗi gốc mà review WWHN-234 tìm ra không phải là thiếu lock hay thiếu idempotency —
 * hai thứ đó đã có. Lỗi là **cùng một lớp xung đột phát ra nhiều mã khác nhau tuỳ
 * đường code**, khiến FE không thể phân biệt "reload rồi thử lại" với "dừng hẳn":
 *
 *   - mất CAS trên `inventory_balances` → `VERSION_CONFLICT` (3 chỗ),
 *     trong khi `IdempotencyStore` gọi đúng lớp đó là `CONCURRENCY_CONFLICT`;
 *   - "phải đảo dependency theo thứ tự ngược" → `DOWNSTREAM_DEPENDENCY_EXISTS` ở nhánh
 *     quantity nhưng `REVERSAL_DEPENDENCY_EXISTS` ở nhánh item, hai nhánh nằm trong
 *     **cùng một file** `CurrentBaselineReversalService`.
 *
 * Một dòng văn trong tài liệu không chặn được việc đó tái diễn; bộ test này chặn.
 */
class Wwhn234ConflictClassContractTest extends TestCase
{
    /**
     * Những file thuộc canonical stock write path + physical subledger. Đây là phạm vi
     * mà WWHN-234 review, và cũng là phạm vi mà mã lỗi quyết định người vận hành có
     * được bấm lại hay không.
     *
     * @var list<string>
     */
    private const WRITE_PATH_SOURCES = [
        'Modules/Warehouse/app/Services/CurrentBaselineStockService.php',
        'Modules/Warehouse/app/Services/CurrentBaselineReversalService.php',
        'Modules/Warehouse/app/Services/AlignedOutboundStockService.php',
        'Modules/Warehouse/app/Services/ContainerService.php',
        'Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php',
        'Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php',
    ];

    /**
     * Mã đã bị WWHN-234 loại bỏ. Chúng không được quay lại dưới bất kỳ hình thức nào —
     * kể cả qua revert hay cherry-pick từ nhánh cũ.
     *
     * @var list<string>
     */
    private const RETIRED_CODES = [
        'VERSION_CONFLICT',
        'CONTAINER_VERSION_CONFLICT',
        'REVERSAL_CONCURRENT_CONFLICT',
        'REVERSAL_DEPENDENCY_EXISTS',
    ];

    public function test_conflict_code_class_matches_srs_8_2_exactly(): void
    {
        $this->assertSame(
            ['STATE_CONFLICT', 'IDEMPOTENCY_CONFLICT', 'CONCURRENCY_CONFLICT', 'DOWNSTREAM_DEPENDENCY_EXISTS'],
            ConflictCode::canonicalSet(),
            'Bốn lớp xung đột 409 phải khớp đúng SRS §8.2.'
        );
    }

    public function test_idempotency_store_reuses_the_single_conflict_code_source(): void
    {
        // Hai hằng của IdempotencyStore từng là bản sao chuỗi độc lập. Sau WWHN-234 chúng
        // phải trỏ về cùng một nguồn, nếu không việc sửa một chỗ sẽ âm thầm bỏ sót chỗ kia.
        $this->assertSame(ConflictCode::IDEMPOTENCY, IdempotencyStore::CONFLICT_PAYLOAD_DIFFERS);
        $this->assertSame(ConflictCode::CONCURRENCY, IdempotencyStore::CONFLICT_LEASE_BUSY);
    }

    public function test_retired_conflict_codes_never_return_to_the_write_path(): void
    {
        foreach (self::WRITE_PATH_SOURCES as $relativePath) {
            $source = $this->source($relativePath);

            foreach (self::RETIRED_CODES as $retired) {
                $this->assertStringNotContainsString(
                    "'".$retired."'",
                    $source,
                    "{$relativePath} phát lại mã đã loại bỏ {$retired}; dùng ConflictCode::CONCURRENCY hoặc ConflictCode::DOWNSTREAM_DEPENDENCY."
                );
            }
        }
    }

    public function test_concurrency_loss_uses_the_canonical_class_in_every_stock_write_path(): void
    {
        // Ba nơi ghi tồn đều có vòng CAS `where('version', ...)`; cả ba phải phát cùng
        // một lớp khi thua CAS.
        foreach ([
            'Modules/Warehouse/app/Services/CurrentBaselineStockService.php',
            'Modules/Warehouse/app/Services/CurrentBaselineReversalService.php',
            'Modules/Warehouse/app/Services/AlignedOutboundStockService.php',
        ] as $relativePath) {
            $source = $this->source($relativePath);

            $this->assertStringContainsString(
                "where('version'",
                $source,
                "{$relativePath} mất vòng CAS trên version."
            );
            $this->assertStringContainsString(
                'ConflictCode::CONCURRENCY',
                $source,
                "{$relativePath} không phát CONCURRENCY_CONFLICT khi thua CAS."
            );
        }
    }

    public function test_downstream_dependency_guard_uses_the_code_named_by_tc_rev_02(): void
    {
        $reversal = $this->source('Modules/Warehouse/app/Services/CurrentBaselineReversalService.php');
        $stock = $this->source('Modules/Warehouse/app/Services/CurrentBaselineStockService.php');

        // BR-REV-04 và TC-REV-02 chỉ đích danh 409 DOWNSTREAM_DEPENDENCY_EXISTS cho cả
        // nhánh quantity lẫn nhánh item.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($reversal, 'ConflictCode::DOWNSTREAM_DEPENDENCY'),
            'Reversal service phải dùng lớp downstream cho cả nhánh quantity và nhánh item.'
        );
        $this->assertStringContainsString('ConflictCode::DOWNSTREAM_DEPENDENCY', $stock);
    }

    /**
     * Optimistic locking của tầng document là 412 STALE_VERSION (§8.2), **không** phải
     * 409 — hai tầng khác nhau và FE phân biệt bằng đúng cặp mã này.
     */
    public function test_document_level_optimistic_lock_stays_on_412(): void
    {
        $flow = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php');

        $this->assertStringContainsString("abort(412, 'STALE_VERSION'", $flow);
        $this->assertStringContainsString("abort(428, 'PRECONDITION_REQUIRED'", $flow);
        $this->assertStringContainsString('lockForUpdate()', $flow);
    }

    private function source(string $relativePath): string
    {
        $absolute = base_path($relativePath);
        $this->assertFileExists($absolute, "Không tìm thấy source {$relativePath}.");

        return (string) file_get_contents($absolute);
    }
}
