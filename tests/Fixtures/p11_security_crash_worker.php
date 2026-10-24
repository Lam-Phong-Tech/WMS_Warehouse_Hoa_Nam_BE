<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Support\P11SecurityCommands;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($input['config']['app.env'] ?? null) !== 'testing' || ($input['config']['database.default'] ?? null) !== 'sqlite'
    || ! str_contains($input['config']['database.connections.sqlite']['database'] ?? '', 'p11-crash-test-')) {
    exit(78);
}
$database = realpath($input['config']['database.connections.sqlite']['database']);
$testing = realpath(dirname(__DIR__, 2).'/storage/framework/testing');
if ($database === false || $testing === false || ! str_starts_with($database, $testing.DIRECTORY_SEPARATOR)
    || ! str_starts_with(basename(dirname($database)), 'p11-crash-test-')) {
    exit(78);
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if (is_file($app->getCachedConfigPath())) {
    exit(78); // Never bootstrap against a cached non-isolated runtime config.
}
$app->instance('request', Request::create('http://localhost'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
config($input['config']);
DB::purge();
DB::listen(static function (QueryExecuted $event): void {
    if (str_starts_with(strtolower($event->sql), 'update') && str_contains($event->sql, P11SecurityCommands::TABLE)
        && in_array('SUCCEEDED', $event->bindings, true)) {
        exit(77); // No catch/finally can record a terminal outcome or repair it.
    }
});
$server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
foreach ($input['headers'] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$response = $kernel->handle(Request::create('/api/v1/auth/change-password', 'POST', [], [], [], $server,
    json_encode($input['body'], JSON_THROW_ON_ERROR)));
fwrite(STDERR, 'Unexpected HTTP status '.$response->getStatusCode());
exit(79);
