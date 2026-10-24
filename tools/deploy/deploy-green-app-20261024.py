#!/usr/bin/env python3
"""Promote the tested App candidate; only the separately approved additive P13 schema."""

import argparse
import datetime
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import tempfile
import urllib.error
import urllib.request


spec = importlib.util.spec_from_file_location('green', Path(__file__).with_name('deploy-green-verified.py'))
green = importlib.util.module_from_spec(spec)
spec.loader.exec_module(green)
spec = importlib.util.spec_from_file_location('tc12', Path(__file__).with_name('deploy-green-tc12.py'))
tc12 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tc12)
SOURCE = 'a9053a21bc35ece367be0b76db727d4749b59855'
PREVIOUS = '8be640aa74704b12fe891f8a6db5969901efd8b2'
PREVIOUS_RELEASE = PREVIOUS + '-nfc-time-20261021T065447Z'
P13 = 'database/migrations/2026_10_23_000001_create_mini_app_web_post_handoffs.php'
P11 = 'database/migrations/2026_10_24_000001_create_p11_security_command_receipts.php'
STAGING = 'Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php'
HISTORY = 'database/migrations/2026_09_09_122500_create_failed_jobs_table.php'
HISTORY_SHA256 = '752aa2914ab7338f83d06f9faa763d4064117bcfba0a4aa328e43676a6cd2242'
RESERVE = 1024 ** 3
RUNTIME = (*tc12.RUNTIME_PATHS, 'public', 'resources', 'tests', 'package.json', 'package-lock.json',
           'vite.config.js', 'vite-module-loader.js')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def files(folder, revision, paths=tc12.RUNTIME_PATHS):
    with tarfile.open(fileobj=io.BytesIO(tc12.git(folder, 'archive', '--format=tar', revision, '--', *paths))) as bundle:
        return {m.name: sha(bundle.extractfile(m).read()) for m in bundle.getmembers() if m.isfile()}


def validate_migration_delta(before, after):
    delta = {name for name in set(before) | set(after) if before.get(name) != after.get(name)}
    require(delta == {P13, P11} and P13 not in before and P11 not in before,
            'Unexpected migration changes; no general migration is authorized')


def package(folder, ops_sha, output):
    require(re.fullmatch('[0-9a-f]{40}', ops_sha), 'Invalid operations SHA')
    require(tc12.git(folder, 'rev-parse', 'HEAD').decode().strip() == ops_sha, 'Checkout changed')
    tc12.git(folder, 'diff', '--exit-code', 'HEAD')
    tc12.git(folder, 'merge-base', '--is-ancestor', SOURCE, ops_sha)
    tc12.git(folder, 'merge-base', '--is-ancestor', PREVIOUS, SOURCE)
    tc12.git(folder, 'diff', '--exit-code', SOURCE, ops_sha, '--', *RUNTIME)
    before, after = files(folder, PREVIOUS), files(folder, SOURCE)
    migrations = lambda values: {name: value for name, value in values.items() if '/migrations/' in name}
    validate_migration_delta(migrations(before), migrations(after))
    changed = {name for name in set(before) | set(after) if before.get(name) != after.get(name)}
    require(set(green.OVERLAYS).intersection(changed) == {STAGING}, 'Unexpected overlay/source overlap')
    require(before['composer.lock'] == after['composer.lock'], 'Dependency lock changed')
    assets = folder / 'public/build'
    require((assets / 'manifest.json').is_file(), 'Built assets missing')
    asset_files = [p for p in assets.rglob('*') if p.is_file()]
    require(asset_files and all(not p.is_symlink() for p in assets.rglob('*')), 'Unsafe asset tree')
    output.mkdir(mode=0o700)
    archive = output / 'source.tar.gz'
    tc12.git(folder, 'archive', '--format=tar.gz', '--output=' + str(archive.resolve()), SOURCE)
    with tarfile.open(output / 'assets.tar.gz', 'w:gz') as bundle:
        for file in sorted(asset_files):
            bundle.add(file, arcname=file.relative_to(folder).as_posix(), recursive=False)
    (output / 'staging-base.php').write_bytes(tc12.git(folder, 'show', PREVIOUS + ':' + STAGING))
    (output / 'staging-source.php').write_bytes(tc12.git(folder, 'show', SOURCE + ':' + STAGING))
    history = Path(__file__).with_name('retained-migrations') / Path(HISTORY).name
    require(green.digest(history) == HISTORY_SHA256, 'Historical migration artifact changed')
    shutil.copy2(history, output / 'retained-failed-jobs.php')
    proof = {'source_sha': SOURCE, 'tested_sha': SOURCE, 'ops_sha': ops_sha, 'previous_sha': PREVIOUS,
             'archive_sha256': green.digest(archive), 'assets_sha256': green.digest(output / 'assets.tar.gz'),
             'previous_files': before, 'source_files': after, 'runtime_delta': sorted(changed),
             'asset_files': {p.relative_to(folder).as_posix(): green.digest(p) for p in asset_files},
             'authorized_migration': P13, 'deferred_migration': P11, 'ci_run': False, 'seed_run': False}
    (output / 'proof.json').write_text(json.dumps(proof, indent=2))
    green.emit('app_release_packaged', {'source_sha': SOURCE, 'ops_sha': ops_sha,
                                      'runtime_files_changed': len(changed), 'assets': len(asset_files)})


