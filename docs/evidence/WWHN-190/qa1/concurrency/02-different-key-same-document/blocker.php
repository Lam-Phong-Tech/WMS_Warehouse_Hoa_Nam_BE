<?php
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET application_name = 'wwhn190-blocker'");
DB::beginTransaction();
DB::table('stock_documents')->where('id', '8e5bd46d-9a9c-4cf6-81f6-ad05fa36164b')->lockForUpdate()->get();
file_put_contents('docs/evidence/WWHN-190/qa1/concurrency/02-different-key-same-document/barrier/blocker-ready', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
usleep(1200 * 1000);
DB::rollBack();