<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Support\SeedEnvironment;
use Modules\Warehouse\Http\Controllers\CurrentBaselineComponentFlowController;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\ComponentContainerSubledgerService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Support\WarrantyCasePii;
use RuntimeException;
use Throwable;

/**
 * Dữ liệu mẫu KHỐI LƯỢNG cho "Báo cáo · Bảo hành & linh kiện" (RPT-WAR-01,
 * AlignedWarrantyReportController) — yêu cầu người dùng: kỳ 2026-06-01..2026-08-31
 * phải "sinh động" với ≥ 50 hồ sơ trải đều và ĐỦ 6 trạng thái vòng đời.
 *
 * Vì report lọc theo COALESCE(received_at, created_at) trong khoảng ngày, seeder
 * đặt received_at rải đều 01/06..31/08; các mốc chuyển trạng thái (completed_at/
 * returned_at) nằm sau received_at vài ngày nhưng luôn ≤ hôm nay.
 *
 * Bám nghiệp vụ (giống OperationalReportRangeSeeder):
 *  - Warranty case stock-neutral: item ISSUED -> IN_WARRANTY (đang xử lý) hoặc
 *    RETURNED_TO_CUSTOMER (đã trả); CANCELLED để nguyên ISSUED; không sinh movement.
 *  - Chỉ mượn item đang ISSUED và CHƯA gắn case active (tránh vỡ unique
 *    ux_erd_warranty_one_active_case_item); mỗi case IDENTIFIED một item riêng.
 *  - Component issue (purpose=WARRANTY) chỉ gắn case đang active; khi Post mới trừ
 *    tồn qua CurrentBaselineStockService => ledger = balance = subledger.
 *  - Event ERD §46: RECEIVE khi tạo + 1 event/transition; version = 1 + #transition.
 *
 * Idempotent theo warranty_case_code 'WC-RPTW-XXXX' và doc_no/issue_no riêng.
 * Chỉ chạy pgsql (dựa CurrentBaselineStockService + dữ liệu ISSUED thật). Guard ở
 * DatabaseSeeder đã bọc pgsql; ở đây guard thêm một lớp cho chắc.
 */
class WarrantyReportRangeSeeder extends Seeder
{
    private const WAREHOUSE_CODE = 'KHO-TONG-HN';

    private const START_DATE = '2026-06-01';

    private const END_DATE = '2026-09-30';

    private const TARGET_CASES = 160;

    private const COMPONENT_SKU = DongChengAnchorSkus::COMPONENT_WARRANTY;

    private const BOX_CODE = 'BOX-RPTW-001';

    /**
     * Bệnh/lỗi gán cho hồ sơ: LẤY ĐỘNG toàn bộ mã đang ACTIVE (xem
     * MasterCatalogCoverageSeeder) thay vì danh sách cứng 5 mã. Bộ lọc "Bệnh/lỗi"
     * ở màn bảo hành chỉ liệt kê mã ACTIVE, nên mọi mã trong đó đều phải có hồ sơ,
     * nếu không sẽ có giá trị lọc ra rỗng.
     *
     * @var list<string>
     */
    private array $defectCycle = [];

    private const CUSTOMER_NAMES = [
        'Nguyễn Văn Hùng', 'Trần Thị Mai', 'Phạm Quốc Bảo', 'Lê Thành Long', 'Võ Thị Cúc',
        'Đặng Minh Trí', 'Hoàng Văn Nam', 'Bùi Thị Lan', 'Đỗ Hữu Phước', 'Ngô Thị Hường',
        'Dương Văn Khoa', 'Phan Thị Kim', 'Vũ Đình Sơn', 'Lý Thị Thu', 'Trịnh Văn Đạt',
    ];

    private const CUSTOMER_ADDRESSES = [
        'Thôn 3, xã Ea Kar, Đắk Lắk',
        '25 Lê Duẩn, TP. Buôn Ma Thuột, Đắk Lắk',
        'Xã Cư Jút, Đắk Nông',
        'Huyện Krông Pắc, Đắk Lắk',
        'Thị trấn Phước An, Đắk Lắk',
        '12 Nguyễn Tất Thành, TP. Gia Nghĩa, Đắk Nông',
        'Xã Ea Tu, TP. Buôn Ma Thuột, Đắk Lắk',
        'Huyện Cư M’gar, Đắk Lắk',
    ];

