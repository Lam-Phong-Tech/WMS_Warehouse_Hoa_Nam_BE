<?php
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET application_name = 'wwhn190-blocker'");
DB::beginTransaction();
DB::table('stock_documents')->where('id', 'f1d25032-5ca3-45ad-8c5c-3a85c7dd1a7d')->lockForUpdate()->get();
file_put_contents('docs/evidence/WWHN-190/qa1/concurrency/03-stale-version/barrier/blocker-ready', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
usleep(1200 * 1000);
DB::rollBack();