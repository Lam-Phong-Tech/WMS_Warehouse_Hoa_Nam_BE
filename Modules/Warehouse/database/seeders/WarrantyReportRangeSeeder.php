<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\ComponentContainerSubledgerService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Support\WarrantyCasePii;
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

    /** Mã case cuối cùng — dùng làm guard "đã seed đủ". */
    private const GUARD_LAST_CASE = 'WC-RPTW-0160';

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
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::table('warranty_case')->where('warranty_case_code', self::GUARD_LAST_CASE)->exists()) {
            $this->command?->info('WarrantyReportRangeSeeder: dữ liệu báo cáo bảo hành mẫu đã tồn tại đủ, bỏ qua.');

            return;
        }

        $warehouse = Warehouse::query()->where('code', self::WAREHOUSE_CODE)->first()
            ?? Warehouse::query()->orderBy('created_at')->first();
        $actorId = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
            ?? DB::table('app_user')->where('username', 'admin')->value('user_id')
            ?? DB::table('app_user')->orderBy('created_at')->value('user_id');
        if ($warehouse === null || $actorId === null) {
            $this->command?->warn('WarrantyReportRangeSeeder: thiếu kho/user — chạy Warehouse*/Auth*Seeder trước.');

            return;
        }

        $this->stock = app(CurrentBaselineStockService::class);
        $this->actorId = (string) $actorId;
        $this->warehouseId = (string) $warehouse->id;

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

            // Gom case active REPAIRING/COMPLETED để đính kèm component issue.
            if (in_array($status, ['REPAIRING', 'COMPLETED'], true) && $isIdentified) {
                $activeCasesForComponents[] = ['case_id' => $caseId, 'status' => $status];
            }
        }

        $this->command?->info('WarrantyReportRangeSeeder: đã seed '.self::TARGET_CASES.' warranty case ('.self::START_DATE.'..'.self::END_DATE.', đủ 6 trạng thái).');

        // "Linh kiện đã xuất" — bổ sung, không được phép làm hỏng lõi case ở trên.
        try {
            $this->seedComponentIssues($activeCasesForComponents);
        } catch (Throwable $e) {
            $this->command?->warn('WarrantyReportRangeSeeder: bỏ qua phần component issue ("linh kiện đã xuất") — '.$e->getMessage());
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

        // Chọn ~1/3 case active để đính linh kiện — vừa đủ "sinh động", không lạm.
        $picked = array_values(array_filter($cases, fn ($_c, $idx): bool => $idx % 3 === 0, ARRAY_FILTER_USE_BOTH));
        $seq = 0;
        foreach ($picked as $case) {
            $seq++;
            $issueNo = 'CID-RPTW-'.sprintf('%02d', $seq);
            if (DB::table('component_issue_documents')->where('component_issue_no', $issueNo)->exists()) {
                continue;
            }
            $qty = 1 + ($seq % 3); // 1..3
            $this->postComponentIssueForCase($issueNo, $case['case_id'], $componentSku, $boxId, $qty);
        }
    }

    private function ensureComponentSku(): string
    {
        $existing = Sku::query()->where('sku_code', self::COMPONENT_SKU)->value('sku_id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $sku = Sku::query()->create([
            'sku_code' => self::COMPONENT_SKU,
            'sku_name' => 'Bo mạch điều khiển thay thế (mẫu báo cáo bảo hành)',
            'sku_type' => 'COMPONENT',
            'unit' => 'cái',
            'is_serial_tracked' => false,
            'brand_id' => DB::table('brands')->where('brand_code', 'DONGCHENG')->value('id'),
            'category_id' => DB::table('product_categories')->where('category_code', 'SPARE_PART')->value('id'),
            'model_id' => DB::table('product_models')->where('model_code', 'DC-DCA')->value('id'),
            'version_code' => 'V1',
            'warranty_applicable' => false,
            'published_to_app' => true,
            'status' => 'ACTIVE',
        ]);

        return (string) $sku->sku_id;
    }

    /** Tạo BOX + nhập tồn qua service thật (RECEIPT +qty) để đủ tồn cho Post issue. */
    private function ensureStockedBox(string $componentSku, int $qty): string
    {
        $existing = DB::table('containers')->where('container_code', self::BOX_CODE)->value('container_id');
        if ($existing !== null) {
            return (string) $existing;
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

            DB::table('container_items')->insert([
                'container_item_id' => (string) Str::uuid(),
                'container_id' => $boxId,
                'item_id' => null,
                'sku_id' => $componentSku,
                'quantity' => $qty,
                'status' => 'ACTIVE',
                'is_active' => true,
                'source_type' => 'STOCK_DOCUMENT',
                'source_id' => $documentId,
                'placed_at' => now(),
                'placed_by' => $this->actorId,
                'created_by' => $this->actorId,
                'updated_by' => $this->actorId,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $boxId;
    }

    private function postComponentIssueForCase(string $issueNo, string $caseId, string $componentSku, string $boxId, int $qty): void
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
