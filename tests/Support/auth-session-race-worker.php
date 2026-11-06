<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql'
    || ! str_contains(DB::connection()->getDatabaseName(), 'test')) {
    exit(2);
}
config()->set('refresh_token.single_active_session.enabled', true);
$command = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config()->set('refresh_token.controlled_multi_session.enabled', ($command['controlled'] ?? false) === true);
if (($command['controlled'] ?? false) === true) {
    config()->set('app.url', 'http://localhost');
}
$request = Request::create('/isolated-session-race');
$tokens = app(RefreshTokenService::class);
echo "READY\n";
flush();
try {
    if ($command['command'] === 'LOGIN') {
        $user = User::query()->findOrFail($command['user_id']);
        $issued = $tokens->completeLogin($user, $request, true);
        // Tokens remain private in this process. Only opaque row identity leaves it.
        echo json_encode(['command' => 'LOGIN', 'id' => $issued['session']['id']], JSON_THROW_ON_ERROR);
    } elseif ($command['command'] === 'REFRESH') {
        $result = $tokens->rotate($command['raw'], $request);
        echo json_encode(['command' => 'REFRESH', 'accepted' => $result !== null,
            'failure' => $request->attributes->get('wms.auth_refresh_failure')], JSON_THROW_ON_ERROR);
    } else {
        exit(3);
    }
} catch (HttpResponseException $error) {
    $body = json_decode($error->getResponse()->getContent(), true);
    if (($command['controlled'] ?? false) === true && $error->getResponse()->getStatusCode() === 409
        && ($body['error_code'] ?? null) === 'AUTH_SESSION_LIMIT_REACHED') {
        echo json_encode(['command' => 'LOGIN', 'status' => 409, 'error_code' => 'AUTH_SESSION_LIMIT_REACHED'], JSON_THROW_ON_ERROR);
    } else {
        exit(4);
    }
} catch (Throwable) {
    // Exceptions may include database bindings; never expose them through worker output.
    exit(4);
}
