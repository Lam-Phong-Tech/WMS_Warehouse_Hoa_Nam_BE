<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Support\UiuxV2OperationalScenario;
use RuntimeException;

/** Additive, date-scoped UI fixtures. Run explicitly; never part of DatabaseSeeder. */
final class UiuxV2ScenarioSeeder extends Seeder
{
    public array $manifest = [];

    public function run(): void
    {
        if (! config('uiux_v2_seed.enabled', false)) {
            throw new RuntimeException('UIUX_V2_SEED_EXPLICIT_OPT_IN_REQUIRED');
        }
        if (! in_array(app()->environment(), ['local', 'testing', 'dev', 'development', 'uat', 'demo'], true)) {
            throw new RuntimeException('UIUX_V2_SEED_ENVIRONMENT_NOT_ALLOWED');
        }
        if (config('deployment_safety.protect_database', true) && ! config('deployment_safety.allow_protected_database_seed', false)) {
            throw new RuntimeException('UIUX_V2_SEED_PROTECTED_DATABASE');
        }
        $dateValue = (string) (config('uiux_v2_seed.date') ?: Carbon::now('Asia/Ho_Chi_Minh')->toDateString());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateValue)) {
            throw new RuntimeException('UIUX_V2_SEED_INVALID_DATE');
        }
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $dateValue, 'Asia/Ho_Chi_Minh');
            if ($date === false || $date->toDateString() !== $dateValue) {
                throw new RuntimeException('UIUX_V2_SEED_INVALID_DATE');
            }
        } catch (\Throwable $error) {
            throw new RuntimeException('UIUX_V2_SEED_INVALID_DATE', 0, $error);
        }
        $actorId = (string) config('uiux_v2_seed.actor_id');
        if (! Str::isUuid($actorId) || ! User::whereKey($actorId)->where('status', 'ACTIVE')->exists()) {
            throw new RuntimeException('UIUX_V2_SEED_ACTIVE_ACTOR_REQUIRED');
        }
        $clock = Carbon::getTestNow();
        $operations = app(UiuxV2OperationalScenario::class);
        $this->manifest = [];
        try {
            $this->manifest = DB::transaction(function () use ($date, $actorId, $operations): array {
                if (DB::getDriverName() === 'pgsql') {
                    // Across all dates: shared fixture masters must not race either.
                    $lock = DB::selectOne("SELECT pg_try_advisory_xact_lock(hashtext('hoa-nam:uiux-v2-scenario:v1')) AS acquired");
                    if (! in_array($lock->acquired, [true, 1, '1', 't'], true)) {
                        throw new RuntimeException('UIUX_V2_SEED_BUSY');
                    }
                }
                Carbon::setTestNow($date->copy()->setTime(9, 0));
                $imports = $this->imports($date, $actorId);
                $graph = $operations->run($date->copy(), $actorId);

                return ['schema' => 'uiux-v2-scenario-v1', 'date' => $date->toDateString(),
                    'actor_id' => $actorId, 'import_report_ids' => $imports, 'operations' => $graph];
            });
            if (DB::transactionLevel() === 0 && method_exists($operations, 'releaseCreatedFiles')) {
                $operations->releaseCreatedFiles();
            }
        } catch (\Throwable $error) {
            if (method_exists($operations, 'cleanupCreatedFiles')) {
                $operations->cleanupCreatedFiles();
            }
            throw $error;
        } finally {
            Carbon::setTestNow($clock);
        }
        $this->command?->info(json_encode($this->manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function imports(Carbon $date, string $actorId): array
    {
        $rules = [
            ['SKU_CODE_DUPLICATE', 'sku_code', 'Mã SKU đã tồn tại.', 'UIUXV2-EXISTING-SKU'],
            ['MISSING_REQUIRED_FIELD', 'sku_name', 'Thiếu tên SKU.', ''],
            ['INVALID_CATEGORY', 'category_code', 'Nhóm hàng không tồn tại.', 'UIUXV2-UNKNOWN-CATEGORY'],
            ['INVALID_BRAND', 'brand_code', 'Hãng không tồn tại.', 'UIUXV2-UNKNOWN-BRAND'],
            ['INVALID_UNIT', 'unit', 'Đơn vị tính không hợp lệ.', 'UIUXV2-INVALID-UNIT'],
        ];
        $errors = [];
        for ($i = 0; $i < 125; $i++) {
            [$code, $field, $message, $value] = $rules[$i % count($rules)];
            $errors[] = ['row' => $i + 2, 'field' => $field, 'code' => $code,
                'message' => $message, 'value' => $value];
        }
        $plans = [
            'committed' => ['COMMITTED', 'COMMIT', 12, 12, 12, []],
            'valid' => ['VALID', 'DRY_RUN', 12, 12, 0, []],
            'duplicate' => ['REJECTED', 'DRY_RUN', 1, 0, 0, [$errors[0]]],
            'errors' => ['REJECTED', 'DRY_RUN', 130, 5, 0, $errors],
        ];
        $ids = [];
        foreach ($plans as $name => [$status, $mode, $total, $valid, $inserted, $rowErrors]) {
            $requestId = 'UIUXV2-'.$date->format('Ymd').'-import-'.$name;
            $values = ['import_type' => 'SKU', 'mode' => $mode, 'status' => $status,
                'file_name' => $requestId.'.xlsx', 'file_sha256' => hash('sha256', $requestId),
                'template_version' => 'v1', 'total_rows' => $total, 'valid_rows' => $valid,
                'error_rows' => count($rowErrors), 'inserted_rows' => $inserted, 'updated_rows' => 0,
                'row_errors' => json_encode($rowErrors, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'actor_id' => $actorId, 'options_fingerprint' => hash('sha256', 'uiux-v2-scenario-v1:'.$name)];
            $existing = DB::table('import_reports')->where('request_id', $requestId)->first();
            if ($existing !== null) {
                foreach ($values as $key => $value) {
                    $matches = $key === 'row_errors'
                        ? json_decode($existing->$key, true) == json_decode($value, true)
                        : (string) $existing->$key === (string) $value;
                    if (! $matches) {
                        throw new RuntimeException('UIUX_V2_SEED_IMPORT_COLLISION: '.$requestId);
                    }
                }
                $ids[$name] = $existing->import_report_id;

                continue;
            }
            $id = (string) Str::uuid();
            DB::table('import_reports')->insert($values + ['import_report_id' => $id, 'request_id' => $requestId,
                'created_at' => now(), 'updated_at' => now()]);
            $ids[$name] = $id;
        }

        return $ids;
    }
}
