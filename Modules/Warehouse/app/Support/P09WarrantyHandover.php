<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\Security\PiiCrypto;
use App\Support\WarrantyPiiMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class P09WarrantyHandover
{
    public const CONTRACT = 'p09-warranty-handover-v1';

    public const TABLE = 'warranty_handovers';

    private const RECEIVER_CONTEXT = 'warranty_handovers.receiver';

    private const NOTE_CONTEXT = 'warranty_handovers.note';

    public static function store(string $caseId, array $input, string $actorId, string $requestId): void
    {
        self::requireSchema();
        DB::table(self::TABLE)->updateOrInsert(['warranty_case_id' => $caseId], [
            'receiver_ciphertext' => PiiCrypto::encrypt($input['receiver'], self::RECEIVER_CONTEXT),
            'handover_date' => $input['handover_date'],
            'handover_checked' => true,
            'note_ciphertext' => PiiCrypto::encrypt($input['note'], self::NOTE_CONTEXT),
            'recorded_by' => $actorId,
            'request_id' => $requestId,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    public static function read(string $caseId, bool $canViewPii, mixed $returnedAt): ?array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }
        $row = DB::table(self::TABLE)->where('warranty_case_id', $caseId)->first();
        if ($row === null) {
            return null;
        }
        try {
            $receiver = PiiCrypto::decrypt($row->receiver_ciphertext, self::RECEIVER_CONTEXT);
            $note = PiiCrypto::decrypt($row->note_ciphertext, self::NOTE_CONTEXT);
        } catch (\Throwable) {
            ApiError::abort(503, 'WARRANTY_HANDOVER_DATA_UNAVAILABLE', 'Dữ liệu bàn giao không thể xác minh an toàn.');
        }

        return [
            'contract' => self::CONTRACT,
            'receiver' => $canViewPii ? $receiver : WarrantyPiiMasker::name((string) $receiver),
            'receiver_masked' => ! $canViewPii,
            'handover_date' => $row->handover_date,
            'handover_checked' => (bool) $row->handover_checked,
            'note' => $canViewPii ? $note : null,
            'note_redacted' => ! $canViewPii,
            'returned_at' => $returnedAt,
            'returned_at_source' => 'warranty_case.returned_at_server_time',
            'recorded_at' => $row->created_at,
        ];
    }

    public static function requireSchema(): void
    {
        if (! Schema::hasTable(self::TABLE)
            || array_diff(['warranty_case_id', 'receiver_ciphertext', 'handover_date', 'handover_checked',
                'note_ciphertext', 'recorded_by', 'request_id'], Schema::getColumnListing(self::TABLE)) !== []) {
            ApiError::abort(503, 'WARRANTY_HANDOVER_MIGRATION_REQUIRED', 'Chưa áp dụng schema bàn giao bảo hành.');
        }
    }
}
