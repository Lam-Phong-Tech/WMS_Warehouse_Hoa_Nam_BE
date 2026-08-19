<?php
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET application_name = 'wwhn190-blocker'");
DB::beginTransaction();
DB::table('stock_documents')->where('id', '8b23c60f-e185-4958-844b-5c95e30107cc')->lockForUpdate()->get();
file_put_contents('docs/evidence/WWHN-190/qa1/concurrency/05-forced-rollback/barrier/blocker-ready', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
usleep(1200 * 1000);
DB::rollBack();