import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const [outputPath = 'build/evidence/api-data/stock-writer-boundary.json'] = process.argv.slice(2);
const sources = {
  report_controller: 'Modules/Warehouse/app/Http/Controllers/ReportController.php',
  snapshot_controller: 'Modules/Warehouse/app/Http/Controllers/InventorySnapshotController.php',
  snapshot_service: 'Modules/Warehouse/app/Services/InventorySnapshotService.php',
  snapshot_model: 'Modules/Warehouse/app/Models/InventoryReportSnapshot.php',
  warranty_controller: 'Modules/Warehouse/app/Http/Controllers/WarrantyController.php',
  warranty_case: 'Modules/Warehouse/app/Models/WarrantyCase.php',
  warranty_event: 'Modules/Warehouse/app/Models/WarrantyEvent.php',
  pv_schema: 'Modules/Warehouse/database/migrations/2026_01_01_000044_create_hoanam_erd_extension_tables.php',
  pv_seed: 'Modules/Warehouse/database/seeders/WarehouseDatabaseSeeder.php',
};

const loaded = Object.fromEntries(Object.entries(sources).map(([key, path]) => {
  const content = fs.readFileSync(path, 'utf8');
  return [key, { path, content, sha256: crypto.createHash('sha256').update(content).digest('hex') }];
}));
const reportText = `${loaded.report_controller.content}\n${loaded.snapshot_controller.content}`;
const warrantyText = `${loaded.warranty_controller.content}\n${loaded.warranty_case.content}\n${loaded.warranty_event.content}`;
const mutationPattern = /(?:->|::)(?:insert|insertGetId|update|delete|create|increment|decrement|upsert|updateOrCreate)\s*\(/i;
const canonicalStockPattern = /inventory_balances|stock_movements|posting_event|InventoryBalance|StockMovement/;
const listPhpFiles = (directory) => fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
  const entryPath = path.join(directory, entry.name);
  if (entry.isDirectory()) return listPhpFiles(entryPath);
  return entry.isFile() && entry.name.endsWith('.php') ? [entryPath.replaceAll('\\', '/')] : [];
});
const pvRuntimeSources = listPhpFiles('Modules/Warehouse/app').filter((sourcePath) =>
  /\bpv_(?:product|industry|filter|recommendation|view)/i.test(fs.readFileSync(sourcePath, 'utf8'))
);
const pvProfileSchema = loaded.pv_schema.content.match(
  /Schema::create\(['"]pv_product_profile['"][\s\S]*?\n\s*\}\);/,
)?.[0] ?? '';

const checks = {
  report_reads_canonical_sources: /inventory_balances/.test(reportText) && /stock_movements/.test(reportText),
  report_has_no_mutation: !mutationPattern.test(reportText),
  warranty_has_no_canonical_stock_access: !canonicalStockPattern.test(warrantyText),
  snapshot_is_derived_from_ledger: /stock_movements/.test(loaded.snapshot_service.content)
    && /InventoryReportSnapshot/.test(loaded.snapshot_service.content),
  snapshot_model_is_noncanonical_read_model: /inventory_report_snapshots/.test(loaded.snapshot_model.content)
    && !canonicalStockPattern.test(loaded.snapshot_model.content),
  snapshot_does_not_mutate_canonical_stock: !/InventoryBalance|StockMovement::|posting_event/.test(loaded.snapshot_service.content)
    && !/DB::table\(['"](?:inventory_balances|stock_movements|posting_event)['"]\)[\s\S]{0,500}(?:insert|update|delete|upsert)\s*\(/i
      .test(loaded.snapshot_service.content),
  pv_schema_is_catalog_only: pvProfileSchema !== ''
    && !/on_hand|available_qty|reserved_qty|qty_delta|stock_movements|posting_event|inventory_balances/i
      .test(pvProfileSchema),
  pv_has_no_runtime_handler: pvRuntimeSources.length === 0,
};
const failed = Object.entries(checks).filter(([, passed]) => !passed).map(([name]) => name);
const output = {
  schema_version: '1.0.0',
  contract: 'WWHN-150 BE1/BE2 stock writer boundary',
  canonical_stock_sources: ['inventory_balances', 'stock_movements', 'posting_event'],
  derived_read_models: [{
    name: 'inventory_report_snapshots',
    source: 'stock_movements',
    rebuildable: true,
    may_post_back_to_canonical_stock: false,
    owner: 'BE1 reporting boundary; BE2 independent confirmation pending',
  }],
  warranty_boundary: {
    tables: ['warranty_case', 'warranty_event'],
    stock_effect: 'NONE for TEMP_ONLY',
  },
  pv_boundary: {
    catalog_table: 'pv_product_profile',
    runtime_handler_count: pvRuntimeSources.length,
    runtime_sources: pvRuntimeSources,
    status: pvRuntimeSources.length === 0 ? 'NO_RUNTIME_HANDLER_REGISTERED' : 'REVIEW_REQUIRED',
    rule: 'PV catalog metadata may reference a product, but may not store quantity or mutate/mirror canonical stock.',
  },
  sources: Object.fromEntries(Object.entries(loaded).map(([key, value]) => [key, {
    path: value.path,
    sha256: value.sha256,
  }])),
  checks,
  summary: {
    checked: Object.keys(checks).length,
    passed: Object.keys(checks).length - failed.length,
    failed: failed.length,
    status: failed.length === 0 ? 'PASS' : 'FAIL',
  },
  failed_checks: failed,
};

fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);
if (failed.length > 0) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}
console.log(`WWHN-150 boundary verification PASS: ${output.summary.passed}/${output.summary.checked}`);