def merge_staging(runtime, base, candidate):
    require(sha(runtime) == green.OVERLAYS[STAGING], 'Runtime staging overlay drift')
    with tempfile.TemporaryDirectory(prefix='app-overlay-') as directory:
        paths = [Path(directory) / name for name in ('runtime.php', 'base.php', 'source.php')]
        for path, content in zip(paths, (runtime, base, candidate)):
            path.write_bytes(content)
        result = subprocess.run(['git', 'merge-file', '-p', *map(str, paths)], capture_output=True)
    require(result.returncode == 0, 'Staging three-way merge conflicts; serving code untouched')
    value = result.stdout
    require(b'<<<<<<<' not in value and value.count(b'P13WebPostHandoff::record(') == 1
            and value.count(b'use Modules\\Warehouse\\Support\\P13WebPostHandoff;') == 1,
            'Merged staging hook invalid')
    return value


def configuration(service='backend'):
    code = r'''require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo json_encode(['sha'=>config('health.build.sha'),'environment'=>config('app.env'),
      'database'=>Illuminate\Support\Facades\DB::connection()->getDatabaseName(),
      'protected'=>(bool)config('deployment_safety.protect_database'),
      'allow_seed'=>(bool)config('deployment_safety.allow_protected_database_seed'),
      'p11'=>(bool)config('p11.security_commands_enabled',false),
      'p12'=>(bool)config('p12_mixed_documents.enabled'),'p14'=>(bool)config('p14.shift_enabled'),
      'p14_recovery'=>(bool)config('p14.recovery_enabled'),'p19'=>(bool)config('p19.commands_enabled'),
      'session_replacement'=>(bool)config('refresh_token.single_active_session.enabled'),
      'body_transport'=>(bool)config('refresh_token.body_transport.enabled'),
      'body_origins_hash'=>hash('sha256',json_encode(config('refresh_token.body_transport.allowed_origins'))),
      'cached'=>app()->configurationIsCached(),'routes_cached'=>app()->routesAreCached()]);'''
    return json.loads(green.run(['docker', 'exec', '-u', 'www-data', 'hoa_nam_green-' + service + '-1', 'php', '-r', code]))


