#!/usr/bin/env bash
set -euo pipefail
cd /app
test "$APP_ENV" = testing && test "$DB_HOST" = 127.0.0.1 && test -z "$DB_URL"
test "$(cat .backlog-source-sha)" = "$BACKLOG_SOURCE_SHA"
case "$DB_CONNECTION:$DB_DATABASE" in 'sqlite::memory:'|'pgsql:p12_contract_test') ;; *) exit 1 ;; esac
cp .env.example .env
php artisan key:generate
php artisan config:clear
php artisan package:discover --ansi > /tmp/backlog-packages.log
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
mkdir -p backlog-results
python3 - <<'PY'
from pathlib import Path
import re
core = ['P24ScannerOwnerContractTest','P19LinkedComponentIssueTest','P20ComponentHistoryTest','P21ComponentResumeTest','P03DraftContractTest','P04InboundContractTest','P05OutboundContractTest','P12ReadRevision2Test','AuthWarehouseContextTest','AuthSessionPersistenceTest','MiniAppComponentIssueStagingTest','ComponentIssueCompositeQrScanTest']
paths = [Path('tests/Feature') / (name + '.php') for name in core]
assert len(paths) == 12 and all(path.is_file() for path in paths)
extra = sorted(p for p in Path('tests/Feature').glob('P*Test.php') if re.match(r'P(?:11|12|13|14|15|16|17|18|19|20|21|22|23|24)', p.name) and p not in paths)
assert len(extra) >= 15 and Path('tests/Feature/P19BatchLimitsTest.php') in extra
assert Path('tests/Feature/P11LegacyCredentialBoundaryTest.php') in extra
for label, selected in [('core', paths), ('extra', extra), ('cached', paths + extra)]:
    Path('backlog-results/' + label + '-expected.txt').write_text('\n'.join(map(str, selected)) + '\n')
    print(label, len(selected), 'required classes')
PY
result=0
files=(Modules/Auth/app/Http/Middleware/EnforceCredentialFreshness.php tests/Feature/P11LegacyCredentialBoundaryTest.php)
for file in "${files[@]}"; do php -l "$file" || result=1; done
vendor/bin/pint --test "${files[@]}" || result=1
for suite in core extra cached; do
    if [ "$suite" = cached ]; then php artisan route:cache; fi
    mapfile -t tests < "backlog-results/$suite-expected.txt"
    if php artisan test "${tests[@]}" --log-junit "backlog-results/$suite.xml" > "/tmp/backlog-$suite.log" 2>&1; then
        tail -8 "/tmp/backlog-$suite.log"
    else
        tail -180 "/tmp/backlog-$suite.log"
        result=1
    fi
    php artisan route:clear
    python3 tools/ci/backlog/report.py "$suite" || result=1
done
sha256sum --check --quiet .backlog-source-hashes || result=1
exit "$result"
