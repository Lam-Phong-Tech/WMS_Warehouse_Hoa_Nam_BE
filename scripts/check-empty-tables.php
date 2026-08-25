<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = DB::select("select tablename from pg_tables where schemaname = 'public' order by tablename");

$totalTables = 0;
$emptyTables = 0;

foreach ($tables as $table) {
    $totalTables++;
    $name = $table->tablename;
    $count = DB::table($name)->count();

    if ($count === 0) {
        $emptyTables++;
        echo $name.':0'.PHP_EOL;
    }
}

fwrite(STDERR, "checked={$totalTables}; empty={$emptyTables}".PHP_EOL);
