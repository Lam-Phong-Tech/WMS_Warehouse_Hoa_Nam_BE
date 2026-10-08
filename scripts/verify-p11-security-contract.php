<?php

/** Isolated executable policy checks; not a substitute for HTTP/DB/release evidence. */
require_once __DIR__.'/../Modules/Auth/app/Support/P11SecurityContract.php';

use Modules\Auth\Support\P11SecurityContract as Contract;

$count = 0;
$check = static function (bool $condition, string $name) use (&$count): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $count++;
};
$rules = Contract::passwordRules();
$check(array_keys($rules) === ['current_password', 'new_password', 'new_password_confirmation'], 'three canonical fields');
$check($rules['current_password'] === ['required', 'string', 'max:255'], 'current password remains required');
$check($rules['new_password'] === ['required', 'string', 'min:8', 'max:128', 'confirmed'], 'server length and confirmation rules');
$check(in_array('required', $rules['new_password_confirmation'], true), 'backend confirmation remains mandatory');
$check(Contract::validationCode(['new_password_confirmation' => ['Required' => []]]) === 'PASSWORD_CONFIRMATION_REQUIRED', 'missing confirmation code');
$check(Contract::validationCode(['new_password' => ['Confirmed' => []]]) === 'PASSWORD_CONFIRMATION_MISMATCH', 'mismatch code');
$check(Contract::validationCode(['new_password' => ['Min' => [8]]]) === 'PASSWORD_POLICY_VIOLATION', 'policy code');
$check(Contract::validationCode(['current_password' => ['Required' => []]]) === 'PASSWORD_INPUT_INVALID', 'input code');
$check(Contract::WRONG_CURRENT === 'CURRENT_PASSWORD_INCORRECT', 'wrong current is not expiration');
foreach (['bcrypt', 'argon', 'argon2id'] as $driver) {
    $policy = Contract::passwordPolicy($driver);
    $check($policy['min_length'] === 8 && $policy['max_length'] === 128, 'length policy');
    $check($policy['confirmation_required'] === true, 'confirmation policy');
    $check($policy['keep_sessions_supported'] === false, 'keep not invented');
    $check(str_ends_with($policy['version'], $driver), 'version identifies effective hasher limits');
    $check(Contract::unsafeEncoding("not\0accepted", $driver), 'NUL rejected before hashing');
    $contract = Contract::contract($driver);
    $check($contract['change_password']['path'] === '/api/v1/auth/change-password', 'existing route only');
    $check($contract['change_password']['wrong_current_http_status'] === 422, 'wrong password is 422');
    $check($contract['change_password']['wrong_current_ends_session'] === false, 'wrong password does not log out');
    $check($contract['sessions']['items_path'] === 'data', 'legacy array preserved');
    $check($contract['sessions']['delete_target_field'] === 'id', 'no family/row fallback');
    $check($contract['sessions']['current_family_protected'] === true, 'current protected');
    $check($contract['operations']['url'] === null, 'no proposed URL exposed as deployed');
    foreach (['durable_receipt_supported', 'idempotency_supported', 'reconciliation_supported',
        'revoked_token_can_read', 'automatic_mutation_retry', 'readback_proves_original_request',
        'not_found_proves_not_executed'] as $flag) {
        $check($contract['operations'][$flag] === false, 'unsupported stays false: '.$flag);
    }
    $check($contract['release']['production_ready'] === false, 'no production claim');
}
$check(Contract::maxBytes('bcrypt') === 72, 'bcrypt byte ceiling');
$check(Contract::maxBytes('argon') === null, 'no arbitrary argon byte ceiling');
$check(! Contract::unsafeEncoding(str_repeat('a', 72), 'bcrypt'), '72 ASCII bytes permitted');
$check(Contract::unsafeEncoding(str_repeat('a', 73), 'bcrypt'), '73 ASCII bytes rejected');
$check(! Contract::unsafeEncoding(str_repeat('á', 36), 'bcrypt'), '36 two-byte characters permitted');
$check(Contract::unsafeEncoding(str_repeat('á', 37), 'bcrypt'), '37 two-byte characters rejected');
$controller = file_get_contents(__DIR__.'/../Modules/Auth/app/Http/Controllers/P10ProfileController.php');
$check(str_contains($controller, 'Validator::make($body, SecurityContract::passwordRules())'), 'controller uses published rules');
$check(strpos($controller, 'SecurityContract::unsafeEncoding') < strpos($controller, '$newHash = Hash::make'), 'byte validation precedes hashing');
$check(str_contains($controller, "Hash::check(\$data['current_password'], \$user->password_hash)"), 'current-password verification retained');
$check(str_contains($controller, 'Family::current($user); // Sid-bearing'), 'session guard retained');
$check(str_contains($controller, "where('user_id', \$user->user_id)->whereKey(\$id)"), 'revoke ownership retained');
$check(str_contains($controller, "'CURRENT_SESSION_PROTECTED'"), 'current revoke guard retained');
$check(str_contains($controller, "\$payload['meta']['current_session_id'] = \$current"), 'current ID independent of page');
$check(str_contains($controller, '$user->fresh()->passwordChangedAtEpoch()'), 'outcome uses persisted server time');
$check(str_contains($controller, "'durable_request_receipt' => false"), 'outcome not misrepresented as durable');
echo "PASS: {$count} standalone P11 policy/source checks. HTTP/DB/deployment not tested by this script.\n";