def migration_state():
    code = r'''require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $m=$app->make('migrator'); $files=$m->getMigrationFiles(array_merge([database_path('migrations')],$m->paths()));
    $ran=$m->getRepository()->getRan(); echo json_encode(['ran'=>$ran,
      'pending'=>array_values(array_diff(array_keys($files),$ran)),
      'orphaned'=>array_values(array_diff($ran,array_keys($files)))]);'''
    return json.loads(green.run(['docker', 'exec', '-u', 'www-data', 'hoa_nam_green-backend-1', 'php', '-r', code]))


def assert_business_unchanged(before, after):
    require(set(after) == set(before) | {'mini_app_web_post_handoffs'}, 'Unexpected table changes')
    require({k: v for k, v in before.items() if k != 'migrations'}
            == {k: v for k, v in after.items() if k not in ('migrations', 'mini_app_web_post_handoffs')},
            'Existing business table fingerprint changed; no automatic restore')
    require(after['mini_app_web_post_handoffs']['count'] == 0
            and after['migrations']['count'] == before['migrations']['count'] + 1,
            'Unexpected schema data changes')


def business_counts():
    return json.loads(green.pg("SELECT json_build_object('sku_total',(SELECT count(*) FROM sku),"
                              "'published_skus',(SELECT count(*) FROM sku WHERE published_to_app=true),"
                              "'media_rows',(SELECT count(*) FROM pv_product_images),"
                              "'items_in_stock',(SELECT count(*) FROM item_instances WHERE item_status='IN_STOCK'))"))


