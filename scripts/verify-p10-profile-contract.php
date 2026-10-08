<?php

/** Standalone policy/source checks. Does NOT substitute for Laravel/DB/DEV tests. */
require_once __DIR__.'/../Modules/Auth/app/Support/P10ProfilePolicy.php';

use Modules\Auth\Support\P10ProfilePolicy as Policy;

$checks = 0;
$check = static function (bool $condition, string $name) use (&$checks): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $checks++;
};
foreach (['1' => 1, '"1"' => 1, '  "22"  ' => 22, '2147483646' => 2147483646] as $input => $expected) {
    $check(Policy::version((string) $input) === $expected, 'positive version');
}
foreach (['', '0', '"0"', '-1', '*', 'W/"1"', '1.1', '1,2', '"1', '1"', '1e2', '2147483647', '99999999999999', '01'] as $bad) {
    try {
        Policy::version($bad);
        $check(false, 'invalid version must fail');
    } catch (InvalidArgumentException) {
        $check(true, 'invalid version rejected');
    }
}
$check(Policy::unsupportedFields(['display_name' => 'Name', 'phone' => null]) === [], 'editable fields');
foreach (['name', 'nickname', 'avatar', 'avatar_url', 'roles', 'permissions', 'warehouse_id', 'actor_id'] as $field) {
    $check(Policy::unsupportedFields([$field => 'ignored']) === [$field], 'untrusted field rejected');
}
$contract = Policy::contract();
$check($contract['nickname_supported'] === false, 'nickname not fabricated');
$check($contract['avatar']['supported'] === false, 'avatar not fabricated');
$check($contract['request_reconciliation']['supported'] === false, 'no invented reconcile');
$check($contract['request_reconciliation']['readback_proves_original_request'] === false, 'readback is not a receipt');
$check($contract['ui_permission_groups']['groups'] === null, 'no inferred six-group grants');
$check(!$contract['logout_ends_work_shift'] && !$contract['logout_deletes_business_drafts'], 'logout remains separate');
$check(Policy::family(null, 'ABC') === 'abc', 'legacy family');
$check(Policy::family('DEF', 'ABC') === 'def', 'stable family wins');
$root = dirname(__DIR__);
$controller = file_get_contents($root.'/Modules/Auth/app/Http/Controllers/P10ProfileController.php');
$family = file_get_contents($root.'/Modules/Auth/app/Support/P10SessionFamily.php');
$tokens = file_get_contents($root.'/Modules/Auth/app/Support/RefreshTokenService.php');
$guard = file_get_contents($root.'/Modules/Auth/app/Http/Middleware/EnforceCredentialFreshness.php');
$provider = file_get_contents($root.'/Modules/Auth/app/Providers/RouteServiceProvider.php');
$check(str_contains($controller, 'CURRENT_SESSION_PROTECTED'), 'current family protected');
$check(str_contains($controller, 'Validator::make($body'), 'validate body not mixed query');
$check(str_contains($controller, "'changed_fields' => array_keys(\$data)"), 'PII-free audit');
$logout = substr($controller, strpos($controller, 'public function logout'), strpos($controller, 'public function changePassword') - strpos($controller, 'public function logout'));
$check(!str_contains($logout, 'revokeAllForUser'), 'logout never revokes all users sessions');
$check(str_contains($logout, 'Family::assertPresentedToken'), 'logout binds token to caller family');
$check(str_contains($family, "where('user_id', \$user->user_id)"), 'session ownership constrained');
$check(str_contains($tokens, 'Same lock order') && substr_count($tokens, 'lockForUpdate()') >= 4, 'shared parent lock paths');
$check(str_contains($guard, 'P10SessionFamily::current($user)'), 'global revoked-family enforcement');
$check(str_contains($provider, 'bind(AuthController::class, P10ProfileController::class)'), 'route-cache-safe binding');
$check(str_contains($controller, "'device_label' => null, 'location' => null"), 'no inferred device or location');
$check(str_contains($controller, 'ROW_NUMBER() OVER (PARTITION BY COALESCE(auth_session_id, id)'), 'family paging not token duplicates');
echo "PASS: {$checks} standalone policy/source checks. Laravel/DB/DEV not exercised here.\n";
