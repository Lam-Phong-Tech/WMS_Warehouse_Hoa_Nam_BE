<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\ImportBatch;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Models\InventorySerial;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\PvRelations;

/**
 * Seed bổ sung cho các bảng "vệ tinh" chưa được WarehouseDatabaseSeeder/
 * WarehouseBusinessDataSeeder chạm tới — mục tiêu duy nhất là mỗi bảng đạt
 * tối thiểu 5 dòng mẫu (yêu cầu demo/QA), không phải mở rộng nghiệp vụ.
 *
 * Nguyên tắc giữ nguyên như 2 seeder trước:
 *  - Bảng có service/controller thật ghi vào thì copy đúng shape của nó.
 *  - Bảng là mirror một chiều (pv_product_industry -> pv_product_industries,
 *    idempotency_keys -> idempotency_records) thì chỉ ghi vào bảng nguồn,
 *    không ghi tay vào bảng mirror để tránh lệch dữ liệu.
 *  - Bảng hệ thống/runtime (aggregate_mutexes, idempotency_keys, audit_outbox,
 *    refresh_tokens, migration_runs/errors) ghi ở trạng thái ĐÃ HOÀN TẤT
 *    (processed/done/released), không ghi trạng thái "đang xử lý/đang giữ
 *    lock" giả — tránh cron/consumer thật nhặt phải dữ liệu giả và tạo log
 *    lỗi phantom trong môi trường dev.
 *  - putaway_task/putaway_task_line: KHÔNG có luồng nghiệp vụ nào (controller/
 *    service/model) ghi vào 2 bảng này — đã bị thay thế hoàn toàn bởi
 *    putaway_events. Dữ liệu ở đây là placeholder thuần theo yêu cầu "mọi
 *    bảng ≥5 dòng", được đánh dấu rõ trong status/note để không ai nhầm là
 *    dữ liệu nghiệp vụ thật.
 *  - Idempotent: mọi insert đều guard theo khóa tự nhiên (code/hash/key) để
 *    chạy lại db:seed không tạo trùng.
 */
class WarehouseSupplementalDataSeeder extends Seeder
{
    private string $warehouseId;

    private string $actorId;

    public function run(): void
    {
        $warehouseId = Warehouse::query()->where('code', 'KHO-TONG-HN')->value('id');
        $actorId = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
            ?? DB::table('app_user')->where('username', 'admin')->value('user_id');

        if ($warehouseId === null || $actorId === null) {
            $this->command?->warn('WarehouseSupplementalDataSeeder: thiếu warehouse/actor gốc; chạy Warehouse*Seeder trước.');

            return;
        }

        $this->warehouseId = (string) $warehouseId;
        $this->actorId = (string) $actorId;

        $this->seedImportBatches();
        $this->seedImportReports();
        $this->seedInventorySerials();
        $this->seedSkuAliases();
        $this->seedSkuCompatibleAccessories();
        $this->seedSkuComponents();
        $this->seedScanBatchesAndEvents();
        $this->seedLabelPrintEvents();
        $this->seedOutboundRequestImportRows();
        $this->seedWarrantyAttachments();
        $this->seedReconciliationIncidents();
        $this->seedPvRecommendationRules();
        $this->seedPvProductImagesTagsIndustries();
        $this->seedPutawayTaskLegacyPlaceholder();

        $this->seedAggregateMutexes();
        $this->seedIdempotencyKeys();
        $this->seedMigrationRunsAndErrors();
        $this->seedSkuImportClaims();
        $this->seedAuditOutbox();
        $this->seedRefreshTokens();

        $this->command?->info('WarehouseSupplementalDataSeeder: đã bổ sung dữ liệu mẫu cho các bảng vệ tinh.');
    }

    // ------------------------------------------------------------------
    // Group A — dữ liệu nghiệp vụ/tham chiếu
    // ------------------------------------------------------------------

