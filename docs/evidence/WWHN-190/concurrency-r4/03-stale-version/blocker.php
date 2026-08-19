<?php
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET application_name = 'wwhn190-blocker'");
DB::beginTransaction();
DB::table('stock_documents')->where('id', '580245ef-26c7-46ef-81f3-00810356ffa3')->lockForUpdate()->get();
file_put_contents('docs/evidence/WWHN-190/concurrency-r4/03-stale-version/barrier/blocker-ready', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
usleep(1200 * 1000);
DB::rollBack();