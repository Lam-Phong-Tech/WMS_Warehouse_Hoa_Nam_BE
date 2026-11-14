<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\SkuImportContract;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_source_files', function (Blueprint $table): void {
            $table->uuid('source_file_id')->primary();
            $table->string('identity_hash', 64)->unique();
            $table->uuid('actor_id')->nullable();
            $table->string('uploaded_by_name')->nullable();
            $table->string('uploaded_by_account')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_sha256', 64);
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('file_path')->nullable();
            $table->timestampTz('uploaded_at')->nullable();
            $table->unsignedInteger('next_version')->default(1);
        });
        Schema::table('import_reports', function (Blueprint $table): void {
            $table->uuid('source_file_id')->nullable()->index();
            $table->unsignedInteger('result_version')->nullable();
            $table->string('sheet_name')->nullable();
            $table->boolean('update_existing')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedBigInteger('processing_duration_ms')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_account')->nullable();
            $table->unique(['source_file_id', 'result_version'], 'import_report_source_version_unique');
        });
        // Metadata that was never captured (bytes, size, processing times) stays unknown.
        foreach (DB::table('import_reports')->where('import_type', 'SKU')->orderBy('created_at')->orderBy('import_report_id')->cursor() as $report) {
            $identity = hash('sha256', ($report->actor_id ?? '').'|'.$report->file_sha256);
            $source = DB::table('import_source_files')->where('identity_hash', $identity)->first();
            if ($source === null) {
                $sourceId = (string) Str::uuid();
                $actor = $report->actor_id ? DB::table('app_user')->where('user_id', $report->actor_id)->first() : null;
                DB::table('import_source_files')->insert([
                    'source_file_id' => $sourceId, 'identity_hash' => $identity,
                    'actor_id' => $report->actor_id, 'file_name' => $report->file_name,
                    'file_sha256' => $report->file_sha256, 'uploaded_at' => null,
                    'uploaded_by_name' => $actor->display_name ?? null,
                    'uploaded_by_account' => $actor->username ?? null, 'next_version' => 1,
                ]);
                $source = DB::table('import_source_files')->where('source_file_id', $sourceId)->first();
            }
            DB::table('import_reports')->where('import_report_id', $report->import_report_id)->update([
                'source_file_id' => $source->source_file_id, 'result_version' => $source->next_version,
                'update_existing' => match ($report->options_fingerprint ?? null) {
                    SkuImportContract::optionsFingerprint(true) => true,
                    SkuImportContract::optionsFingerprint(false) => false,
                    default => null,
                },
            ]);
            DB::table('import_source_files')->where('source_file_id', $source->source_file_id)->increment('next_version');
        }
    }

    public function down(): void
    {
        Schema::table('import_reports', function (Blueprint $table): void {
            $table->dropUnique('import_report_source_version_unique');
            $table->dropIndex('import_reports_source_file_id_index');
            $table->dropColumn(['source_file_id', 'result_version', 'sheet_name', 'update_existing', 'started_at', 'completed_at', 'processing_duration_ms', 'actor_name', 'actor_account']);
        });
        Schema::dropIfExists('import_source_files');
    }
};