    private const REPORTED_DEFECTS = [
        'Máy không khởi động được, đề không quay dù bình điện mới sạc.',
        'Động cơ nổ không đều rồi chết máy, kéo giật không nổ lại.',
        'Nứt vỏ hộp số sau va chạm, rò rỉ nhớt.',
        'Bơm kêu to bất thường khi chạy tải lớn.',
        'Máy chạy yếu, nhả khói đen, hao nhiên liệu bất thường.',
        'Màn hình điều khiển báo lỗi liên tục, không vào được chế độ chạy.',
    ];

    private const MANUAL_DESCRIPTIONS = [
        'Máy bơm nước cũ vỏ inox màu xanh, tay cầm quấn băng keo đen, không còn tem.',
        'Bộ điều khiển máy gặt cũ, vỏ nhựa xám, màn LCD 4 dòng, mất mã.',
        'Động cơ diesel nhỏ sơn đỏ đã bạc màu, thiếu nhãn năng lượng.',
        'Máy phát điện mini vỏ cam, quai xách gãy một bên, không đọc được serial.',
    ];

    private CurrentBaselineStockService $stock;

    private string $actorId;

    private string $warehouseId;

    public function run(): void
    {
        if ((bool) config('deployment_safety.protect_database', true)) {
            throw new RuntimeException('WARRANTY_REPORT_PROTECTED_DATABASE');
        }
        SeedEnvironment::assertFixtureAccountsAllowed(self::class);
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $warehouse = Warehouse::query()->where('code', self::WAREHOUSE_CODE)->first()
            ?? Warehouse::query()->orderBy('created_at')->first();
        $actorId = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
            ?? DB::table('app_user')->where('username', 'admin')->value('user_id')
            ?? DB::table('app_user')->orderBy('created_at')->value('user_id');
        if ($warehouse === null || $actorId === null) {
            throw new RuntimeException('WARRANTY_REPORT_PREREQUISITES_MISSING');
        }

        $this->stock = app(CurrentBaselineStockService::class);
        $this->actorId = (string) $actorId;
        $this->warehouseId = (string) $warehouse->id;

        if (DB::table('warranty_case')->where('warranty_case_code', 'like', 'WC-RPTW-%')->exists()) {
            if (! $this->completedFixture()) {
                throw new RuntimeException('WARRANTY_REPORT_EXISTING_FIXTURE_REQUIRES_REVIEW');
            }
            $this->command?->info('WarrantyReportRangeSeeder: complete cases/component receipts verified; replay is read-only.');

            return;
        }
        if (DB::table('containers')->where('container_code', self::BOX_CODE)->exists()
            || DB::table('stock_documents')->where('doc_no', 'PN-RPTW-BOX-01')->exists()
            || DB::table('component_issue_documents')->where('component_issue_no', 'like', 'CID-RPTW-%')->exists()) {
            throw new RuntimeException('WARRANTY_REPORT_EXISTING_RESOURCES_REQUIRES_REVIEW');
        }

        try {
            DB::transaction(fn () => $this->createFixture());
            $this->command?->info('WarrantyReportRangeSeeder: report cases/component receipts verified; not full-chain acceptance.');
        } catch (Throwable $error) {
            // Console output must not include SQL bindings or encrypted customer data.
            $code = 'UNCLASSIFIED_FAILURE';
            if ($error instanceof HttpResponseException) {
                $body = json_decode($error->getResponse()->getContent(), true);
                $candidate = $body['error_code'] ?? $body['error']['code'] ?? '';
                if (is_string($candidate) && preg_match('/^[A-Z][A-Z0-9_]{0,100}$/D', $candidate)) {
                    $code = $candidate;
                }
            } elseif ($error instanceof QueryException) {
                $state = $error->errorInfo[0] ?? '';
                if (is_string($state) && preg_match('/^[A-Z0-9]{5}$/D', $state)) {
                    $code = 'SQLSTATE_'.$state;
                }
            } elseif ($error instanceof RuntimeException && preg_match('/^WARRANTY_REPORT_[A-Z0-9_]{1,100}$/D', $error->getMessage())) {
                $code = $error->getMessage();
            }
            throw new RuntimeException('WARRANTY_REPORT_FIXTURE_FAILED: '.$code);
        }
    }

