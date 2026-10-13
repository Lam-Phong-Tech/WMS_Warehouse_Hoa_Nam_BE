#!/usr/bin/env bash
set -euo pipefail
cd /app
test "$APP_ENV" = testing && test "$DB_HOST" = 127.0.0.1 && test -z "$DB_URL"
test "$(cat .p14-source-sha)" = "$P14_SOURCE_SHA"
case "$DB_CONNECTION:$DB_DATABASE" in 'sqlite::memory:'|'pgsql:p12_contract_test') ;; *) exit 1 ;; esac
# All application/test execution takes place without external networking.
cp .env.example .env
php artisan key:generate
php artisan config:clear
php artisan package:discover --ansi > /tmp/p14-packages.log
php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$safe = $db->getDriverName() === 'sqlite' ? $db->getDatabaseName() === ':memory:'
    : $db->getDriverName() === 'pgsql' && $db->getDatabaseName() === 'p12_contract_test' && $db->getConfig('host') === '127.0.0.1';
if (! $app->environment('testing') || ! $safe || getenv('DB_URL') !== '') { exit(1); }
PHP
mkdir -p p14-results
files=(app/Support/P14ShiftLifecycle.php app/Support/P14ShiftOwnerEvidence.php app/Support/P14ShiftService.php app/Http/Middleware/P14ShiftOwnerFence.php Modules/Warehouse/app/Support/MiniAppWorkShiftService.php config/p14.php tests/Feature/P14LifecycleIntegrationTest.php)
for f in "${files[@]}"; do php -l "$f"; done
vendor/bin/pint --test "${files[@]}"
python3 -m json.tool contracts/p14/lifecycle-v1.json >/dev/null
# Additional unchanged-case probes, not a substitute for either complete matrix.
php artisan test tools/ci/p14/P14NfcProbeTest.php tools/ci/p14/P14SnapshotProbeTest.php --filter='test_p07_confirm_uses_native_durable_receipt_not_a_fake_saved_draft|test_saved_p03_snapshot_is_immutable_paged_and_does_not_change_owner' > /tmp/p14-probe.log 2>&1 || true
cat /tmp/p14-probe.log
core=(P14LifecycleIntegrationTest P14RecoveryShiftContractTest P14RecoveryBoundaryTest MiniAppWorkShiftTest AuthWarehouseContextTest AuthSessionPersistenceTest P03DraftContractTest P04InboundContractTest P05OutboundContractTest P07NfcContractTest)
cached=(P14LifecycleIntegrationTest P14RecoveryShiftContractTest MiniAppWorkShiftTest AuthWarehouseContextTest)
result=0
for suite in lifecycle cached; do
    names=("${core[@]}")
    if [ "$suite" = cached ]; then
        php artisan route:cache
        names=("${cached[@]}")
    fi
    printf '%s\n' "${names[@]}" > "p14-results/$suite-expected.txt"
    paths=(); for name in "${names[@]}"; do paths+=("tests/Feature/$name.php"); done
    if php artisan test "${paths[@]}" --log-junit "p14-results/$suite.xml" > "/tmp/p14-$suite.log" 2>&1; then
        tail -8 "/tmp/p14-$suite.log"
    else
        tail -150 "/tmp/p14-$suite.log"
        result=1
    fi
    php artisan route:clear
    if ! python3 tools/ci/p14/report.py "$suite"; then result=1; fi
done
# Verify every archived tracked file, not just the changed PHP files.
sha256sum --check --quiet .p14-source-hashes || result=1
exit "$result"
