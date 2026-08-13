<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$verify = in_array('--verify', $argv, true);
$password = $argv[1] ?? 'Cntt@1234';

if ($verify) {
    $total = DB::table('app_user')->count();
    $matching = DB::table('app_user')
        ->pluck('password_hash')
        ->filter(fn (string $hash): bool => Hash::check($password, $hash))
        ->count();

    echo "Verified {$matching}/{$total} app_user password_hash values.\n";
    exit($matching === $total ? 0 : 1);
}

$hash = Hash::make($password);

$updated = DB::table('app_user')->update([
    'password_hash' => $hash,
    'updated_at' => now(),
]);

echo "Updated {$updated} app_user password_hash values.\n";