    private function createFixture(): void
    {
        $componentSku = $this->ensureComponentSku();
        $openingBalance = $this->availableBalance($componentSku);
        $defects = DB::table('defect_catalogs')->pluck('defect_id', 'defect_code');
        $this->defectCycle = DB::table('defect_catalogs')
            ->where('status', 'ACTIVE')
            ->orderBy('defect_code')
            ->pluck('defect_code')
            ->map(fn ($code) => (string) $code)
            ->values()
            ->all();

        // Pool item đang ISSUED, chưa gắn case active — nguồn cho case IDENTIFIED.
        $itemPool = DB::table('item_instances as ii')
            ->where('ii.item_status', 'ISSUED')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('warranty_case as wc')
                    ->whereColumn('wc.item_id', 'ii.item_id')
                    ->whereIn('wc.status', ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED']);
            })
            ->orderBy('ii.item_id')
            ->limit(self::TARGET_CASES + 10)
            ->get(['ii.item_id', 'ii.sku_id'])
            ->values();
        $poolCursor = 0;

        $start = Carbon::parse(self::START_DATE);
        $spanDays = $start->diffInDays(Carbon::parse(self::END_DATE)); // 91

        /** @var list<array{case_id:string,status:string}> $activeCasesForComponents */
        $activeCasesForComponents = [];

        for ($i = 0; $i < self::TARGET_CASES; $i++) {
            $caseCode = 'WC-RPTW-'.sprintf('%04d', $i + 1);
            if (DB::table('warranty_case')->where('warranty_case_code', $caseCode)->exists()) {
                continue;
            }

            $offset = (int) round($i * $spanDays / (self::TARGET_CASES - 1));
            $receivedAt = $start->copy()->addDays($offset)->setTime(8 + ($i % 9), ($i % 6) * 10, 0);

            $status = $this->statusForIndex($i);
            $tempOnly = ($i % 6) === 5;

            // Mượn item cho case IDENTIFIED (trừ CANCELLED vẫn có thể IDENTIFIED
            // nhưng không flip trạng thái item).
            $item = null;
            if (! $tempOnly && $poolCursor < $itemPool->count()) {
                $item = $itemPool[$poolCursor++];
            }
            $isIdentified = $item !== null;

            // Vòng qua ĐỦ mọi mã lỗi ACTIVE; chỉ ~1/17 hồ sơ để trống (khách báo
            // lỗi chưa phân loại được) để cột "Bệnh/lỗi" vẫn có cả trường hợp trống.
            $defectCode = $this->defectCycle === []
                ? null
                : $this->defectCycle[$i % count($this->defectCycle)];
            $defectId = ($i % 17 === 0 || $defectCode === null) ? null : ($defects[$defectCode] ?? null);

            $caseId = $this->makeCase([
                'code' => $caseCode,
                'status' => $status,
                'item_id' => $isIdentified ? (string) $item->item_id : null,
                'sku_id' => $isIdentified ? (string) $item->sku_id : null,
                'defect_id' => $defectId,
                'received_at' => $receivedAt,
                'name' => self::CUSTOMER_NAMES[$i % count(self::CUSTOMER_NAMES)],
                'phone' => '09'.substr(str_pad((string) (12000000 + $i * 137), 8, '0', STR_PAD_LEFT), 0, 8),
                'address' => self::CUSTOMER_ADDRESSES[$i % count(self::CUSTOMER_ADDRESSES)],
                'reported_defect' => self::REPORTED_DEFECTS[$i % count(self::REPORTED_DEFECTS)],
                'manual_description' => self::MANUAL_DESCRIPTIONS[$i % count(self::MANUAL_DESCRIPTIONS)],
            ]);

            if (in_array($status, CurrentBaselineComponentFlowController::CASE_STATUSES_ALLOWING_ISSUE, true) && $isIdentified) {
                $activeCasesForComponents[] = ['case_id' => $caseId, 'status' => $status];
            }
        }

