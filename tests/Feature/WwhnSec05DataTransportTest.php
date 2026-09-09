<?php

namespace Tests\Feature;

use App\Console\Commands\BackendGoLiveCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WWHN-SEC-05 — biên vận chuyển tầng dữ liệu.
 *
 * Bộ test này KHÔNG kiểm "config có đúng chuỗi không" (thứ đó xanh cả khi biên
 * bị phá). Nó kiểm hai hành vi:
 *
 *   1. Mặc định tự chuyển sang fail-closed khi môi trường không phải dev — kể
 *      cả khi APP_ENV bị gõ sai.
 *   2. Gate cutover THẬT SỰ từ chối cấu hình cho phép rơi xuống plaintext.
 */
class WwhnSec05DataTransportTest extends TestCase
{
    use RefreshDatabase;

    private ?string $originalDefaultConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = (string) config('database.default');
    }

    /**
     * BẮT BUỘC khôi phục `database.default`.
     *
     * Vài test dưới đây đặt tạm `database.default = pgsql` để chạm nhánh kiểm DB
     * của gate. Nếu để nguyên, `RefreshDatabase` lúc dọn dẹp sẽ rollback trên
     * ĐÚNG connection sai và transaction của sqlite không bao giờ được đóng —
     * test KẾ TIẾP đổ với "There is already an active transaction", tức lỗi hiện
     * ra ở một test hoàn toàn vô can. Đã dính đúng bẫy này khi viết bộ test.
     */
    protected function tearDown(): void
    {
        if ($this->originalDefaultConnection !== null) {
            config()->set('database.default', $this->originalDefaultConnection);
        }

        parent::tearDown();
    }

    /**
     * Nạp lại config/database.php với một APP_ENV giả lập.
     *
     * File config được `require` lại thay vì gọi `config()->set(...)`: mục tiêu
     * của test là chính LOGIC MẶC ĐỊNH nằm trong file đó. Set thẳng giá trị vào
     * container sẽ bỏ qua đúng thứ cần kiểm.
     *
     * DB_SSLMODE cũng phải được cô lập như APP_ENV: đối tượng kiểm là nhánh
     * MẶC ĐỊNH khi biến không đặt. CI (09/09) đặt DB_SSLMODE=disable trong .env
     * để migrate chạy được với postgres service không TLS — không cô lập thì
     * cả ba test default bên dưới đọc nhầm giá trị môi trường thay vì logic.
     *
     * @return array<string, mixed>
     */
    private function databaseConfigForEnv(string $appEnv): array
    {
        $previous = getenv('APP_ENV');
        $previousSslMode = getenv('DB_SSLMODE');
        putenv('APP_ENV='.$appEnv);
        $_ENV['APP_ENV'] = $appEnv;
        $_SERVER['APP_ENV'] = $appEnv;
        putenv('DB_SSLMODE');
        unset($_ENV['DB_SSLMODE'], $_SERVER['DB_SSLMODE']);

        try {
            return require base_path('config/database.php');
        } finally {
            if ($previous === false) {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
            } else {
                putenv('APP_ENV='.$previous);
                $_ENV['APP_ENV'] = $previous;
                $_SERVER['APP_ENV'] = $previous;
            }
            if ($previousSslMode === false) {
                putenv('DB_SSLMODE');
                unset($_ENV['DB_SSLMODE'], $_SERVER['DB_SSLMODE']);
            } else {
                putenv('DB_SSLMODE='.$previousSslMode);
                $_ENV['DB_SSLMODE'] = $previousSslMode;
                $_SERVER['DB_SSLMODE'] = $previousSslMode;
            }
        }
    }

    public function test_sslmode_mac_dinh_la_prefer_o_local_va_require_ngoai_local(): void
    {
        // Dev: giữ nguyên hành vi cũ để `docker compose up` không cần CA.
        $this->assertSame('prefer', $this->databaseConfigForEnv('local')['connections']['pgsql']['sslmode']);
        $this->assertSame('prefer', $this->databaseConfigForEnv('testing')['connections']['pgsql']['sslmode']);

        // Production và mọi tên khác: bắt buộc TLS.
        $this->assertSame('require', $this->databaseConfigForEnv('production')['connections']['pgsql']['sslmode']);
        $this->assertSame('require', $this->databaseConfigForEnv('uat')['connections']['pgsql']['sslmode']);
    }

    /**
     * Đây là nhánh dễ bị bỏ quên nhất: APP_ENV gõ sai (`prod`, `Production `,
     * `stagng`) không được biến thành "chắc là dev". Fail-closed nghĩa là mọi
     * giá trị lạ đều bị siết.
     */
    public function test_app_env_go_sai_van_bi_siet_nhu_production(): void
    {
        foreach (['prod', 'Production', 'stagng', '', 'locall'] as $typo) {
            $this->assertSame(
                'require',
                $this->databaseConfigForEnv($typo)['connections']['pgsql']['sslmode'],
                "APP_ENV='{$typo}' phải được đối xử như production.",
            );
        }
    }

    /**
     * Khi không chạy TLS, hai khoá scheme/context phải VẮNG MẶT hẳn — không
     * phải null. Hai lần đo thực tế chồng lên nhau:
     *  - 08/09/2026 (phpredis): context là MẢNG RỖNG vẫn khiến
     *    PhpRedisConnector đẩy stream-context xuống Redis::connect() và
     *    phpredis cố bật TLS -> "Failed to enable crypto", Valkey đứt.
     *  - 09/09/2026 (predis v3 trên CI): đặt KEY 'scheme' => null bị predis
     *    cast thành '' và ném "Unknown connection scheme: ''" ngay ở
     *    cache:clear. Vì vậy config chỉ spread scheme/context khi THẬT SỰ bật
     *    TLS (xem $redisTlsConnection trong config/database.php).
     * Ý định gốc của test không đổi: không TLS ngầm, không context rác.
     */
    public function test_redis_context_la_null_khi_chua_bat_tls(): void
    {
        $config = $this->databaseConfigForEnv('local');

        $this->assertArrayNotHasKey('scheme', $config['redis']['default']);
        $this->assertArrayNotHasKey('context', $config['redis']['default']);
        $this->assertArrayNotHasKey('scheme', $config['redis']['cache']);
        $this->assertArrayNotHasKey('context', $config['redis']['cache']);
    }

    public function test_bat_redis_tls_thi_co_scheme_va_xac_thuc_chung_chi(): void
    {
        $previous = getenv('REDIS_SCHEME');
        putenv('REDIS_SCHEME=tls');
        $_ENV['REDIS_SCHEME'] = 'tls';
        $_SERVER['REDIS_SCHEME'] = 'tls';

        try {
            $config = $this->databaseConfigForEnv('production');

            $this->assertSame('tls', $config['redis']['default']['scheme']);
            // Bật TLS mà không xác thực chứng chỉ thì vẫn MITM được — mặc định
            // phải là bật xác thực, không phải để người deploy tự nhớ.
            $this->assertTrue($config['redis']['default']['context']['ssl']['verify_peer']);
            $this->assertTrue($config['redis']['default']['context']['ssl']['verify_peer_name']);
        } finally {
            if ($previous === false) {
                putenv('REDIS_SCHEME');
                unset($_ENV['REDIS_SCHEME'], $_SERVER['REDIS_SCHEME']);
            } else {
                putenv('REDIS_SCHEME='.$previous);
                $_ENV['REDIS_SCHEME'] = $previous;
                $_SERVER['REDIS_SCHEME'] = $previous;
            }
        }
    }

    /**
     * Gate cutover phải TỪ CHỐI, không phải cảnh báo, khi tầng dữ liệu còn cho
     * phép plaintext ngoài môi trường dev.
     */
    public function test_golive_gate_tu_choi_khi_tang_du_lieu_con_plaintext(): void
    {
        config()->set('golive.development_environments', ['nothing-matches-this']);
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.sslmode', 'prefer');
        config()->set('database.redis.default.scheme', null);
        config()->set('database.redis.default.password', null);
        config()->set('session.driver', 'redis');

        $result = $this->runDataTransportCheck();

        $this->assertSame('fail', $result['status']);
        $this->assertArrayHasKey('db_sslmode', $result['detail']['findings']);
        $this->assertArrayHasKey('redis_scheme', $result['detail']['findings']);
        $this->assertArrayHasKey('redis_password', $result['detail']['findings']);
    }

    public function test_golive_gate_chap_nhan_khi_da_bat_tls_hai_ben(): void
    {
        config()->set('golive.development_environments', ['nothing-matches-this']);
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.sslmode', 'verify-full');
        config()->set('database.connections.pgsql.sslrootcert', '/etc/ssl/certs/db-ca.pem');
        config()->set('database.redis.default.scheme', 'tls');
        config()->set('database.redis.default.password', 'khong-phai-secret-that');
        config()->set('database.redis.default.context', ['ssl' => ['verify_peer' => true]]);
        config()->set('session.driver', 'redis');

        $result = $this->runDataTransportCheck();

        // Assert trên FINDINGS chứ không trên status: ở môi trường test, Swagger
        // vẫn bật nên check sinh thêm một WARNING không liên quan tới tầng dữ
        // liệu. Điều cần chứng minh ở đây là không còn phát hiện CHẶN nào.
        $this->assertSame([], $result['detail']['findings'], (string) json_encode($result['detail']['findings']));
    }

    /**
     * `require` mã hoá nhưng không xác thực danh tính server. Đó là tiến bộ thật
     * so với `prefer` nên KHÔNG chặn cutover, nhưng phải hiện thành cảnh báo —
     * nếu im lặng thì không môi trường nào lên tới verify-full.
     */
    public function test_require_khong_kem_ca_chi_la_canh_bao(): void
    {
        config()->set('golive.development_environments', ['nothing-matches-this']);
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.sslmode', 'require');
        config()->set('database.redis.default.scheme', 'tls');
        config()->set('database.redis.default.password', 'khong-phai-secret-that');
        config()->set('database.redis.default.context', ['ssl' => ['verify_peer' => true]]);
        config()->set('session.driver', 'redis');

        $result = $this->runDataTransportCheck();

        $this->assertSame([], $result['detail']['findings']);
        $this->assertArrayHasKey('db_sslmode_unverified', $result['detail']['warnings']);
        $this->assertSame('warn', $result['status']);
    }

    /**
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function runDataTransportCheck(): array
    {
        $command = new BackendGoLiveCheck;
        $method = new \ReflectionMethod($command, 'dataTransport');

        /** @var array{status:string, detail:array<string,mixed>} $result */
        $result = $method->invoke($command);

        return $result;
    }
}
