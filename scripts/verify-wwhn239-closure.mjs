import fs from 'node:fs';
import path from 'node:path';

/**
 * WWHN-239 — cổng chống tái phát cho bất biến Reversal ở grain ITEM.
 *
 * Lý do tồn tại: bài học gốc của dự án (xem DEC-STK-002 §Context) là "disposition bằng
 * văn bản không để lại thứ gì cho CI kiểm tra", nên cùng một mệnh đề phải chứng minh
 * lại ở mọi vòng rà soát. Script này biến bốn điểm chốt của WWHN-239 thành thứ CI
 * kiểm được, để lần sau không ai lặng lẽ nới lại guard rồi phát hiện qua QA.
 *
 * Bốn điểm chốt:
 *   1. Guard grain ITEM tồn tại và được gọi ở bước tạo DRAFT.
 *   2. Re-check dưới lock tồn tại ở bước Post, và thuộc lớp CONCURRENCY của §8.2.
 *   3. Whitelist receipt của assertItemDependency KHÔNG chứa ADJUSTED_OUT
 *      (ADJUSTED_OUT chính là trạng thái do lần reversal trước đặt ra).
 *   4. Có lớp phòng thủ DB: partial unique index theo (original_movement_id, item_id).
 *
 * Chạy: node scripts/verify-wwhn239-closure.mjs
 * Thoát 0 = PASS; thoát 1 = FAIL kèm danh sách điểm hỏng.
 */

const root = process.cwd();

const SERVICE = 'Modules/Warehouse/app/Services/CurrentBaselineReversalService.php';
const MIGRATION = 'Modules/Warehouse/database/migrations/2026_08_18_214000_enforce_one_posted_reversal_per_item.php';
const TEST = 'tests/Feature/Wwhn239ReversalItemLevelClosureTest.php';
const SCOPE_MIDDLEWARE = 'Modules/Warehouse/app/Http/Middleware/EnforceReversalWarehouseScope.php';
const DISPOSITION = 'docs/decision-records/DISPOSITION-WWHN-239-REVERSAL-CONTROLLING-DECISION.md';
const REGISTER = 'docs/evidence/WWHN-239/DEV239_FIX_REGISTER.md';
const QA_PACK = 'docs/evidence/WWHN-239/QA1_TEST_PACK_WWHN-241.md';
const RECONCILIATION = 'docs/evidence/WWHN-239/RECONCILIATION.sql';

const failures = [];

const read = (relativePath) => {
  const absolute = path.join(root, relativePath);
  if (!fs.existsSync(absolute)) {
    failures.push(`Thiếu file bắt buộc: ${relativePath}`);
    return null;
  }
  return fs.readFileSync(absolute, 'utf8');
};

const require = (condition, message) => {
  if (!condition) failures.push(message);
};

