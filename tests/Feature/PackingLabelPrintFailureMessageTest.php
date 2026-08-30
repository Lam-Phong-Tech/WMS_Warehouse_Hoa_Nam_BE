<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * In nhãn thất bại phải hiện đúng MỘT câu lỗi rõ ràng (vd "không kết nối được
 * máy in"), không phải chuỗi UUID/trạng thái/số/bool nối lại — lỗi thật gặp khi
 * QA bấm "In nhãn" mà chưa bật/kết nối máy in.
 *
 * Nguyên nhân gốc: ApiError::abort() Việt hoá TỪNG GIÁ TRỊ trong `details` rồi
 * FE hiển thị chúng như câu lỗi field-by-field (đúng cho lỗi validate 422).
 * dispatchLabelOnce()/recordPrintFailure() từng nhét metadata thuần audit
 * (label_print_event_id, label_status, version, reprint) vào đúng chỗ đó dù
 * FE không đọc lại field nào — kết quả là toast hiện "<uuid> In thất bại 4
 * false" thay vì một câu tiếng Việt rõ nghĩa.
 */
class PackingLabelPrintFailureMessageTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private function createLabel(string $printerAgentEndpoint): array
    {
        $headers = $this->superAdminHeaders();
        $printer = $this->postJson('/api/v1/printer-configs', [
            'printer_code' => 'PRT-PRNFAIL-'.Str::upper(Str::random(6)),
            'printer_name' => 'Print failure message test',
            'agent_endpoint' => $printerAgentEndpoint,
            'status' => 'ACTIVE',
        ], $headers)->assertCreated();
        $printerId = (string) $printer->json('data.id');

        $labelId = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $labelId,
            'outbound_document_id' => null,
            'outbound_item_match_id' => null,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode(['test' => true]),
            'status' => 'READY_TO_PRINT',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['headers' => $headers, 'printer_id' => $printerId, 'label_id' => $labelId];
    }

    public function test_khong_ket_noi_duoc_may_in_hien_dung_mot_cau_ro_rang(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect');
        });
        $fixture = $this->createLabel('https://printer.unreachable.test/print');

        $response = $this->postJson(
            "/api/v1/label-snapshots/{$fixture['label_id']}/print",
            ['printer_config_id' => $fixture['printer_id']],
            $fixture['headers'] + [
                'If-Match' => '1',
                'Idempotency-Key' => 'print-fail-conn-'.Str::random(8),
            ],
        );

        $response->assertStatus(409)->assertJsonPath('error.code', 'PRINT_FAILED');
        $this->assertSame(
            'Không thể kết nối tới máy in. Kiểm tra máy in đã bật và đã kết nối mạng, sau đó thử lại.',
            $response->json('message'),
        );
        // Không được rò rỉ metadata audit (uuid/version/bool) ra field lỗi mà
        // FE sẽ nối chuỗi hiển thị thẳng cho người dùng.
        $this->assertSame([], $response->json('errors'));
        $this->assertSame([], $response->json('error.details'));
    }

    public function test_agent_may_in_tra_ve_loi_hien_dung_mot_cau_khong_kem_metadata(): void
    {
        Http::fake(fn () => Http::response(['success' => false], 500));
        $fixture = $this->createLabel('https://printer.rejects.test/print');

        $response = $this->postJson(
            "/api/v1/label-snapshots/{$fixture['label_id']}/print",
            ['printer_config_id' => $fixture['printer_id']],
            $fixture['headers'] + [
                'If-Match' => '1',
                'Idempotency-Key' => 'print-fail-agent-'.Str::random(8),
            ],
        );

        $response->assertStatus(409)->assertJsonPath('error.code', 'PRINT_FAILED');
        $this->assertSame(
            'Máy in phản hồi lỗi khi nhận lệnh in (HTTP 500).',
            $response->json('message'),
        );
        $this->assertSame([], $response->json('errors'));
    }

    public function test_may_in_chua_cau_hinh_hien_dung_mot_cau_khong_kem_metadata(): void
    {
        $headers = $this->superAdminHeaders();
        $printer = $this->postJson('/api/v1/printer-configs', [
            'printer_code' => 'PRT-PRNFAIL-NOAGENT-'.Str::upper(Str::random(6)),
            'printer_name' => 'No agent endpoint',
            'agent_endpoint' => 'https://printer.inactive.test/print',
            'status' => 'INACTIVE',
        ], $headers)->assertCreated();
        $printerId = (string) $printer->json('data.id');

        $labelId = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $labelId,
            'outbound_document_id' => null,
            'outbound_item_match_id' => null,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode(['test' => true]),
            'status' => 'READY_TO_PRINT',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson(
            "/api/v1/label-snapshots/{$labelId}/print",
            ['printer_config_id' => $printerId],
            $headers + [
                'If-Match' => '1',
                'Idempotency-Key' => 'print-fail-noconfig-'.Str::random(8),
            ],
        );

        $response->assertStatus(409)->assertJsonPath('error.code', 'PRINT_FAILED');
        $this->assertSame(
            'Máy in chưa được cấu hình hoặc đang ở trạng thái không hoạt động.',
            $response->json('message'),
        );
        $this->assertSame([], $response->json('errors'));
    }
}