    private function seedImportBatches(): void
    {
        $rows = [
            ['import_batch_code' => 'IMP-SEED-BATCH-001', 'shipment_container_code' => 'SHIP-SEED-001', 'supplier_batch_no' => 'SUP-SEED-001', 'import_period' => '2026-Q1', 'supplier_name' => 'Kubota Việt Nam'],
            ['import_batch_code' => 'IMP-SEED-BATCH-002', 'shipment_container_code' => 'SHIP-SEED-002', 'supplier_batch_no' => 'SUP-SEED-002', 'import_period' => '2026-Q1', 'supplier_name' => 'Yanmar Việt Nam'],
            ['import_batch_code' => 'IMP-SEED-BATCH-003', 'shipment_container_code' => 'SHIP-SEED-003', 'supplier_batch_no' => 'SUP-SEED-003', 'import_period' => '2026-Q2', 'supplier_name' => 'Honda Việt Nam'],
            ['import_batch_code' => 'IMP-SEED-BATCH-004', 'shipment_container_code' => 'SHIP-SEED-004', 'supplier_batch_no' => 'SUP-SEED-004', 'import_period' => '2026-Q2', 'supplier_name' => 'Husqvarna Việt Nam'],
            ['import_batch_code' => 'IMP-SEED-BATCH-005', 'shipment_container_code' => 'SHIP-SEED-005', 'supplier_batch_no' => 'SUP-SEED-005', 'import_period' => '2026-Q3', 'supplier_name' => 'Nhà cung cấp linh kiện Hoa Nam'],
        ];

        foreach ($rows as $row) {
            ImportBatch::firstOrCreate(
                ['import_batch_code' => $row['import_batch_code']],
                [
                    'shipment_container_code' => $row['shipment_container_code'],
                    'supplier_batch_no' => $row['supplier_batch_no'],
                    'import_period' => $row['import_period'],
                    'supplier_name' => $row['supplier_name'],
                    'status' => 'ACTIVE',
                ]
            );
        }
    }

