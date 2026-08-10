<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$domain = $argv[1] ?? 'gmail.com';
$verify = in_array('--verify', $argv, true);
$users = DB::table('app_user')
    ->select('user_id', 'username', 'email')
    ->orderBy('username')
    ->get();

if ($verify) {
    $matching = $users->filter(fn ($user): bool => str_ends_with((string) $user->email, '@' . $domain))->count();
    echo "Verified {$matching}/{$users->count()} app_user emails use @{$domain}.\n";
    foreach ($users as $user) {
        echo "{$user->username}: {$user->email}\n";
    }
    exit($matching === $users->count() ? 0 : 1);
}

$updated = 0;
foreach ($users as $user) {
    $email = (string) $user->email;
    $localPart = str_contains($email, '@') ? strstr($email, '@', true) : $user->username;
    $normalizedEmail = $localPart . '@' . $domain;

    if ($email === $normalizedEmail) {
        continue;
    }

    DB::table('app_user')
        ->where('user_id', $user->user_id)
        ->update([
            'email' => $normalizedEmail,
            'updated_at' => now(),
        ]);

    echo "{$user->username}: {$email} -> {$normalizedEmail}\n";
    $updated++;
}

echo "Updated {$updated} app_user email values.\n";