// ---------------------------------------------------------------------------
// 1..3 — guard nghiệp vụ trong service
// ---------------------------------------------------------------------------
const service = read(SERVICE);
if (service !== null) {
  require(
    /private function assertItemNotAlreadyReversed\(/.test(service),
    `${SERVICE}: mất method assertItemNotAlreadyReversed() — guard grain ITEM của BR-REV-05/08.`,
  );
  require(
    /private function postedReversalLineForItem\(/.test(service),
    `${SERVICE}: mất method postedReversalLineForItem().`,
  );
  require(
    /\$this->assertItemNotAlreadyReversed\(/.test(service),
    `${SERVICE}: assertItemNotAlreadyReversed() không được gọi ở bước tạo DRAFT.`,
  );
  require(
    /\$this->postedReversalLineForItem\((?:[^;]*)\$reversalId\)/.test(service),
    `${SERVICE}: mất re-check dưới lock ở postDraft() (gọi postedReversalLineForItem có loại trừ reversal hiện hành).`,
  );

  // Re-check khi Post là thua race → phải thuộc lớp CONCURRENCY, không phải mã tự chế.
  const postRecheck = service.match(
    /postedReversalLineForItem\([^;]*\$reversalId\)[^;]*!== null\) \{\s*(?:\/\/[^\n]*\n\s*)*ApiError::abort\(409, ([^,]+),/,
  );
  require(
    postRecheck !== null && postRecheck[1].trim() === 'ConflictCode::CONCURRENCY',
    `${SERVICE}: re-check dưới lock phải trả ConflictCode::CONCURRENCY (§8.2 lớp concurrency: client reload rồi retry với CÙNG Idempotency-Key).`,
  );

  const receiptWhitelist = service.match(
    /\$documentType === 'receipt' && ! in_array\(\$item->item_status, \[([^\]]*)\]/,
  );
  require(
    receiptWhitelist !== null,
    `${SERVICE}: không tìm thấy whitelist item_status của nhánh receipt trong assertItemDependency().`,
  );
  if (receiptWhitelist !== null) {
    require(
      !receiptWhitelist[1].includes('ADJUSTED_OUT'),
      `${SERVICE}: whitelist receipt chứa ADJUSTED_OUT — đó chính là trạng thái do lần reversal trước đặt ra, cho phép đảo lại cùng một serial (defect D-239-01).`,
    );
  }
}

// ---------------------------------------------------------------------------
// 4 — lớp phòng thủ ở tầng DB
// ---------------------------------------------------------------------------
const migration = read(MIGRATION);
if (migration !== null) {
  require(
    /CREATE UNIQUE INDEX IF NOT EXISTS/.test(migration),
    `${MIGRATION}: không tạo unique index.`,
  );
  require(
    /\(original_movement_id, item_id\)/.test(migration),
    `${MIGRATION}: unique index phải theo cặp (original_movement_id, item_id).`,
  );
  require(
    /WHERE item_id IS NOT NULL AND posted_movement_id IS NOT NULL/.test(migration),
    `${MIGRATION}: index phải là partial theo posted_movement_id IS NOT NULL để DRAFT/CANCELLED không bị chặn (BR-REV-08).`,
  );
  require(
    /DROP INDEX IF EXISTS/.test(migration),
    `${MIGRATION}: down() phải DROP INDEX IF EXISTS — không được để migrate:rollback brick DB (bài học WWHN-300).`,
  );
}

// ---------------------------------------------------------------------------
// 5 — test pack phủ đúng ma trận DEV239-P1-04
// ---------------------------------------------------------------------------
const test = read(TEST);
if (test !== null) {
  const requiredCases = [
    'test_double_reverse_of_the_same_serial_item_is_blocked_and_creates_no_duplicate_movement',
    'test_same_item_declared_twice_in_one_reversal_request_is_rejected',
    'test_same_key_replay_returns_the_first_result_without_second_write',
    'test_same_key_with_different_payload_conflicts_without_write',
    'test_reversal_is_denied_for_anonymous_and_for_actor_without_permission',
    'test_dependency_guard_blocks_receipt_reversal_when_item_moved_downstream',
    'test_failed_line_rolls_back_the_whole_reversal_document',
    'test_ledger_reconciles_with_balance_and_leaves_no_orphan_after_reversals',
  ];
  for (const testCase of requiredCases) {
    require(test.includes(testCase), `${TEST}: thiếu ca bắt buộc ${testCase}().`);
  }
}

// ---------------------------------------------------------------------------
// 6 — hồ sơ đóng phải còn nguyên (P1-01/P1-02/P1-04)
// ---------------------------------------------------------------------------
for (const document of [SCOPE_MIDDLEWARE, DISPOSITION, REGISTER, QA_PACK, RECONCILIATION]) {
  read(document);
}

const reconciliation = fs.existsSync(path.join(root, RECONCILIATION))
  ? fs.readFileSync(path.join(root, RECONCILIATION), 'utf8')
  : '';
require(
  /ux_reversal_line_posted_item_per_movement/.test(reconciliation),
  `${RECONCILIATION}: phải có truy vấn Q7 xác nhận unique index tồn tại trên build đang kiểm thử.`,
);

// ---------------------------------------------------------------------------
if (failures.length > 0) {
  console.error('WWHN-239 closure verifier: FAIL');
  for (const failure of failures) console.error(`  - ${failure}`);
  process.exit(1);
}

console.log('WWHN-239 closure verifier: PASS');
console.log('  - guard grain ITEM ở createDraft + re-check CONCURRENCY dưới lock ở postDraft');
console.log('  - assertItemDependency không còn whitelist ADJUSTED_OUT cho nhánh receipt');
console.log('  - partial unique index (original_movement_id, item_id) ở tầng DB, down() an toàn');
console.log('  - 8 ca test của ma trận DEV239-P1-04 và hồ sơ đóng đầy đủ');
