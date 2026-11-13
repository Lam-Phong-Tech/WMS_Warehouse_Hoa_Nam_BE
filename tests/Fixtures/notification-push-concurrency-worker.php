<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Notification\Delivery\DeliverySender;
use Modules\Notification\Delivery\NotificationOutbox;
use Modules\Notification\Delivery\PushDeliveryThrottle;

// Only the dedicated disposable local PostgreSQL cluster may run this fixture.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '55438'
    || getenv('DB_DATABASE') !== 'hn_notification_queue_test' || getenv('DB_USERNAME') !== 'hn_notification_test') {
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $mode, $user, $barrier, $output, $instant] = $argv;
$clock = CarbonImmutable::parse($instant);
CarbonImmutable::setTestNow($clock);
Carbon::setTestNow($clock);
config(['notification_delivery.enabled' => true, 'notification_delivery.canary_file' => null,
    'notification_delivery.max_push_per_user_per_minute' => 60]);
file_put_contents($output.'.ready', 'ready');
$deadline = microtime(true) + 30;
while (! is_file($barrier)) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(10_000);
    clearstatcache(true, $barrier);
}
if ($mode === 'admit' || $mode === 'admit-live') {
    if ($mode === 'admit-live') {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        $clock = CarbonImmutable::now('UTC');
    }
    $result = DB::transaction(function () use ($user, $clock): bool {
        $next = app(PushDeliveryThrottle::class)->admit($user, $clock);
        usleep(200_000); // Make the competing transaction wait on a real PostgreSQL row lock.

        return $next === null;
    });
} else {
    $sender = new class implements DeliverySender
    {
        public function send(string $token, array $data): array
        {
            DB::table('notification_push_test_sends')->insert(['delivery_id' => $data['delivery_id']]);
            usleep(100_000);

            return ['state' => 'PROVIDER_ACCEPTED', 'provider_id' => 'TEST-ONLY', 'retry_after' => null];
        }
    };
    $result = app(NotificationOutbox::class)->drain($sender, 100);
}
file_put_contents($output, json_encode($result, JSON_THROW_ON_ERROR));