def public_probe(base):
    result = []
    for path in ('/api/v1/health', '/api/v1/auth/me', '/api/v1/mini-app/documents?cursor=start'):
        request = urllib.request.Request(base + path, headers={'Accept': 'application/json', 'Cache-Control': 'no-cache'})
        try:
            response = urllib.request.urlopen(request, timeout=25)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            raw = response.read().decode()
            value = json.loads(raw)
            expected = 200 if path.endswith('/health') else 401
            require(response.status == expected, 'Public health/unauthenticated guard failed')
            if expected == 200:
                require(value['data']['build']['sha'] == SOURCE, 'Public release SHA mismatch')
            result.append({'method': 'GET', 'url': base + path, 'status': response.status, 'raw_body': raw,
                           'headers': {k: v for k, v in response.headers.items()
                                       if k.lower() not in ('authorization', 'cookie', 'set-cookie')},
                           'observed_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                           'authenticated': False})
    return result


def deploy(folder, ops_sha, inspect_only):
    os.umask(0o077)
    require(__debug__, 'Optimized Python prohibited')
    require(green.ROOT.resolve() == green.ROOT, 'Deployment root changed')
    previous = (green.ROOT / 'current').resolve()
    require(previous == green.ROOT / 'releases' / PREVIOUS_RELEASE, 'Serving release changed')
    proof = json.loads((folder / 'proof.json').read_text())
    require(proof['source_sha'] == SOURCE and proof['ops_sha'] == ops_sha and proof['previous_sha'] == PREVIOUS
            and proof['authorized_migration'] == P13 and proof['deferred_migration'] == P11, 'Proof mismatch')
    require(green.digest(folder / 'source.tar.gz') == proof['archive_sha256']
            and green.digest(folder / 'assets.tar.gz') == proof['assets_sha256'], 'Archive mismatch')
    before_policy = configuration()
    require(before_policy['sha'] == PREVIOUS and before_policy['environment'] == 'testing'
            and before_policy['database'] == green.DATABASE and before_policy['protected']
            and not before_policy['allow_seed'] and not before_policy['p11'] and not before_policy['p19']
            and not before_policy['p14_recovery'] and before_policy['session_replacement'], 'Target policy mismatch')
    before_migrations = migration_state()
    green.emit('app_existing_schema', {'ran_count': len(before_migrations['ran']),
                                      'pending': before_migrations['pending'], 'orphaned': before_migrations['orphaned']})
    require(len(before_migrations['ran']) == 218 and not before_migrations['pending']
            and before_migrations['orphaned'] == [Path(HISTORY).stem], 'Existing schema history changed')
    columns = json.loads(green.pg("SELECT json_agg(json_build_array(column_name,data_type,is_nullable) ORDER BY ordinal_position) "
                                 "FROM information_schema.columns WHERE table_schema='public' AND table_name='failed_jobs'"))
    green.emit('app_retained_history_schema', {'table': 'failed_jobs', 'columns': columns,
                                             'retained_artifact_sha256': HISTORY_SHA256})
    expected_columns = [['id', 'bigint', 'NO'], ['uuid', 'character varying', 'NO'], ['connection', 'text', 'NO'],
                        ['queue', 'text', 'NO'], ['payload', 'text', 'NO'], ['exception', 'text', 'NO'],
                        ['failed_at', 'timestamp without time zone', 'NO']]
    require(columns == expected_columns, 'Historical failed-jobs schema does not match retained source')
    require(green.pg("SELECT count(*) FROM pg_indexes WHERE schemaname='public' AND tablename='failed_jobs' "
                     "AND indexdef LIKE 'CREATE UNIQUE INDEX% (uuid)'") == '1', 'Historical queue UUID guard missing')
    require(green.digest(folder / 'retained-failed-jobs.php') == HISTORY_SHA256, 'Historical artifact checksum changed')
    require(green.pg("SELECT to_regclass('public.mini_app_web_post_handoffs') IS NULL AND "
                     "to_regclass('public.p11_security_commands') IS NULL AND "
                     "to_regclass('public.p11_security_reconciliations') IS NULL") == 't', 'New tables already exist')
    for name, expected in proof['previous_files'].items():
        require(green.digest(previous / name) == green.OVERLAYS.get(name, expected), 'Runtime drift: ' + name)
    for name, expected in green.OVERLAYS.items():
        require(green.digest(previous / name) == expected, 'Overlay drift: ' + name)
    base = (folder / 'staging-base.php').read_bytes()
    candidate = (folder / 'staging-source.php').read_bytes()
    require(sha(base) == proof['previous_files'][STAGING] and sha(candidate) == proof['source_files'][STAGING],
            'Staging merge input changed')
    merged = merge_staging((previous / STAGING).read_bytes(), base, candidate)
    services, images, vendors = [], {}, set()
    for service in ('backend', 'queue', 'scheduler'):
        row = json.loads(green.run(['docker', 'inspect', 'hoa_nam_green-' + service + '-1']))[0]
        require(row['State']['Running'] and row['Image'] == green.IMAGE_ID
                and row['Config']['Labels']['com.docker.compose.project'] == green.PROJECT, 'Service/image mismatch')
        require('AUTO_MIGRATE=false' in row['Config']['Env'], 'Startup migration enabled')
        require(any(m['Destination'] == '/var/www/html' and m['Source'] == str(previous) for m in row['Mounts']),
                'Source mount mismatch')
        vendors.add(next(m['Name'] for m in row['Mounts'] if m['Destination'] == '/var/www/html/vendor'))
        services.append(service)
        images[service] = row['Image']
    require(len(vendors) == 1, 'Vendor mounts differ')
    vendor = vendors.pop()
    require(green.run(['docker', 'exec', 'hoa_nam_green-backend-1', 'cat', 'vendor/.composer-lock.sha256']).strip()
            == proof['source_files']['composer.lock'], 'Vendor lock mismatch')
    with tarfile.open(folder / 'source.tar.gz') as bundle:
        source_bytes = sum(m.size for m in bundle.getmembers() if m.isfile())
    storage_bytes = int(green.run(['du', '-sb', str(previous / 'storage')]).split()[0])
    db_bytes = int(green.pg('SELECT pg_database_size(current_database())'))
    required = RESERVE + source_bytes + storage_bytes + db_bytes + 128 * 1024 ** 2
    capacity = {'free_bytes': shutil.disk_usage(green.ROOT).free, 'required_bytes': required,
                'reserve_bytes': RESERVE, 'database_bytes': db_bytes, 'storage_bytes': storage_bytes}
    green.emit('app_release_preflight', {'source_sha': SOURCE, 'ops_sha': ops_sha, 'policy': before_policy,
                                       'migration_count': 218, 'capacity': capacity,
                                       'overlays': len(green.OVERLAYS), 'staging_merged_sha256': sha(merged),
                                       'migration_only': P13, 'p11_schema_deferred': True})
    require(capacity['free_bytes'] > required, 'Insufficient disk reserve; no cleanup authorized')
    if inspect_only:
        return
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    release = green.ROOT / 'releases' / (SOURCE + '-app-' + stamp)
    report = green.ROOT / 'backups' / ('deploy-app-' + stamp + '.json')
    evidence = {'source_sha': SOURCE, 'tested_sha': SOURCE, 'ops_sha': ops_sha, 'previous_release': str(previous),
                'release': str(release), 'capacity': capacity, 'images': images, 'vendor': vendor,
                'policy_before': before_policy, 'overlay_sha256_before': green.OVERLAYS,
                'staging_merged_sha256': sha(merged), 'ci_run': False, 'seed_run': False, 'data_restore': False,
                'retained_historical_migration': {'path': HISTORY, 'sha256': HISTORY_SHA256,
                                                 'original_commit': 'ebfd201c8fff74558de5b1bd14b36718bec1813d',
                                                 'already_applied': True, 'reexecuted': False},
                'p11_activation': False, 'production_activation': False, 'stage': 'prepare'}
    paused = stopped = switched = reopened = False
    try:
        release.mkdir(mode=0o755)
        os.chmod(release, 0o755)
        for archive in ('source.tar.gz', 'assets.tar.gz'):
            with tarfile.open(folder / archive) as bundle:
                require(all(green.safe_archive_member(release, m) for m in bundle.getmembers()), 'Unsafe archive')
                if archive == 'assets.tar.gz':
                    require(all(m.name.startswith('public/build/') for m in bundle.getmembers()), 'Unsafe asset path')
                bundle.extractall(release)
        for name, expected in proof['source_files'].items():
            require(green.digest(release / name) == expected, 'Source archive mismatch: ' + name)
        for name, expected in proof['asset_files'].items():
            require(green.digest(release / name) == expected, 'Asset mismatch')
        for name in green.OVERLAYS:
            (release / name).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(previous / name, release / name)
        (release / STAGING).write_bytes(merged)
        validate_migration_delta(green.migrations(previous), green.migrations(release))
        shutil.copy2(folder / 'retained-failed-jobs.php', release / HISTORY)
        info = (previous / '.env').stat()
        (release / '.env').write_text(tc12.environment((previous / '.env').read_text(), {
            'APP_BUILD_SHA': SOURCE, 'APP_BUILD_REF': 'dev@a9053a21+app-hardening+preserved-runtime-hotfixes',
            'APP_BUILD_TIME': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'AUTO_MIGRATE': 'false'}))
        os.chown(release / '.env', info.st_uid, info.st_gid)
        os.chmod(release / '.env', 0o640)
        shutil.copy2(previous / 'docker-compose.vendor-release.yml', release / 'docker-compose.vendor-release.yml')
        model = json.loads(green.compose(release, ['config', '--format', 'json']))
        for service in services:
            value = model['services'][service]
            require(value['image'] == images[service] and value['environment']['AUTO_MIGRATE'] == 'false'
                    and value['environment']['DB_DATABASE'] == green.DATABASE, 'Resolved target mismatch')
        green.run(['docker', 'run', '--rm', '--network', 'none', '-v', str(release) + ':/var/www/html:ro',
                   '--entrypoint', 'php', green.IMAGE_ID, '-l', '/var/www/html/' + STAGING])
        evidence['stage'] = 'pause-and-backup'
        green.artisan('down', '--retry=30')
        paused = stopped = True
        green.compose(previous, ['stop', '-t', '120', *services], timeout=240)
        before_data = green.fingerprints()
        before_counts = business_counts()
        before_media = green.media_manifest(previous)
        backup = green.ROOT / 'backups' / ('wms_hoanam_green_pre_app_' + stamp + '.dump')
        with open(backup, 'xb') as stream:
            result = subprocess.run(['docker', 'exec', 'hoa_nam_green-postgres-1', 'pg_dump', '-U', 'postgres',
                                     '-d', green.DATABASE, '-Fc'], stdout=stream, stderr=subprocess.PIPE, timeout=240)
        os.chmod(backup, 0o600)
        require(result.returncode == 0 and backup.stat().st_size > 100000, 'Backup failed')
        with open(backup, 'rb') as stream:
            result = subprocess.run(['docker', 'exec', '-i', 'hoa_nam_green-postgres-1', 'pg_restore', '--list'],
                                    stdin=stream, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
        require(result.returncode == 0 and b'TABLE DATA' in result.stdout, 'Backup catalog invalid')
        evidence['backup'] = {'path': str(backup), 'bytes': backup.stat().st_size, 'sha256': green.digest(backup),
                              'catalog_validated': True, 'full_restore_drill': False}
        evidence['before_tables'] = before_data
        shutil.copytree(previous / 'storage', release / 'storage', dirs_exist_ok=True, symlinks=True)
        require(before_media == green.media_manifest(release), 'Media copy mismatch')
        require(shutil.disk_usage(green.ROOT).free > RESERVE, 'Disk reserve lost')
        green.run(['docker', 'run', '--rm', '--network', 'none', '-v', str(release) + ':/var/www/html',
                   '--entrypoint', '/usr/local/bin/ensure-runtime-permissions.sh', green.IMAGE_ID], timeout=180)
        evidence['stage'] = 'approved-p13-migration-only'
        green.compose(release, ['run', '--rm', '--no-deps', '-T', '--user', 'www-data', '--entrypoint', 'php',
                                'backend', 'artisan', 'migrate', '--path=' + P13, '--force', '--no-interaction'], timeout=180)
        evidence['migration_run'] = P13
        green.emit('app_p13_migrated', {'migration': P13, 'seed_run': False, 'p11_migrated': False})
        evidence['stage'] = 'switch'
        green.switch(release, stamp)
        switched = True
        green.compose(release, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', 'backend', 'nginx'])
        tc12.wait_ready()
        green.artisan('package:discover')
        for command in ('config:clear', 'route:clear', 'event:clear', 'view:clear', 'config:cache', 'route:cache'):
            green.artisan(command)
        green.artisan('l5-swagger:generate', timeout=180)
        green.run(['docker', 'exec', 'hoa_nam_green-nginx-1', 'nginx', '-t'])
        after_policy = configuration()
        require(after_policy['sha'] == SOURCE and {k: v for k, v in before_policy.items() if k != 'sha'}
                == {k: v for k, v in after_policy.items() if k != 'sha'}, 'Runtime policy changed')
        after_migrations = migration_state()
        require(after_migrations['pending'] == [Path(P11).stem] and not after_migrations['orphaned']
                and set(after_migrations['ran']) == set(before_migrations['ran']) | {Path(P13).stem},
                'Unexpected applied/pending schema')
        after_data = green.fingerprints()
        assert_business_unchanged(before_data, after_data)
        after_counts = business_counts()
        require(before_counts == after_counts, 'Product/image/stock view counts changed')
        require(before_media == green.media_manifest(release), 'Media changed')
        routes = json.loads(green.artisan('route:list', '--json', '--path=api/v1'))
        required_routes = {'api/v1/mini-app/documents', 'api/v1/containers',
                           'api/v1/auth/security-requests/{key}', 'api/v1/auth/security-requests/{key}/verify'}
        actual_routes = {row['uri'] for row in routes}
        require(required_routes.issubset(actual_routes), 'Required routes missing')
        evidence.update({'policy_after': after_policy, 'migrations': after_migrations,
                         'migration_count': len(after_migrations['ran']), 'business_data_unchanged': True,
                         'media_unchanged': True, 'comparison_scope': 'MAINTENANCE_BEFORE_REOPEN',
                         'verified_existing_tables': len(before_data) - 1, 'media_files': len(before_media),
                         'new_handoff_rows': after_data['mini_app_web_post_handoffs']['count'],
                         'routes': [row for row in routes if row['uri'] in required_routes],
                         'source_sha256_after': {name: green.digest(release / name) for name in green.OVERLAYS},
                         'swagger_sha256': green.digest(release / 'storage/api-docs/api-docs.json')})
        green.emit('app_release_verified_maintenance', {
            'policy': after_policy, 'schema': {'applied': len(after_migrations['ran']),
                                             'pending': after_migrations['pending'], 'orphaned': after_migrations['orphaned']},
            'counts_before': before_counts, 'counts_after': after_counts, 'routes': evidence['routes'],
            'backup': evidence['backup'], 'retained_history': evidence['retained_historical_migration'],
            'staging_merged_sha256': sha(merged), 'overlay_sha256_after': evidence['source_sha256_after'],
            'swagger_sha256': evidence['swagger_sha256'], 'business_data_unchanged': True, 'media_unchanged': True})
        green.artisan('up')
        paused = False
        reopened = True
        green.compose(release, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', 'queue', 'scheduler'])
        green.compose(release, ['restart', 'backend'])
        tc12.wait_ready()
        for service in services:
            require(configuration(service)['sha'] == SOURCE and not configuration(service)['p11'], 'Worker release/flag mismatch')
        evidence['stage'] = 'public-origin-proxy-smoke'
        evidence['public_http'] = public_probe(green.ORIGIN) + public_probe('https://appqr.lptech.info.vn/wms-api')
        evidence.update({'stage': 'complete', 'completed_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                         'free_bytes_after': shutil.disk_usage(green.ROOT).free, 'authenticated_smoke': False})
        (release / 'release-operations.json').write_text(json.dumps(evidence, indent=2))
        green.emit('app_release_deployed', {k: evidence[k] for k in ('source_sha', 'ops_sha', 'stage', 'migration_count',
                   'business_data_unchanged', 'media_unchanged', 'new_handoff_rows', 'verified_existing_tables',
                   'media_files', 'free_bytes_after', 'p11_activation', 'authenticated_smoke')})
        green.emit('app_release_public_http', evidence['public_http'])
    except Exception as error:
        evidence['error_type'] = type(error).__name__
        green.emit('app_release_failed', {'stage': evidence['stage'], 'error_type': type(error).__name__,
                                        'safe_message': str(error) if isinstance(error, RuntimeError) else 'See private report'})
        if not reopened:
            if switched:
                green.switch(previous, stamp)
            if stopped or paused:
                green.compose(previous, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', *services, 'nginx'])
                green.artisan('up')
                evidence['application_rollback'] = True
            evidence['schema_retained_no_restore'] = True
        else:
            evidence['manual_recovery_required'] = True
        raise
    finally:
        report.write_text(json.dumps(evidence, indent=2))
        os.chmod(report, 0o600)
        green.emit('app_release_report', {'path': str(report)})


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--package', action='store_true')
    parser.add_argument('--checkout')
    parser.add_argument('--ops-sha', required=True)
    parser.add_argument('--output')
    parser.add_argument('--source')
    parser.add_argument('--inspect', action='store_true')
    args = parser.parse_args()
    if args.package:
        package(Path(args.checkout), args.ops_sha, Path(args.output))
    else:
        deploy(Path(args.source), args.ops_sha, args.inspect)
