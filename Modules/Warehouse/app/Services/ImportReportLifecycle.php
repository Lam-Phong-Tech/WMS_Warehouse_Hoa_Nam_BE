<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Models\ImportSourceFile;
use Modules\Warehouse\Support\SkuImportContract;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportReportLifecycle
{
    public function __construct(private readonly ImportService $imports, private readonly ImportErrorFileService $errorFiles) {}

    public function recheck(ImportReport $report, ?string $actorId, ?string $requestId): ImportReport
    {
        $source = $report->sourceFile;
        if ($source === null || ! $source->available()) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không còn file gốc để kiểm tra lại.');
        }
        // Legacy reports predate the explicit option column. Recover only known fingerprints.
        $updateExisting = $report->update_existing;
        if ($updateExisting === null) {
            $updateExisting = match ($report->options_fingerprint) {
                SkuImportContract::optionsFingerprint(true) => true,
                SkuImportContract::optionsFingerprint(false) => false,
                default => null,
            };
        }
        if ($updateExisting === null) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Lần nhập cũ chưa lưu tùy chọn cập nhật; hãy tải file lên để kiểm tra mới.');
        }
        $extension = strtolower(pathinfo($source->file_path, PATHINFO_EXTENSION));
        if (! in_array($extension, SkuImportContract::allowedExtensions(), true)) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Định dạng file gốc không được hỗ trợ.');
        }
        $base = tempnam(sys_get_temp_dir(), 'sku-recheck-');
        if ($base === false) {
            throw new \RuntimeException('Cannot create recheck temporary file.');
        }
        $path = $base.'.'.$extension;
        $input = null;
        $output = null;
        try {
            $input = Storage::disk(ImportSourceFile::diskName())->readStream($source->file_path);
            $output = fopen($path, 'wb');
            if (! is_resource($input) || ! is_resource($output) || stream_copy_to_stream($input, $output) === false) {
                throw new \RuntimeException('Cannot read retained source file.');
            }
            fclose($output);
            $output = null;
            if (! hash_equals($source->file_sha256, hash_file('sha256', $path))) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Nội dung file gốc không khớp lần tải lên.');
            }
            $result = $this->imports->import($path, $updateExisting, $actorId, $requestId, $report->file_name, true, (string) $source->getKey());
            $created = ImportReport::findOrFail($result['import_report_id']);
            if (($result['row_errors'] ?? []) !== []) {
                try {
                    $generated = $this->errorFiles->generate((string) $created->getKey(), $result['row_errors'], (string) $report->file_name, $result['sheet_name'] ?? null);
                    if ($generated !== null) {
                        $created->forceFill(['error_file_path' => $generated['path'], 'error_file_expires_at' => $generated['expires_at']])->save();
                    }
                } catch (Throwable $e) {
                    Log::warning('IMPORT_RECHECK_ERROR_FILE_UNAVAILABLE', ['import_report_id' => $created->getKey(), 'exception' => $e::class]);
                }
            }

            return $created;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($path)) {
                unlink($path);
            }
            if (is_file($base)) {
                unlink($base);
            }
        }
    }

    public function sourceFile(ImportReport $report): StreamedResponse
    {
        $source = $report->sourceFile;
        if ($source === null || ! $source->available()) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không còn file gốc để tải.');
        }

        return Storage::disk(ImportSourceFile::diskName())->download($source->file_path, basename($report->file_name ?: 'sku-import.xlsx'), ['Content-Type' => 'application/octet-stream']);
    }

    public function resultFile(ImportReport $report): StreamedResponse
    {
        $data = $report->toArray();
        $rows = [
            ['Trường', 'Giá trị'],
            ['Mã lần nhập', $report->getKey()], ['Tên file', $report->file_name],
            ['Phiên bản kết quả', $report->result_version], ['Phiên bản mẫu', $report->template_version],
            ['Người tải lên', $data['uploaded_by_name']], ['Tài khoản tải lên', $data['uploaded_by_account']],
            ['Thời gian tải lên', $data['uploaded_at']], ['Dung lượng (byte)', $data['file_size_bytes']],
            ['SHA-256', $report->file_sha256], ['Sheet', $report->sheet_name],
            ['Người kiểm tra', $report->actor_name], ['Tài khoản kiểm tra', $report->actor_account],
            ['Thời điểm tạo kết quả', $report->created_at?->toIso8601String()],
            ['Bắt đầu', $report->started_at?->toIso8601String()], ['Hoàn tất', $report->completed_at?->toIso8601String()],
            ['Thời lượng (ms)', $report->processing_duration_ms], ['Chế độ', $report->mode], ['Trạng thái', $report->status],
            ['Cho phép cập nhật', $report->update_existing === null ? null : ($report->update_existing ? 'Có' : 'Không')],
            ['Tổng dòng dữ liệu (không tính tiêu đề và dòng trống)', $report->total_rows],
            ['Dòng hợp lệ', $report->row_counts['valid']], ['Dòng trùng', $report->row_counts['duplicate']],
            ['Dòng lỗi dữ liệu', $report->row_counts['invalid']], ['Số lỗi theo trường', $report->error_rows],
            ['Đã thêm mới', $data['actual_inserted_rows']], ['Đã cập nhật', $data['actual_updated_rows']],
            ['Không ghi', $data['not_written_rows']], ['Dự kiến thêm mới', $data['planned_inserted_rows']], ['Dự kiến cập nhật', $data['planned_updated_rows']],
        ];
        $errors = $this->errorFiles->previewRows(is_array($report->row_errors) ? $report->row_errors : []);

        return response()->streamDownload(function () use ($rows, $errors): void {
            $book = new Spreadsheet;
            $book->getActiveSheet()->setTitle('Kết quả đã lưu')->fromArray(array_map(fn ($row) => array_map($this->safeCell(...), $row), $rows));
            $issues = $book->createSheet()->setTitle('Dòng cần kiểm tra');
            $issues->fromArray(['Dòng', 'Trường', 'Mã lỗi', 'Nội dung', 'Giá trị', 'Mức độ', 'Hướng xử lý'], null, 'A1');
            foreach ($errors as $index => $error) {
                $issues->fromArray(array_map($this->safeCell(...), [$error['row_no'], $error['field'], $error['error_code'], $error['message'], $error['raw_value'], $error['severity'], $error['suggested_action']]), null, 'A'.($index + 2));
            }
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, 'sku-import-'.$report->getKey().'-v'.($report->result_version ?? 'legacy').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