        $this->seedComponentIssues($activeCasesForComponents);
        if (! $this->completedFixture()) {
            throw new RuntimeException('WARRANTY_REPORT_COMPLETION_UNPROVEN_'.$this->completionFailureCode());
        }
        $remaining = (int) DB::table('container_items as ci')->join('containers as c', 'c.container_id', '=', 'ci.container_id')
            ->where('c.container_code', self::BOX_CODE)->where('ci.is_active', true)->sum('ci.quantity');
        if ($this->availableBalance($componentSku) !== $openingBalance + $remaining) {
            throw new RuntimeException('WARRANTY_REPORT_BALANCE_DELTA_INVALID');
        }
    }

    /**
     * Phân bổ trạng thái theo timeline 4 tháng 06→09/2026: hồ sơ cũ (tháng 6-7)
     * phần lớn đã đóng (RETURNED/COMPLETED/CANCELLED), hồ sơ mới (tháng 8-9) còn
     * đang xử lý (RECEIVED/CHECKING/REPAIRING). Mỗi tháng đều có mặt cả 6 trạng
     * thái để bộ lọc trạng thái × bộ lọc kỳ luôn ra dữ liệu.
     */
    private function statusForIndex(int $i): string
    {
        $n = self::TARGET_CASES;
        if ($i < (int) ($n * 0.25)) { // tháng 6 — gần như đã đóng hết
            return ['RETURNED', 'COMPLETED', 'CANCELLED', 'RETURNED', 'COMPLETED', 'CHECKING'][$i % 6];
        }
        if ($i < (int) ($n * 0.50)) { // tháng 7 — đóng là chính, còn vài hồ sơ tồn
            return ['COMPLETED', 'RETURNED', 'REPAIRING', 'CANCELLED', 'RETURNED', 'RECEIVED'][$i % 6];
        }
        if ($i < (int) ($n * 0.75)) { // tháng 8 — hỗn hợp
            return ['REPAIRING', 'CHECKING', 'COMPLETED', 'RETURNED', 'CANCELLED', 'RECEIVED'][$i % 6];
        }

        return ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RECEIVED', 'CANCELLED'][$i % 6]; // tháng 9 — đang xử lý
    }

    /**
     * Hạn xử lý dự kiến, trải đủ 4 mức rủi ro của báo cáo bảo hành:
     *  - Hồ sơ ĐÃ ĐÓNG (COMPLETED/RETURNED/CANCELLED) → hạn tính theo ngày tiếp
     *    nhận (dữ liệu lịch sử), báo cáo tự xếp NOT_APPLICABLE.
     *  - Hồ sơ CÒN MỞ → neo theo hôm nay: quá hạn / sắp đến hạn / đúng hạn, và
     *    một phần để trống (chưa hẹn ngày trả máy).
     */
    private function estimatedCompletionDate(string $status, Carbon $receivedAt, Carbon $now): ?string
    {
        if (in_array($status, ['COMPLETED', 'RETURNED', 'CANCELLED'], true)) {
            return $receivedAt->copy()->addDays(7 + ($receivedAt->day % 9))->toDateString();
        }

        return match ($receivedAt->day % 4) {
            0 => null,                                        // chưa hẹn ngày (NO_ESTIMATE)
            1 => $now->copy()->subDays(3)->toDateString(),     // quá hạn (OVERDUE)
            2 => $now->copy()->addDay()->toDateString(),       // sắp đến hạn (DUE_SOON)
            default => $now->copy()->addDays(6)->toDateString(), // đúng hạn (ON_TRACK)
        };
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function makeCase(array $c): string
    {
        /** @var Carbon $receivedAt */
        $receivedAt = $c['received_at'];
        $status = (string) $c['status'];
        $tempOnly = $c['item_id'] === null;
        $now = now();

        $transitions = $this->lifecyclePath($status);
        $version = 1 + count($transitions);

        // Tính mốc thời gian các event/trạng thái (mỗi transition +1 ngày, ≤ now).
        $completedAt = null;
        $returnedAt = null;
        $closedAt = null;
        $eventAt = $receivedAt->copy();
        foreach ($transitions as $target) {
            $eventAt = min($eventAt->copy()->addDay(), $now);
            if ($target === 'COMPLETED') {
                $completedAt = $eventAt->copy();
            } elseif ($target === 'RETURNED') {
                $returnedAt = $eventAt->copy();
                $closedAt = $eventAt->copy();
            } elseif ($target === 'CANCELLED') {
                $closedAt = $eventAt->copy();
            }
        }

        $caseId = (string) Str::uuid();
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $caseId,
            'warranty_case_code' => $c['code'],
            'item_id' => $c['item_id'],
            'inventory_serial_id' => null,
            'product_id' => $c['sku_id'],
            'temporary_item_code' => $tempOnly ? 'TEMP-'.$c['code'] : null,
            'identification_status' => $tempOnly ? 'TEMP_ONLY' : 'IDENTIFIED',
            'sales_trace_status' => $tempOnly ? 'NOT_TRACEABLE' : 'TRACEABLE',
            'missing_code_reason' => $tempOnly ? 'Khách làm mất tem/mã vật lý trên máy, không tra được serial gốc.' : null,
            'manual_product_description' => $tempOnly ? $c['manual_description'] : null,
            'defect_id' => $c['defect_id'],
            'customer_name' => $c['name'],
            'customer_phone' => $c['phone'],
            'customer_address' => $c['address'],
            'purchase_date' => $receivedAt->copy()->subDays(60 + ($version * 23))->toDateString(),
            'status' => $status,
            'condition_status' => 'DEFECTIVE',
            'description' => $c['reported_defect'],
            'reported_defect' => $c['reported_defect'],
            'confirmed_defect' => in_array($status, ['REPAIRING', 'COMPLETED', 'RETURNED'], true)
                ? 'Đã chẩn đoán và xác nhận nguyên nhân, tiến hành sửa chữa theo quy trình.'
                : null,
            'accessories_received' => $tempOnly ? null : 'Chìa khóa, sách hướng dẫn',
            'received_condition' => $tempOnly ? 'Vỏ máy trầy xước, mất tem' : 'Nguyên vỏ, đủ phụ kiện tiêu chuẩn',
            'cancel_reason' => $status === 'CANCELLED'
                ? 'Khách đổi ý xin nhận lại máy, không sửa vì chi phí dự kiến cao.'
                : null,
            'received_at' => $receivedAt,
            // Hạn xử lý dự kiến — nguồn của cột "Mức rủi ro hạn xử lý" ở báo cáo
            // bảo hành (ON_TRACK / DUE_SOON / OVERDUE / NO_ESTIMATE). Báo cáo so
            // hạn với NGÀY XEM, nên hồ sơ CÒN MỞ phải neo theo now() thật lúc
            // chạy seed (không backdate), nếu không mọi hồ sơ đều thành OVERDUE.
            'estimated_completion_date' => $this->estimatedCompletionDate($status, $receivedAt, $now),
            'completed_at' => $completedAt,
            'returned_at' => $returnedAt,
            'closed_at' => $closedAt,
            'created_by' => $this->actorId,
            'version' => $version,
            'legal_hold_flag' => false,
            'created_at' => $receivedAt,
            'updated_at' => $closedAt ?? $now,
        ]));

        // Event: RECEIVE + 1 event/transition.
        $eventTypes = [
            'CHECKING' => 'CHECK',
            'REPAIRING' => 'REPAIR',
            'COMPLETED' => 'COMPLETE',
            'RETURNED' => 'RETURN',
            'CANCELLED' => 'CANCEL',
        ];
        $notes = [
            'CHECKING' => 'Bắt đầu kiểm tra chẩn đoán.',
            'REPAIRING' => 'Xác nhận nguyên nhân, bắt đầu sửa chữa.',
            'COMPLETED' => 'Hoàn tất sửa chữa, chạy thử đạt yêu cầu.',
            'RETURNED' => 'Đã bàn giao máy cho khách, khách ký nhận.',
            'CANCELLED' => $c['status'] === 'CANCELLED' ? 'Khách yêu cầu hủy, không tiến hành sửa.' : 'Hủy hồ sơ.',
        ];
        $this->insertEvent($caseId, 'RECEIVE', null, 'RECEIVED', $c['reported_defect'], $receivedAt);
        $from = 'RECEIVED';
        $eventAt = $receivedAt->copy();
        foreach ($transitions as $target) {
            $eventAt = min($eventAt->copy()->addDay(), $now);
            $this->insertEvent($caseId, $eventTypes[$target], $from, $target, $notes[$target], $eventAt);
            $from = $target;
        }

        // Ánh xạ 1 defect chính vào bảng nhiều-nhiều (report by_defect cũng đọc cột này).
        if ($c['defect_id'] !== null && Schema::hasTable('warranty_case_defects')) {
            DB::table('warranty_case_defects')->insert([
                'warranty_case_defect_id' => (string) Str::uuid(),
                'warranty_case_id' => $caseId,
                'defect_id' => $c['defect_id'],
                'is_primary' => true,
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);
        }

        // Trạng thái item (stock-neutral). CANCELLED giữ ISSUED.
        if (! $tempOnly && $status !== 'CANCELLED') {
            DB::table('item_instances')->where('item_id', $c['item_id'])->update([
                'item_status' => $status === 'RETURNED' ? 'RETURNED_TO_CUSTOMER' : 'IN_WARRANTY',
                'updated_at' => $now,
            ]);
        }

        return $caseId;
    }

    private function lifecyclePath(string $status): array
    {
        return match ($status) {
            'RECEIVED' => [],
            'CHECKING' => ['CHECKING'],
            'REPAIRING' => ['CHECKING', 'REPAIRING'],
            'COMPLETED' => ['CHECKING', 'REPAIRING', 'COMPLETED'],
            'RETURNED' => ['CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED'],
            'CANCELLED' => ['CHECKING', 'CANCELLED'],
            default => [],
        };
    }

    private function insertEvent(string $caseId, string $type, ?string $from, string $to, string $note, Carbon $at): void
    {
        DB::table('warranty_event')->insert([
            'warranty_event_id' => (string) Str::uuid(),
            'warranty_case_id' => $caseId,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'created_by' => $this->actorId,
            'event_at' => $at,
        ]);
    }

    // ------------------------------------------------------------------
    // "Linh kiện đã xuất" — component issue POSTED gắn case active (bổ sung).
    // ------------------------------------------------------------------

    /**
     * @param  list<array{case_id:string,status:string}>  $cases
     */
    private function seedComponentIssues(array $cases): void
    {
        if ($cases === []) {
            return;
        }

        $componentSku = $this->ensureComponentSku();
        $boxId = $this->ensureStockedBox($componentSku, 120);

        foreach ($this->componentPlan($cases) as $entry) {
            $this->postComponentIssueForCase($entry['issue_no'], $entry['case_id'], $componentSku, $boxId, $entry['quantity']);
        }
    }

    private function componentPlan(array $cases): array
    {
        $plan = [];
        foreach (array_values($cases) as $index => $case) {
            if ($index % 3 !== 0) {
                continue;
            }
            $sequence = count($plan) + 1;
            $plan[] = ['issue_no' => 'CID-RPTW-'.sprintf('%02d', $sequence),
                'case_id' => $case['case_id'], 'quantity' => 1 + ($sequence % 3)];
        }

        return $plan;
    }

    private function ensureComponentSku(): string
    {
        $sku = Sku::query()->where('sku_code', self::COMPONENT_SKU)->first();
        if ($sku === null || $sku->sku_type !== 'COMPONENT' || $sku->is_serial_tracked || $sku->status !== 'ACTIVE') {
            throw new RuntimeException('WARRANTY_REPORT_COMPONENT_ANCHOR_INCOMPATIBLE');
        }

        return (string) $sku->sku_id;
    }

    private function completedFixture(): bool
    {
        return $this->completionFailureCode() === null;
    }

    private function completionFailureCode(): ?string
    {
        $cases = DB::table('warranty_case')->where('warranty_case_code', 'like', 'WC-RPTW-%')
            ->orderBy('warranty_case_code')->get()->keyBy('warranty_case_code');
        if ($cases->count() !== self::TARGET_CASES) {
            return 'CASE_SET_INCOMPLETE';
        }
        $eligible = [];
        for ($index = 0; $index < self::TARGET_CASES; $index++) {
            $case = $cases->get('WC-RPTW-'.sprintf('%04d', $index + 1));
            if ($case === null || $case->status !== $this->statusForIndex($index) || $case->created_by !== $this->actorId) {
                return 'CASE_IDENTITY_OR_STATE_CHANGED';
            }
            if ($case->item_id !== null && in_array($case->status, CurrentBaselineComponentFlowController::CASE_STATUSES_ALLOWING_ISSUE, true)) {
                $eligible[] = ['case_id' => $case->warranty_case_id];
            }
        }
        $plan = $this->componentPlan($eligible);
        if ($plan === []) {
            return 'COMPONENT_CASES_UNAVAILABLE';
        }
        $skuId = $this->ensureComponentSku();
        $box = DB::table('containers')->where('container_code', self::BOX_CODE)->first();
        $receipt = DB::table('stock_documents')->where('doc_no', 'PN-RPTW-BOX-01')->first();
        if ($box === null || $receipt === null || $box->primary_sku_id !== $skuId
            || $receipt->status !== 'POSTED' || $receipt->dst_warehouse_id !== $this->warehouseId
            || $box->created_by !== $this->actorId || $receipt->created_by !== $this->actorId) {
            return 'RECEIPT_OR_BOX_UNPROVEN';
        }
        $movement = DB::table('stock_movements')->where('stock_document_id', $receipt->id)
            ->where('warehouse_id', $this->warehouseId)->where('product_id', $skuId)->get();
        if ($movement->count() !== 1 || $movement[0]->movement_type !== 'RECEIPT' || (int) $movement[0]->qty_delta !== 120
            || (int) $movement[0]->qty_after !== (int) $movement[0]->qty_before + 120) {
            return 'RECEIPT_MOVEMENT_UNPROVEN';
        }
        $after = (int) $movement[0]->qty_after;
        $remaining = 120;
        $issues = DB::table('component_issue_documents')->where('component_issue_no', 'like', 'CID-RPTW-%')
            ->get()->keyBy('component_issue_no');
        if ($issues->count() !== count($plan)) {
            return 'COMPONENT_PLAN_INCOMPLETE';
        }
        foreach ($plan as $entry) {
            $issue = $issues->get($entry['issue_no']);
            if ($issue === null || $issue->status !== 'POSTED' || $issue->purpose !== 'WARRANTY'
                || $issue->warranty_case_id !== $entry['case_id'] || $issue->warehouse_id !== $this->warehouseId
                || $issue->created_by !== $this->actorId) {
                return 'COMPONENT_DOCUMENT_UNPROVEN';
            }
            $lines = DB::table('component_issue_lines')->where('component_issue_document_id', $issue->id)->get();
            if ($lines->count() !== 1 || $lines[0]->sku_id !== $skuId
                || (int) $lines[0]->required_qty !== $entry['quantity'] || (int) $lines[0]->scanned_qty !== $entry['quantity']) {
                return 'COMPONENT_LINE_UNPROVEN';
            }
            $targets = DB::table('component_issue_items')->where('component_issue_line_id', $lines[0]->id)->get();
            if ($targets->count() !== 1 || $targets[0]->container_id !== $box->container_id
                || $targets[0]->item_id !== null || (int) $targets[0]->quantity !== $entry['quantity']) {
                return 'COMPONENT_TARGET_UNPROVEN';
            }
            // Business sequence, not second-resolution timestamps or random UUID order.
            $movements = DB::table('stock_movements')->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')->where('source_id', $issue->id)
                ->where('warehouse_id', $this->warehouseId)->where('product_id', $skuId)->get();
            if ($movements->count() !== 1 || (int) $movements[0]->qty_delta !== -$entry['quantity']
                || (int) $movements[0]->qty_before !== $after || (int) $movements[0]->qty_after !== $after - $entry['quantity']) {
                return 'COMPONENT_MOVEMENT_CHAIN_INVALID';
            }
            $after -= $entry['quantity'];
            $remaining -= $entry['quantity'];
        }
        $mappings = DB::table('container_items')->where('container_id', $box->container_id)->get();
        if ($mappings->count() !== 1 || $mappings[0]->sku_id !== $skuId || $mappings[0]->source_id !== $receipt->id
            || $mappings[0]->source_type !== 'RECEIPT' || (int) $mappings[0]->quantity !== $remaining
            || ! $mappings[0]->is_active) {
            return 'BOX_SUBLEDGER_UNPROVEN';
        }

        return null;
    }

    private function availableBalance(string $skuId): int
    {
        return (int) DB::table('inventory_balances')->where('warehouse_id', $this->warehouseId)
            ->where('product_id', $skuId)->where('stock_status', 'AVAILABLE')->sum('quantity');
    }

    /** Tạo BOX + nhập tồn qua service thật (RECEIPT +qty) để đủ tồn cho Post issue. */
    private function ensureStockedBox(string $componentSku, int $qty): string
    {
        $existing = DB::table('containers')->where('container_code', self::BOX_CODE)->value('container_id');
        if ($existing !== null || DB::table('stock_documents')->where('doc_no', 'PN-RPTW-BOX-01')->exists()) {
            throw new RuntimeException('WARRANTY_REPORT_EXISTING_BOX_REQUIRES_REVIEW');
        }

        $boxId = (string) Str::uuid();
        DB::table('containers')->insert([
            'container_id' => $boxId,
            ...(Schema::hasColumn('containers', 'id') ? ['id' => $boxId] : []),
            'container_code' => self::BOX_CODE,
            'zone_code' => 'ZONE-B',
            'location_hint' => 'Thùng B-07',
            'container_type' => 'BOX',
            'primary_sku_id' => $componentSku,
            'status' => 'EMPTY',
            'version' => 1,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $docNo = 'PN-RPTW-BOX-01';
        if (! DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            $documentId = (string) Str::uuid();
            DB::table('stock_documents')->insert([
                'id' => $documentId,
                'doc_type' => 'receipt',
                'doc_no' => $docNo,
                'doc_date' => now()->toDateString(),
                'status' => 'DRAFT',
                'dst_warehouse_id' => $this->warehouseId,
                'source_name' => 'Nhà cung cấp linh kiện Hoa Nam — bổ sung tồn báo cáo bảo hành',
                'note' => 'Nhập linh kiện thay thế phục vụ dữ liệu báo cáo bảo hành (seeder)',
                'version' => 1,
                'created_by' => $this->actorId,
                'updated_by' => $this->actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $lineId = (string) Str::uuid();
            DB::table('stock_document_lines')->insert([
                'id' => $lineId,
                'stock_document_id' => $documentId,
                'line_no' => 1,
                'product_id' => $componentSku,
                'qty_planned' => $qty,
                'qty_actual' => $qty,
                'scanned_qty' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->stock->scanStockDocument($documentId, 'receipt', [
                'line_id' => $lineId,
                'quantity' => $qty,
                'container_id' => $boxId,
            ], $this->actorId);

            DB::table('stock_documents')->where('id', $documentId)->update([
                'version' => 2,
                'updated_by' => $this->actorId,
                'updated_at' => now(),
            ]);
            $key = 'seed-rptw-receipt-'.$documentId;
            $this->stock->postStockDocument($documentId, 'receipt', $this->actorId, $key, sha1($key));

            // Post Receipt owns the single BOX mapping and its stock-bearing status.
        }

        return $boxId;
    }

    private function postComponentIssueForCase(string $issueNo, string $caseId, string $componentSku, string $boxId, int $qty): void
    {
        DB::transaction(fn () => $this->createAndPostComponentIssue($issueNo, $caseId, $componentSku, $boxId, $qty));
    }

    private function createAndPostComponentIssue(string $issueNo, string $caseId, string $componentSku, string $boxId, int $qty): void
    {
        $documentId = (string) Str::uuid();
        DB::table('component_issue_documents')->insert([
            'id' => $documentId,
            'component_issue_no' => $issueNo,
            'warehouse_id' => $this->warehouseId,
            'warranty_case_id' => $caseId,
            'document_date' => now()->toDateString(),
            'purpose' => 'WARRANTY',
            'status' => 'DRAFT',
            'note' => 'Xuất linh kiện thay thế cho hồ sơ bảo hành (seeder báo cáo)',
            'created_at' => now(),
            'created_by' => $this->actorId,
            'updated_at' => now(),
            'updated_by' => $this->actorId,
            'version' => 0,
        ]);

        $lineId = (string) Str::uuid();
        DB::table('component_issue_lines')->insert([
            'id' => $lineId,
            'component_issue_document_id' => $documentId,
            'line_no' => 1,
            'sku_id' => $componentSku,
            'required_qty' => $qty,
            'scanned_qty' => $qty,
            'created_at' => now(),
            'created_by' => $this->actorId,
            'updated_at' => now(),
            'updated_by' => $this->actorId,
            'version' => 0,
        ]);

        DB::table('component_issue_items')->insert([
            'id' => (string) Str::uuid(),
            'component_issue_line_id' => $lineId,
            'item_id' => null,
            'container_id' => $boxId,
            'quantity' => $qty,
            'created_at' => now(),
            'created_by' => $this->actorId,
            'updated_at' => now(),
            'updated_by' => $this->actorId,
            'version' => 0,
        ]);

        AuditLogger::record(
            action: 'CREATE_COMPONENT_ISSUE',
            objectType: 'component_issue_document',
            objectId: $documentId,
            actorId: $this->actorId,
            requestId: 'seed-rptw-'.strtolower($issueNo),
            afterData: ['status' => 'DRAFT', 'purpose' => 'WARRANTY', 'stock_effect' => 'NONE'],
        );

        app(ComponentContainerSubledgerService::class)->consumeDocument($documentId);
        $key = 'seed-rptw-component-issue-'.$documentId;
        $this->stock->postComponentIssue($documentId, $this->actorId, $key, sha1($key));
    }
}
