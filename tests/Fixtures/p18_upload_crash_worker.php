<?php

// Test-only child process: input is ephemeral, never passed in argv or printed.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($input['config']['app.env'] ?? null) !== 'testing'
    || ($input['config']['database.default'] ?? null) !== 'sqlite'
    || ! str_contains($input['config']['database.connections.sqlite']['database'] ?? '', 'p18-crash-test-')) {
    exit(78);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->instance('request', Request::create('http://localhost'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
config($input['config']);
DB::purge();
DB::listen(static function (QueryExecuted $event): void {
    if (preg_match('/^insert into ["`]?document_attachments["`]? /i', $event->sql)) {
        // Bypass every exception handler and the shift transaction's catch.
        exit(77);
    }
});
$server = ['HTTP_ACCEPT' => 'application/json'];
foreach ($input['headers'] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$request = Request::create($input['path'], 'POST', [], [], [
    'file' => new UploadedFile($input['file'], 'crash-test.png', 'image/png', null, true),
], $server);
$response = $kernel->handle($request);
// No body: an unexpected error might otherwise reveal credentials or PII.
fwrite(STDERR, 'Unexpected HTTP status '.$response->getStatusCode());
exit(79);