    private function seedImportReports(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $requestId = 'seed-import-report-'.sprintf('%03d', $i);
            if (ImportReport::query()->where('request_id', $requestId)->exists()) {
                continue;
            }

            ImportReport::query()->create([
                'import_type' => 'SKU',
                'mode' => 'COMMIT',
                'status' => 'COMMITTED',
                'file_name' => 'seed-sku-import-'.$i.'.xlsx',
                'file_sha256' => hash('sha256', 'seed-sku-import-'.$i),
                'template_version' => 'v1',
                'total_rows' => 10,
                'valid_rows' => 10,
                'error_rows' => 0,
                'inserted_rows' => 8,
                'updated_rows' => 2,
                'row_errors' => [],
                'actor_id' => $this->actorId,
                'request_id' => $requestId,
                'options_fingerprint' => hash('sha256', 'seed-options-'.$i),
            ]);
        }
    }

    private function seedInventorySerials(): void
    {
        $sku = Sku::query()->where('sku_code', DongChengAnchorSkus::PRODUCT_3)->first();
        if ($sku === null) {
            return;
        }

        for ($i = 1; $i <= 5; $i++) {
            $serial = 'SN-SEED-INV-'.sprintf('%03d', $i);
            InventorySerial::firstOrCreate(
                ['serial_no' => $serial],
                [
                    'product_id' => $sku->sku_id,
                    'warehouse_id' => $this->warehouseId,
                    'physical_code_id' => null,
                    'condition' => 'GOOD',
                    'stock_condition' => 'AVAILABLE',
                    'item_condition' => 'NEW',
                    'status' => 'IN_STOCK',
                ]
            );
        }
    }

    /**
     * Chưa có luồng nghiệp vụ thật ghi vào bảng này — dữ liệu tham chiếu mẫu,
     * schema-valid. Ghi vào bảng canonical `sku_alias`: `sku_aliases` chỉ là VIEW
     * tương thích (migration 2026_08_09_000400) — insert vào view nổ trên sqlite.
     */
    private function seedSkuAliases(): void
    {
        $skuIds = Sku::query()->orderBy('sku_code')->limit(5)->pluck('sku_id');
        $systems = ['LEGACY_ERP', 'LEGACY_ERP', 'SAP_B1', 'SAP_B1', 'PARTNER_PORTAL'];

        foreach ($skuIds as $index => $skuId) {
            $aliasCode = 'ALIAS-SEED-'.sprintf('%03d', $index + 1);
            // Guard PHẢI theo alias_code — cột UNIQUE của bảng. Guard cũ theo cặp
            // (product_id, alias_code) không idempotent: `limit(5) order by sku_code`
            // trả về tập SKU khác nhau giữa hai lần seed (các seeder sau bổ sung SKU
            // có mã đứng trước về alphabet), nên lần chạy thứ hai gán ALIAS-SEED-005
            // cho một SKU khác → guard trượt và vỡ UNIQUE alias_code.
            $exists = DB::table('sku_alias')
                ->where('alias_code', $aliasCode)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('sku_alias')->insert([
                'alias_id' => (string) Str::uuid(),
                'product_id' => $skuId,
                'alias_type' => $systems[$index] ?? 'LEGACY_ERP',
                'alias_code' => $aliasCode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Chưa có luồng nghiệp vụ thật ghi vào bảng này — dữ liệu tham chiếu mẫu, schema-valid. */
    private function seedSkuCompatibleAccessories(): void
    {
        $skuIds = Sku::query()->orderBy('sku_code')->limit(6)->pluck('sku_id')->values();
        if ($skuIds->count() < 6) {
            return;
        }

        // Ghép 5 cặp (main, accessory) khác nhau, không tự tham chiếu.
        $pairs = [
            [$skuIds[0], $skuIds[1]],
            [$skuIds[0], $skuIds[2]],
            [$skuIds[1], $skuIds[3]],
            [$skuIds[2], $skuIds[4]],
            [$skuIds[3], $skuIds[5]],
        ];

        foreach ($pairs as $index => [$mainId, $accessoryId]) {
            $exists = DB::table('sku_compatible_accessories')
                ->where('sku_id', $mainId)
                ->where('accessory_sku_id', $accessoryId)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('sku_compatible_accessories')->insert([
                'sku_id' => $mainId,
                'accessory_sku_id' => $accessoryId,
                'priority' => $index + 1,
                'created_at' => now(),
                'created_by' => $this->actorId,
                'note' => 'Dữ liệu mẫu về khả năng tương thích phụ kiện',
                'status' => 'ACTIVE',
            ]);
        }
    }

    /** Copy đúng shape của SkuAggregateService::replaceComponents(). */
    private function seedSkuComponents(): void
    {
        $parentIds = Sku::query()->where('sku_type', 'PRODUCT')->orderBy('sku_code')->limit(3)->pluck('sku_id')->values();
        $componentIds = Sku::query()->where('sku_type', 'COMPONENT')->orderBy('sku_code')->limit(3)->pluck('sku_id')->values();
        if ($parentIds->count() < 2 || $componentIds->count() < 2) {
            return;
        }

        $pairs = [];
        foreach ($parentIds as $pi => $parentId) {
            foreach ($componentIds as $ci => $componentId) {
                $pairs[] = [$parentId, $componentId, ($ci + 1)];
            }
        }
        $pairs = array_slice($pairs, 0, 5);

        foreach ($pairs as [$parentId, $componentId, $qty]) {
            $exists = DB::table('sku_component')
                ->where('parent_product_id', $parentId)
                ->where('component_product_id', $componentId)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('sku_component')->insert([
                'sku_component_id' => (string) Str::uuid(),
                'parent_product_id' => $parentId,
                'component_product_id' => $componentId,
                'quantity' => (string) $qty,
                'status' => 'ACTIVE',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Copy đúng shape của ScanRecordingService — scan_batches + scan_record_events. */
    private function seedScanBatchesAndEvents(): void
    {
        $codes = DB::table('physical_codes')->limit(5)->get(['physical_code_id', 'code_value', 'item_id']);
        if ($codes->isEmpty()) {
            return;
        }

        $sourceTypes = ['INBOUND', 'PUTAWAY', 'OUTBOUND', 'WARRANTY', 'INBOUND'];

        foreach ($codes as $index => $code) {
            // scan_batches PK là uuid, không phải khóa tự nhiên đọc được ->
            // guard idempotency qua raw_code cố định trên scan_record_events.
            $rawCode = 'SEED-SCAN-'.sprintf('%03d', $index + 1);
            if (DB::table('scan_record_events')->where('raw_code', $rawCode)->exists()) {
                continue;
            }

            $newBatchId = (string) Str::uuid();
            DB::table('scan_batches')->insert([
                'scan_batch_id' => $newBatchId,
                'source_type' => $sourceTypes[$index] ?? 'INBOUND',
                'warehouse_id' => $this->warehouseId,
                'created_by' => $this->actorId,
                'created_at' => now(),
            ]);

            DB::table('scan_record_events')->insert([
                'scan_event_id' => (string) Str::uuid(),
                'scan_batch_id' => $newBatchId,
                'raw_code' => $rawCode,
                'physical_code_id' => $code->physical_code_id,
                'resolved_object_type' => 'ITEM_INSTANCE',
                'resolved_object_id' => $code->item_id,
                'result_status' => 'RESOLVED',
                'created_at' => now(),
            ]);
        }
    }

    /** Copy đúng shape ghi trong CurrentBaselineOperationsController (print label). */
    private function seedLabelPrintEvents(): void
    {
        $snapshotIds = DB::table('label_snapshots')->limit(5)->pluck('id');
        $printerId = DB::table('printer_configs')->where('status', 'ACTIVE')->value('id');
        if ($snapshotIds->isEmpty() || $printerId === null) {
            return;
        }

        foreach ($snapshotIds as $snapshotId) {
            if (DB::table('label_print_events')->where('label_snapshot_id', $snapshotId)->exists()) {
                continue;
            }

            DB::table('label_print_events')->insert([
                'id' => (string) Str::uuid(),
                'label_snapshot_id' => $snapshotId,
                'printer_config_id' => $printerId,
                'status' => 'PRINTED',
                'error_message' => null,
                'printed_at' => now(),
                'created_at' => now(),
                'created_by' => $this->actorId,
                'updated_at' => now(),
                'updated_by' => $this->actorId,
                'version' => 0,
            ]);
        }
    }

    /** Copy đúng shape ghi trong CurrentBaselineOperationsController (outbound import rows). */
    private function seedOutboundRequestImportRows(): void
    {
        $importId = DB::table('outbound_request_imports')->value('id');
        $skuId = Sku::query()->where('sku_code', DongChengAnchorSkus::BASELINE_PRODUCT)->value('sku_id');
        if ($importId === null || $skuId === null) {
            return;
        }

        for ($i = 1; $i <= 5; $i++) {
            $requestNo = 'TOA-SEED-IMPORT-ROW-'.sprintf('%03d', $i);
            if (DB::table('outbound_request_import_rows')->where('request_no', $requestNo)->exists()) {
                continue;
            }

            DB::table('outbound_request_import_rows')->insert([
                'id' => (string) Str::uuid(),
                'import_id' => $importId,
                'row_no' => $i,
                'raw_payload' => json_encode(['sku_code' => DongChengAnchorSkus::BASELINE_PRODUCT, 'required_qty' => $i], JSON_UNESCAPED_UNICODE),
                'normalized_payload' => json_encode([
                    'request_no' => $requestNo,
                    'sku_id' => $skuId,
                    'sku_code' => DongChengAnchorSkus::BASELINE_PRODUCT,
                    'required_qty' => $i,
                    'recipient_name' => 'Khách hàng mẫu '.$i,
                    'recipient_address' => 'Địa chỉ mẫu '.$i,
                ], JSON_UNESCAPED_UNICODE),
                'validation_status' => 'VALID',
                'validation_errors' => json_encode([], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        }
    }

    /** Copy đúng shape ghi trong CurrentBaselineWarrantyAttachmentController::upload(). */
    private function seedWarrantyAttachments(): void
    {
        $caseIds = DB::table('warranty_case')->limit(5)->pluck('warranty_case_id');

        foreach ($caseIds as $index => $caseId) {
            $fileName = 'seed-warranty-photo-'.sprintf('%03d', $index + 1).'.jpg';
            if (DB::table('warranty_attachments')->where('warranty_case_id', $caseId)->where('file_name', $fileName)->exists()) {
                continue;
            }

            $attachmentId = (string) Str::uuid();
            DB::table('warranty_attachments')->insert([
                'id' => $attachmentId,
                'warranty_case_id' => $caseId,
                'file_name' => $fileName,
                'storage_path' => "warranty-attachments/{$caseId}/{$attachmentId}.jpg",
                'storage_key' => "warranty-attachments/{$caseId}/{$attachmentId}.jpg",
                'mime_type' => 'image/jpeg',
                'content_type' => 'image/jpeg',
                'file_size' => 102400,
                'file_size_bytes' => 102400,
                'checksum' => hash('sha256', 'seed-content-'.$attachmentId),
                'attachment_type' => 'INTAKE',
                'status' => 'ACTIVE',
                'uploaded_at' => now(),
                'uploaded_by' => $this->actorId,
                'created_at' => now(),
                'created_by' => $this->actorId,
                'updated_at' => now(),
                'updated_by' => $this->actorId,
                'version' => 1,
            ]);
        }
    }

    /** Copy đúng shape ghi trong App\Console\Commands\ReconcileInventoryBalances::recordIncident(). */
    private function seedReconciliationIncidents(): void
    {
        $skuIds = Sku::query()->orderBy('sku_code')->limit(5)->pluck('sku_id')->values();
        $severities = ['WARNING', 'ERROR', 'CRITICAL', 'WARNING', 'ERROR'];
        // Không seed incident OPEN: core.reconciliation_backlog của wms:golive-check
        // fail-closed khi còn incident mở, nên dữ liệu mẫu để lại backlog sẽ làm gate
        // sẵn sàng đỏ trên chính môi trường vừa seed.
        $statuses = ['RESOLVED', 'RESOLVED', 'WAIVED', 'RESOLVED', 'RESOLVED'];

        foreach ($skuIds as $index => $skuId) {
            $incidentNo = 'REC-SEED-'.sprintf('%03d', $index + 1);
            if (DB::table('reconciliation_incidents')->where('incident_no', $incidentNo)->exists()) {
                continue;
            }

            $erdColumns = [
                'incident_no' => $incidentNo,
                'divergence_type' => 'QUANTITY_MISMATCH',
                'warehouse_id' => $this->warehouseId,
                'detected_value' => json_encode(['stock_status' => 'AVAILABLE', 'on_hand_qty' => 10, 'ledger_qty' => 8, 'variance' => 2.0], JSON_UNESCAPED_UNICODE),
                'detected_at' => now()->subDays(5 - $index),
                'balance_qty' => 10,
                'ledger_qty' => 8,
                'difference_qty' => 2,
                'severity' => $severities[$index] ?? 'WARNING',
                'status' => $statuses[$index] ?? 'RESOLVED',
                'created_at' => now()->subDays(5 - $index),
                'updated_at' => now()->subDays(5 - $index),
            ];

            // Cùng driver-duality với ReconcileInventoryBalances::recordIncident():
            // pgsql ghi bảng plural; sqlite chỉ có bảng singular (plural là VIEW).
            if (DB::getDriverName() === 'pgsql') {
                DB::table('reconciliation_incidents')->insert($erdColumns + [
                    'id' => (string) Str::uuid(),
                    'sku_id' => $skuId,
                ]);
            } else {
                DB::table('reconciliation_incident')->insert($erdColumns + [
                    'incident_id' => (string) Str::uuid(),
                    'product_id' => $skuId,
                ]);
            }
        }
    }

    /** Copy đúng shape ghi trong PvAdminResourceController::storeRule(). */
    private function seedPvRecommendationRules(): void
    {
        // FK industry_id trỏ pv_industries (SỐ NHIỀU — mirror của pv_industry),
        // không phải pv_industry (số ít, bảng ghi). Không được lẫn lộn 2 cái.
        $industryIds = DB::table('pv_industries')->limit(5)->pluck('id');
        $skuIds = Sku::query()->where('sku_type', 'PRODUCT')->orderBy('sku_code')->limit(5)->pluck('sku_id')->values();
        if ($industryIds->isEmpty() || $skuIds->isEmpty()) {
            return;
        }

        // WWHN-252/PvRelations: bảng ghi được khác nhau theo driver — plural là
        // bảng thật trên PostgreSQL nhưng chỉ là VIEW trên sqlite (insert sẽ nổ).
        $rule = PvRelations::rule();
        foreach ($industryIds as $index => $industryId) {
            $skuId = $skuIds[$index % $skuIds->count()];
            $exists = DB::table($rule['table'])
                ->where('industry_id', $industryId)
                ->where($rule['sku'], $skuId)
                ->exists();
            if ($exists) {
                continue;
            }

            $row = [
                $rule['key'] => (string) Str::uuid(),
                'industry_id' => $industryId,
                $rule['sku'] => $skuId,
                'category_id' => null,
                'tag_id' => null,
                'priority' => $index + 1,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (PvRelations::isPgsql()) {
                $row['version'] = 1;
            }
            DB::table($rule['table'])->insert($row);
        }
    }

    /**
     * pv_product_images: bảng ghi trực tiếp (không phải mirror).
     * pv_product_industry: bảng ghi trực tiếp (số ít) -> trigger tự đồng bộ
     * sang pv_product_industries (số nhiều), KHÔNG ghi tay vào bảng số nhiều.
     * pv_product_tags: bảng ghi trực tiếp (số nhiều — trên Postgres đây mới
     * là bảng thật, khác pattern industry).
     */
    private function seedPvProductImagesTagsIndustries(): void
    {
        // Ảnh/nhãn/ngành nghề của App PV nay do DongChengCatalogSeeder nạp từ
        // catalog thật (972 ảnh trên images.bigk.click). Bước bù "cho đủ 5 dòng"
        // dưới đây chỉ còn ý nghĩa khi các bảng đó rỗng — ví dụ một CSDL dựng
        // riêng cho test không chạy catalog seeder. Có dữ liệu thật rồi thì bỏ
        // qua, nếu không mỗi lần seed lại chèn thêm 5 ảnh cdn.example.com giả
        // vào giữa ảnh thật.
        if (DB::table('pv_product_images')->exists()) {
            return;
        }

        $profileIds = DB::table('pv_product_profiles')->limit(5)->pluck('id');
        $industryIds = DB::table('pv_industry')->limit(5)->pluck('industry_id');
        $tagIds = DB::table('pv_filter_tag')->limit(5)->pluck('tag_id');

        foreach ($profileIds as $index => $profileId) {
            $imageUrl = 'https://cdn.example.com/pv/seed-'.sprintf('%03d', $index + 1).'.jpg';
            if (! DB::table('pv_product_images')->where('profile_id', $profileId)->where('image_url', $imageUrl)->exists()) {
                DB::table('pv_product_images')->insert([
                    'id' => (string) Str::uuid(),
                    'profile_id' => $profileId,
                    'image_url' => $imageUrl,
                    'storage_url' => $imageUrl,
                    // Chỉ 1 ảnh primary ACTIVE / profile (partial unique index) -> luôn false ở đây.
                    'is_primary' => false,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'created_by' => $this->actorId,
                    'updated_at' => now(),
                    'updated_by' => $this->actorId,
                    'version' => 0,
                    'alt_text' => 'Ảnh sản phẩm demo '.($index + 1),
                    'status' => 'ACTIVE',
                ]);
            }

            if ($industryIds->isNotEmpty()) {
                $industryId = $industryIds[$index % $industryIds->count()];
                $existsIndustry = DB::table('pv_product_industry')
                    ->where('profile_id', $profileId)
                    ->where('industry_id', $industryId)
                    ->exists();
                if (! $existsIndustry) {
                    DB::table('pv_product_industry')->insert([
                        'profile_id' => $profileId,
                        'industry_id' => $industryId,
                        'priority' => 1,
                        'created_at' => now(),
                    ]);
                }
            }

            if ($tagIds->isNotEmpty()) {
                $tagId = $tagIds[$index % $tagIds->count()];
                // PvRelations::productTagTable(): plural là bảng thật trên PostgreSQL,
                // nhưng trên sqlite plural chỉ là VIEW — bảng ghi là số ít.
                $productTagTable = PvRelations::productTagTable();
                $existsTag = DB::table($productTagTable)
                    ->where('profile_id', $profileId)
                    ->where('tag_id', $tagId)
                    ->exists();
                if (! $existsTag) {
                    DB::table($productTagTable)->insert([
                        'profile_id' => $profileId,
                        'tag_id' => $tagId,
                        'created_at' => now(),
                    ] + (PvRelations::isPgsql() ? ['created_by' => $this->actorId] : []));
                }
            }
        }
    }

    /**
     * putaway_task/putaway_task_line: KHÔNG có controller/service/model nào
     * ghi vào 2 bảng này trong toàn bộ codebase — luồng putaway thật dùng
     * putaway_events (xem WarehouseBusinessDataSeeder::confirmPutaway/
     * seedPutawayVariant). Đây là placeholder thuần theo yêu cầu "mọi bảng
     * ≥5 dòng"; status/note đánh dấu rõ ràng để không nhầm là dữ liệu
     * nghiệp vụ đang được luồng thật nào đó tiêu thụ.
     */
    private function seedPutawayTaskLegacyPlaceholder(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $taskNo = 'LEGACY-PLACEHOLDER-TASK-'.sprintf('%03d', $i);
            $existingTaskId = DB::table('putaway_task')->where('task_no', $taskNo)->value('putaway_task_id');
            if ($existingTaskId !== null) {
                continue;
            }

            $taskId = (string) Str::uuid();
            DB::table('putaway_task')->insert([
                'putaway_task_id' => $taskId,
                'task_no' => $taskNo,
                'stock_document_id' => null,
                'warehouse_id' => $this->warehouseId,
                'status' => 'CLOSED',
                'assigned_to' => null,
                'created_by' => $this->actorId,
                'completed_at' => now(),
                'version' => 1,
            ]);

            DB::table('putaway_task_line')->insert([
                'putaway_task_line_id' => (string) Str::uuid(),
                'putaway_task_id' => $taskId,
                'container_id' => null,
                'product_id' => null,
                'target_container_id' => null,
                'quantity' => null,
                'target_location_hint' => null,
                'status' => 'CLOSED',
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Group B — bảng hệ thống/runtime (seed ở trạng thái đã hoàn tất)
    // ------------------------------------------------------------------

    /** Không có "held vs released" state ở tầng schema — mọi row luôn an toàn (xem Wwhn172AggregateLock). */
    private function seedAggregateMutexes(): void
    {
        $keys = [
            'wwhn172:sku:seed-demo-001',
            'wwhn172:sku:seed-demo-002',
            'wwhn172:sku:seed-demo-003',
            'wwhn172:warehouse:seed-demo-001',
            'wwhn172:warranty:seed-demo-001',
        ];

        foreach ($keys as $key) {
            DB::table('aggregate_mutexes')->insertOrIgnore([
                'mutex_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * idempotency_records được đồng bộ TỰ ĐỘNG từ idempotency_keys qua trigger
     * AFTER INSERT hn_erd_mirror_idempotency_records — chỉ ghi vào
     * idempotency_keys, không ghi tay vào bảng mirror để tránh lệch dữ liệu.
     * Seed ở status='done' (terminal, đã replay xong), không phải 'processing'
     * (đang giữ lease) để không giả lập một request đang treo.
     */
    private function seedIdempotencyKeys(): void
    {
        $operations = [
            'stock.receipt.confirm',
            'stock.issue.confirm',
            'component_issue.post',
            'reversal.post',
            'putaway.confirm',
        ];

        foreach ($operations as $index => $operation) {
            $idemKey = 'seed-idem-'.sprintf('%03d', $index + 1);
            $businessKey = sha1($operation.'|'.$this->actorId.'|'.$idemKey);
            if (DB::table('idempotency_keys')->where('business_key', $businessKey)->exists()) {
                continue;
            }

            DB::table('idempotency_keys')->insert([
                'idem_key' => $idemKey,
                'user_id' => $this->actorId,
                'operation' => $operation,
                'business_key' => $businessKey,
                'request_hash' => 'v2:'.hash('sha256', $idemKey),
                'status' => 'done',
                'response_code' => 200,
                'response_body' => json_encode(['success' => true, 'message' => 'Phát lại dữ liệu mẫu', 'data' => []], JSON_UNESCAPED_UNICODE),
                'locked_until' => null,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * BEFORE INSERT trigger hn_erd_guard_migration_commit_evidence chặn
     * status COMMITTED/PASSED nếu reconciliation_status != 'PASS' — seed 4
     * run COMMITTED hợp lệ (kèm reconciliation_status=PASS) + 1 run FAILED để
     * gắn migration_errors vào, giữ dữ liệu nhất quán logic (run PASS không
     * nên còn error đính kèm).
     */
    private function seedMigrationRunsAndErrors(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $code = 'SEED-RUN-'.sprintf('%03d', $i).'-COMMIT';
            if (DB::table('migration_runs')->where('migration_run_code', $code)->exists()) {
                continue;
            }

            DB::table('migration_runs')->insert([
                'id' => (string) Str::uuid(),
                'migration_run_code' => $code,
                'package_version' => '1.0.'.$i,
                'source_file_name' => 'seed-package-'.$i.'.csv',
                'source_checksum' => hash('sha256', 'seed-package-'.$i),
                'mode' => 'COMMIT',
                'status' => 'COMMITTED',
                'total_rows' => 5,
                'valid_rows' => 5,
                'error_rows' => 0,
                'duplicate_rows' => 0,
                'reconciliation_status' => 'PASS',
                'reconciliation_result' => json_encode(['status' => 'PASS'], JSON_UNESCAPED_UNICODE),
                'started_at' => now()->subMinutes(10 + $i),
                'finished_at' => now()->subMinutes($i),
                'executed_by' => $this->actorId,
                'created_at' => now()->subMinutes(10 + $i),
            ]);
        }

        $failedCode = 'SEED-RUN-005-FAILED';
        $failedRunId = DB::table('migration_runs')->where('migration_run_code', $failedCode)->value('id');
        if ($failedRunId === null) {
            $failedRunId = (string) Str::uuid();
            DB::table('migration_runs')->insert([
                'id' => $failedRunId,
                'migration_run_code' => $failedCode,
                'package_version' => '1.0.5',
                'source_file_name' => 'seed-package-5.csv',
                'source_checksum' => hash('sha256', 'seed-package-5'),
                'mode' => 'DRY_RUN',
                'status' => 'FAILED',
                'total_rows' => 5,
                'valid_rows' => 3,
                'error_rows' => 2,
                'duplicate_rows' => 0,
                'reconciliation_status' => 'FAIL',
                'reconciliation_result' => json_encode(['status' => 'FAIL'], JSON_UNESCAPED_UNICODE),
                'started_at' => now()->subMinutes(20),
                'finished_at' => now()->subMinutes(15),
                'executed_by' => $this->actorId,
                'created_at' => now()->subMinutes(20),
            ]);
        }

        $errorRows = [
            ['dataset' => 'opening_balances', 'row_no' => 3, 'field_name' => 'quantity', 'error_code' => 'INVALID_QUANTITY', 'severity' => 'ERROR', 'message' => 'Dòng lỗi dữ liệu mẫu số 1.'],
            ['dataset' => 'opening_balances', 'row_no' => 4, 'field_name' => 'sku_code', 'error_code' => 'SKU_NOT_FOUND', 'severity' => 'CRITICAL', 'message' => 'Dòng lỗi dữ liệu mẫu số 2.'],
            ['dataset' => 'agencies', 'row_no' => 1, 'field_name' => 'phone', 'error_code' => 'INVALID_FORMAT', 'severity' => 'WARNING', 'message' => 'Dòng lỗi dữ liệu mẫu số 3.'],
            ['dataset' => 'agencies', 'row_no' => 2, 'field_name' => null, 'error_code' => 'DUPLICATE_ROW', 'severity' => 'WARNING', 'message' => 'Dòng lỗi dữ liệu mẫu số 4.'],
            ['dataset' => 'warehouses', 'row_no' => 1, 'field_name' => 'code', 'error_code' => 'DUPLICATE_CODE', 'severity' => 'ERROR', 'message' => 'Dòng lỗi dữ liệu mẫu số 5.'],
        ];

        foreach ($errorRows as $row) {
            $exists = DB::table('migration_errors')
                ->where('migration_run_id', $failedRunId)
                ->where('dataset', $row['dataset'])
                ->where('row_no', $row['row_no'])
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('migration_errors')->insert([
                'id' => (string) Str::uuid(),
                'migration_run_id' => $failedRunId,
                'dataset' => $row['dataset'],
                'row_no' => $row['row_no'],
                'field_name' => $row['field_name'],
                'error_code' => $row['error_code'],
                'severity' => $row['severity'],
                'message' => $row['message'],
                'raw_value_masked' => '***',
                'suggested_action' => 'Xem lại dòng nguồn.',
                'created_at' => now()->subMinutes(18),
            ]);
        }
    }

    /**
     * Claim đã hết hạn (expires_at trong quá khứ) — đại diện trạng thái ổn
     * định thật (không phải lock import đang giữ sống).
     */
    private function seedSkuImportClaims(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $hash = hash('sha256', 'seed-sku-import-claim-'.$i);
            if (DB::table('sku_import_claims')->where('identity_hash', $hash)->exists()) {
                continue;
            }

            DB::table('sku_import_claims')->insertOrIgnore([
                'identity_hash' => $hash,
                'owner_token' => (string) Str::uuid(),
                'actor_id' => $this->actorId,
                'file_sha256' => hash('sha256', 'seed-sku-import-file-'.$i),
                'options_fingerprint' => hash('sha256', 'update_existing=false-'.$i),
                'request_id' => (string) Str::uuid(),
                'claimed_at' => now()->subMinutes(30 + $i),
                'expires_at' => now()->subMinutes(20 + $i),
            ]);
        }
    }

    /**
     * audit:reconcile chạy mỗi 5 phút và nhặt mọi row processed_at IS NULL —
     * seed ở trạng thái ĐÃ XỬ LÝ (processed_at đã set) để không tạo phantom
     * failure log khi consumer thật chạy trên môi trường dev.
     */
    private function seedAuditOutbox(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $objectId = (string) Str::uuid();
            $requestId = 'seed-audit-outbox-'.sprintf('%03d', $i);
            if (DB::table('audit_outbox')->whereRaw("payload->>'request_id' = ?", [$requestId])->exists()) {
                continue;
            }

            $createdAt = now()->subMinutes(60 - $i);
            $processedAt = $createdAt->copy()->addMinute();

            DB::table('audit_outbox')->insert([
                'audit_outbox_id' => (string) Str::uuid(),
                'payload' => json_encode([
                    'actor_id' => $this->actorId,
                    'action' => 'SEED_DEMO_EVENT',
                    'object_type' => 'seed_demo',
                    'object_id' => $objectId,
                    'reason' => null,
                    'request_id' => $requestId,
                    'idempotency_key' => null,
                    'result' => 'SUCCESS',
                    'error_code' => null,
                    'before_data' => null,
                    'after_data' => null,
                    'created_at' => $createdAt->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
                'attempts' => 0,
                'last_error' => null,
                'available_at' => $createdAt,
                'processed_at' => $processedAt,
                'created_at' => $createdAt,
                'updated_at' => $processedAt,
            ]);
        }
    }

    private function seedRefreshTokens(): void
    {
        $userIds = DB::table('app_user')->limit(5)->pluck('user_id');

        foreach ($userIds as $index => $userId) {
            $tokenHash = hash('sha256', 'seed-refresh-token-'.$index);
            if (DB::table('refresh_tokens')->where('token_hash', $tokenHash)->exists()) {
                continue;
            }

            DB::table('refresh_tokens')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'user_agent' => 'seed-agent/1.0',
                'ip_address' => '127.0.0.1',
                'expires_at' => now()->addDays(30),
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
