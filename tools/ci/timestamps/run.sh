#!/usr/bin/env bash
set -euo pipefail
cd /app
test "$APP_ENV" = testing && test "$DB_HOST" = 127.0.0.1 && test -z "$DB_URL"
test "$(cat .timestamp-source-sha)" = "$TIMESTAMP_SOURCE_SHA"
case "$DB_CONNECTION:$DB_DATABASE" in 'sqlite::memory:'|'pgsql:p12_contract_test') ;; *) exit 1 ;; esac
cp .env.example .env
php artisan key:generate
php artisan config:clear
php artisan package:discover --ansi >/tmp/timestamp-packages.log
mkdir -p timestamp-results
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
files=(app/Support/DocumentTimestamp.php config/document_timestamps.php Modules/Warehouse/app/Support/DocumentTimestampSql.php Modules/Warehouse/app/Support/SerializesDocumentTimestamps.php Modules/Warehouse/app/Models/StockDocument.php Modules/Warehouse/app/Http/Middleware/DocumentTimestampResponse.php Modules/Warehouse/app/Providers/RouteServiceProvider.php Modules/Warehouse/app/Support/P12MixedDocumentReadModel.php Modules/Warehouse/app/Support/P12MixedDocumentPage.php Modules/Warehouse/app/Support/P12MixedDocumentMetadata.php tests/Unit/DocumentTimestampContractTest.php tests/Feature/DocumentTimestampHttpTest.php)
for file in "${files[@]}"; do php -l "$file"; done
python3 -m json.tool contracts/timestamps/document-timestamps-v1.json >/dev/null
result=0
vendor/bin/pint --test "${files[@]}" || result=1
for suite in contract legacy_p12 owners cached; do
    storage=Asia/Ho_Chi_Minh
    case "$suite" in
        contract|cached) paths=(tests/Unit/DocumentTimestampContractTest.php tests/Feature/DocumentTimestampHttpTest.php) ;;
        legacy_p12) paths=(tests/Feature/P12MixedDocumentsTest.php); storage=UTC ;;
        owners) paths=(tests/Feature/P02ReadContractTest.php tests/Feature/P03DraftContractTest.php tests/Feature/P04InboundContractTest.php tests/Feature/P05OutboundContractTest.php tests/Feature/P12DocumentsReadContractTest.php tests/Feature/P12ReadRevision2Test.php tests/Feature/P12ScanParentSafetyTest.php tests/Feature/P23ReadContractTest.php) ;;
    esac
    if [ "$suite" = cached ]; then php artisan route:cache; fi
    printf '%s\n' "${paths[@]}" > "timestamp-results/$suite-expected.txt"
    if DOCUMENT_LEGACY_NAIVE_TIMEZONE="$storage" php artisan test "${paths[@]}" --log-junit "timestamp-results/$suite.xml" > "/tmp/timestamps-$suite.log" 2>&1; then
        tail -8 "/tmp/timestamps-$suite.log"
    else
        tail -180 "/tmp/timestamps-$suite.log"; result=1
    fi
    php artisan route:clear
    if ! TIMESTAMP_SUITE="$suite" TIMESTAMP_STORAGE="$storage" python3 tools/ci/timestamps/report.py; then result=1; fi
done
sha256sum --check --quiet .timestamp-source-hashes || result=1
exit "$result"
