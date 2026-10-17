#!/usr/bin/env python3
"""Promote the exact retained CI application build to green DEV/TEST only."""

import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import time
import urllib.request


SOURCE_SHA = '898d447eba868f0fb2ea6362b2e4766534ddf76a'
PREVIOUS_SHA = '49bceda27ebc6a2de83d43342f8216860b3d708a'
IMAGE_ID = 'sha256:69aad9b47acd88192a463c72045fb8ae77789719bbcc7421226e93c91eadd44b'
ROOT = Path('/opt/hoa-nam-green')
PROJECT = 'hoa_nam_green'
DATABASE = 'wms_hoanam_green'
ORIGIN = 'https://khohoanamdev.lptech.info.vn'
RESERVE = 1024 ** 3
OVERLAYS = {
    'config/cache.php': '05011f2f7339aa666af885aa82acc643e416e64d1b07d945e3d7ef204a54f7b5',
    'database/migrations/2026_10_07_000003_add_p04_category_and_daily_numbers.php': '0c49ebe8e92809a1488d3d920bda59f94e6182e72b438dfbd63b87b79f267a3d',
    'database/migrations/2026_10_08_000001_add_p04_draft_numbers.php': '9b55566f6db32030b53544e6c4e1451fb27847fb1eead497fdde35f019b3fc79',
    'docker-compose.yml': '4ff45aff14596e01d498a08ec3e409b971062d9914b00ac3ea96c03b38ae6cad',
    'Modules/Warehouse/app/Http/Controllers/InventoryBalanceController.php': '88b6c60793a42a0a754970b691618d565e93774e338141eecb9ab93629914ed2',
    'Modules/Warehouse/app/Http/Controllers/MiniAppInboundStagingController.php': 'b83b0e72177175e3c0e8b64291465000f360f538670f259cadb49f313d993b57',
    'Modules/Warehouse/app/Http/Controllers/MiniAppProductController.php': '28172889560ece9ad24c3f2b2bd1a6643216cfb08f676e8c97b1562f9cb2679d',
    'Modules/Warehouse/app/Http/Controllers/P04InboundController.php': '8274a8af9ba0335d16476e2c69f6bf9a186e8d236fdfce377ffb6488adff4e45',
    'Modules/Warehouse/app/Services/InboundDocumentNumber.php': 'a2d7dc4763776af5870e276494d3284fb5bec7d755c30095ac678975edcf35f6',
    'Modules/Warehouse/app/Services/StockDocumentService.php': '50a299f1a611ecc77a9cd72f75e2ac76a5f14683b1108f79024491223c0cf3c2',
    'Modules/Warehouse/database/seeders/DongChengCatalogSeeder.php': '32fcef5f6511ace0b5f3fa083a15926d965ebb7a9f976b4c7138f94a7e068c21',
    'p04-draft-number-release.json': 'e14f54e9efffd9701d42689b69bdeaf7e494bf53e7e3c8537d57416349cc1630',
    'p04-global-sendfix-release.json': '706ae5e8df77c30905de8c7ac1472a2f95799d18596c144e54c77f26dc6230ca',
    'p04-ui-options-release.json': '0888431b4593aba2078ebe5d20da66569c57d6f13cb0280873116103a9d8c627',
}


def digest(path):
    result = hashlib.sha256()
    with open(path, 'rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def emit(event, data):
    print(json.dumps({'event': event, 'data': data}), flush=True)


def run(args, *, cwd=None, input_data=None, timeout=120):
    result = subprocess.run(args, cwd=cwd, input=input_data, text=True,
                            capture_output=True, timeout=timeout)
    if result.returncode:
        # Never forward arbitrary application/Composer output into deployment logs.
        raise RuntimeError(f'{args[0]} exited {result.returncode}')
    return result.stdout


def compose(folder, args, timeout=180):
    return run(['docker', 'compose', '-p', PROJECT, '-f', 'docker-compose.yml',
                '-f', 'docker-compose.vendor-release.yml', *args], cwd=folder, timeout=timeout)


def artisan(*args, timeout=120):
    value = run(['docker', 'exec', '-u', 'www-data', f'{PROJECT}-backend-1',
                 'php', 'artisan', *args], timeout=timeout)
    emit('artisan', {'command': ' '.join(args), 'exit_code': 0})
    return value


def pg(query):
    return run(['docker', 'exec', f'{PROJECT}-postgres-1', 'psql', '-X', '-q', '-At',
                '-U', 'postgres', '-d', DATABASE, '-v', 'ON_ERROR_STOP=1', '-c', query]).strip()


def fingerprints():
    tables = json.loads(pg("SELECT json_agg(tablename ORDER BY tablename) FROM pg_tables WHERE schemaname='public'"))
    parts = []
    for table in tables:
        if not re.fullmatch(r'[a-zA-Z0-9_]+', table):
            raise RuntimeError('Unexpected database identifier')
        parts.append(f"SELECT '{table}' name,count(*) count,"
                     f"md5(COALESCE(string_agg(to_jsonb(t)::text,'' ORDER BY to_jsonb(t)::text),'')) hash FROM \"{table}\" t")
    return json.loads(pg('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY; '
                         "SELECT json_object_agg(name,json_build_object('count',count,'hash',hash)) FROM ("
                         + ' UNION ALL '.join(parts) + ') t; COMMIT;'))


def media_manifest(folder):
    result = {}
    base = folder / 'storage/app'
    for path in base.rglob('*'):
        if path.is_symlink():
            result[str(path.relative_to(base))] = 'link:' + os.readlink(path)
        elif path.is_file():
            result[str(path.relative_to(base))] = digest(path)
    return result


def migrations(folder):
    directories = [folder / 'database/migrations', *folder.glob('Modules/*/database/migrations')]
    return {str(p.relative_to(folder)): digest(p) for directory in directories
            for p in directory.glob('*.php')}


def policy():
    php = r'''require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo json_encode(['sha'=>config('health.build.sha'),
    'database'=>Illuminate\Support\Facades\DB::connection()->getDatabaseName(),
    'environment'=>config('app.env'),'p12'=>(bool)config('p12_mixed_documents.enabled'),
    'p14'=>(bool)config('p14.shift_enabled'),'p14_recovery'=>(bool)config('p14.recovery_enabled'),
    'p19'=>(bool)config('p19.commands_enabled'),
    'p19_recovery'=>(bool)config('p19.commands_enabled'),
    'protected'=>(bool)config('deployment_safety.protect_database'),
    'allow_seed'=>(bool)config('deployment_safety.allow_protected_database_seed')]);'''
    return json.loads(run(['docker', 'exec', '-u', 'www-data', f'{PROJECT}-backend-1', 'php', '-r', php]))


def switch(target, stamp):
    pending = ROOT / ('current.pending-' + stamp)
    if pending.exists() or pending.is_symlink():
        raise RuntimeError('Unexpected pending release link')
    pending.symlink_to(target)
    os.replace(pending, ROOT / 'current')


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser()
    parser.add_argument('--archive', required=True)
    parser.add_argument('--proof', required=True)
    args = parser.parse_args()
    proof = json.loads(Path(args.proof).read_text())
    assert proof['source_sha'] == SOURCE_SHA and proof['ci_run'] == 39052882961
    assert proof['application_gates_passed'] is True
    assert proof['distribution_failure'] == 'ARTIFACT_STORAGE_QUOTA'
    assert proof['image_id'] == IMAGE_ID and digest(args.archive) == proof['source_archive_sha256']
    assert ROOT.resolve() == ROOT and (ROOT / 'current').is_symlink()
    previous = (ROOT / 'current').resolve()
    assert previous == ROOT / 'releases' / (PREVIOUS_SHA + '-code-only-20261015T155459Z')
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    release = ROOT / 'releases' / (SOURCE_SHA + '-verified-' + stamp)
    vendor = f'{PROJECT}_vendor_{SOURCE_SHA[:8]}_{stamp.lower()}'
    report = ROOT / 'backups' / ('deploy-verified-' + SOURCE_SHA[:8] + '-' + stamp + '.json')
    evidence = {'source_sha': SOURCE_SHA, 'ops_sha': proof['ops_sha'], 'ci_run': proof['ci_run'],
                'retained_artifact_sha256': proof['retained_artifact_sha256'], 'image_id': IMAGE_ID,
                'ci_conclusion': 'FAILURE_ARTIFACT_UPLOAD_QUOTA_ONLY', 'application_gates_passed': True,
                'previous_release': str(previous), 'release': str(release), 'vendor_volume': vendor,
                'migration_run': False, 'seed_run': False, 'database_restore': False,
                'feature_flags_changed': False, 'stage': 'prepare'}
    paused = False
    switched = False
    stopped = False
    app_services = []
    try:
        before_policy = policy()
        assert before_policy['sha'] == PREVIOUS_SHA and before_policy['database'] == DATABASE
        assert before_policy['environment'] == 'testing' and before_policy['protected']
        assert not before_policy['allow_seed'] and not before_policy['p19']
        assert not before_policy['p14_recovery'] and not before_policy['p19_recovery']
        evidence['policy_before'] = before_policy
        assert run(['docker', 'image', 'inspect', IMAGE_ID, '--format', '{{.Id}}']).strip() == IMAGE_ID
        names = run(['docker', 'ps', '-a', '--filter', 'label=com.docker.compose.project=' + PROJECT,
                     '--format', '{{.Names}}']).splitlines()
        for service in ('backend', 'queue', 'scheduler', 'reverb'):
            name = f'{PROJECT}-{service}-1'
            if name not in names:
                continue
            row = json.loads(run(['docker', 'inspect', name]))[0]
            assert row['Config']['Labels']['com.docker.compose.project'] == PROJECT
            assert 'AUTO_MIGRATE=false' in row['Config']['Env']
            assert any(m['Destination'] == '/var/www/html' and m['Source'] == str(previous) for m in row['Mounts'])
            app_services.append(service)
            evidence.setdefault('rollback_images', {})[service] = row['Image']
        assert set(('backend', 'queue', 'scheduler')).issubset(app_services)
        for filename, expected in OVERLAYS.items():
            assert digest(previous / filename) == expected, 'Runtime overlay drift: ' + filename
        storage_bytes = int(run(['du', '-sb', str(previous / 'storage')]).split()[0])
        with tarfile.open(args.archive) as bundle:
            source_bytes = sum(m.size for m in bundle.getmembers() if m.isfile())
        vendor_bytes = int(run(['du', '-sb', '/opt/actions-runner/hoa-nam-vps42/_work/WMS_Warehouse_Hoa_Nam_BE/WMS_Warehouse_Hoa_Nam_BE/vendor']).split()[0])
        required = RESERVE + source_bytes + storage_bytes + 2 * vendor_bytes + 256 * 1024 ** 2
        free = shutil.disk_usage(ROOT).free
        evidence['capacity'] = {'free_before': free, 'required': required, 'reserve': RESERVE}
        assert free > required, 'Insufficient disk reserve; no automatic cleanup'
        release.mkdir()
        with tarfile.open(args.archive) as bundle:
            for member in bundle.getmembers():
                target = (release / member.name).resolve()
                assert target.is_relative_to(release) and (member.isdir() or member.isfile()), 'Unsafe source archive'
            bundle.extractall(release)
        for filename in OVERLAYS:
            target = release / filename
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(previous / filename, target)
        assert migrations(previous) == migrations(release), 'Unreviewed migration delta'
        assert digest(release / 'composer.lock') == proof['composer_lock_sha256']
        shutil.copy2(previous / '.env', release / '.env')
        env = (release / '.env').read_text()
        build_ref = 'dev@898d447e+preserved-runtime-hotfixes+verified-ci39052882961'
        settings = {'APP_BUILD_SHA': SOURCE_SHA, 'APP_BUILD_REF': build_ref,
                    'APP_BUILD_TIME': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                    'AUTO_MIGRATE': 'false', 'WMS_PROTECT_DEPLOYMENT_DATABASE': 'true',
                    'APP_URL': ORIGIN, 'L5_SWAGGER_CONST_HOST': ORIGIN,
                    'COMPOSE_FILE': 'docker-compose.yml:docker-compose.vendor-release.yml'}
        for key, value in settings.items():
            updated, count = re.subn(r'(?m)^' + re.escape(key) + r'=.*$', key + '=' + value, env)
            assert count <= 1, 'Duplicate environment key: ' + key
            env = updated if count else env.rstrip('\n') + '\n' + key + '=' + value + '\n'
        (release / '.env').write_text(env)
        info = (previous / '.env').stat()
        os.chown(release / '.env', info.st_uid, info.st_gid)
        os.chmod(release / '.env', 0o640)
        override = 'volumes:\n  wms_vendor:\n    name: ' + vendor + '\nservices:\n'
        for service in app_services:
            override += f'  {service}:\n    image: {IMAGE_ID}\n'
        (release / 'docker-compose.vendor-release.yml').write_text(override)
        compose(release, ['config', '--quiet'])
        model = json.loads(compose(release, ['config', '--format', 'json']))
        for service in app_services:
            actual = model['services'][service]
            assert actual['environment']['AUTO_MIGRATE'] == 'false'
            assert actual['environment']['DB_DATABASE'] == DATABASE
            assert actual['image'] == IMAGE_ID
        assert not run(['docker', 'volume', 'ls', '--filter', 'name=^' + vendor + '$', '-q']).strip()
        run(['docker', 'volume', 'create', '--label', 'com.hoanam.release=' + SOURCE_SHA, vendor])
        ci_vendor = '/opt/actions-runner/hoa-nam-vps42/_work/WMS_Warehouse_Hoa_Nam_BE/WMS_Warehouse_Hoa_Nam_BE/vendor'
        run(['docker', 'run', '--rm', '--network', 'none', '-v', ci_vendor + ':/ci-vendor:ro',
             '-v', vendor + ':/new-vendor', '--entrypoint', 'sh', IMAGE_ID,
             '-lc', 'cp -a /ci-vendor/. /new-vendor/'], timeout=180)
        lock_hash = digest(release / 'composer.lock')
        run(['docker', 'run', '--rm', '--network', 'none', '-e', 'COMPOSER_DISABLE_NETWORK=1',
             '-e', 'COMPOSER_ALLOW_SUPERUSER=1', '-v', str(release) + ':/var/www/html',
             '-v', vendor + ':/var/www/html/vendor', '--entrypoint', 'sh', IMAGE_ID,
             '-lc', 'composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts '
             '&& composer check-platform-reqs --lock '
             '&& sha256sum composer.lock | cut -d " " -f1 > vendor/.composer-lock.sha256'], timeout=180)
        evidence['vendor_policy'] = 'FRESH_PRIVATE_VOLUME_LOCKED_INSTALL_NETWORK_DISABLED'
        evidence['composer_lock_sha256'] = lock_hash
        evidence['overlays'] = OVERLAYS
        (release / 'release-operations.json').write_text(json.dumps(evidence, indent=2))
        emit('prepared', {'release': str(release), 'overlays': len(OVERLAYS), 'private_vendor': vendor})
        evidence['stage'] = 'backup'
        artisan('down', '--retry=30')
        paused = True
        stopped = True
        compose(previous, ['stop', '-t', '120', *app_services], timeout=240)
        before_data = fingerprints()
        before_media = media_manifest(previous)
        backup = ROOT / 'backups' / ('wms_hoanam_green_pre_898d447e_' + stamp + '.dump')
        with open(backup, 'xb') as stream:
            result = subprocess.run(['docker', 'exec', f'{PROJECT}-postgres-1', 'pg_dump',
                                     '-U', 'postgres', '-d', DATABASE, '-Fc'],
                                    stdout=stream, stderr=subprocess.PIPE, timeout=180)
        os.chmod(backup, 0o600)
        assert result.returncode == 0 and backup.stat().st_size > 100000, 'Backup failed'
        evidence['backup'] = {'path': str(backup), 'sha256': digest(backup), 'bytes': backup.stat().st_size}
        evidence['data_before'] = before_data
        shutil.copytree(previous / 'storage', release / 'storage', dirs_exist_ok=True, symlinks=True)
        assert before_media == media_manifest(release), 'Media copy mismatch'
        assert shutil.disk_usage(ROOT).free > RESERVE
        evidence['stage'] = 'switch'
        switch(release, stamp)
        switched = True
        compose(release, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', 'backend', 'nginx'])
        for _ in range(45):
            row = json.loads(run(['docker', 'inspect', f'{PROJECT}-backend-1']))[0]
            if row['State'].get('Health', {}).get('Status') == 'healthy':
                break
            time.sleep(2)
        else:
            raise RuntimeError('Backend startup health timeout')
        artisan('package:discover')
        status = artisan('migrate:status', '--no-ansi')
        assert 'Pending' not in status, 'Unexpected pending migration'
        evidence['migration_count'] = int(pg('SELECT count(*) FROM migrations'))
        assert evidence['migration_count'] == 217
        artisan('config:clear')
        artisan('route:clear')
        artisan('event:clear')
        artisan('view:clear')
        artisan('config:cache')
        artisan('route:cache')
        artisan('l5-swagger:generate', timeout=180)
        run(['docker', 'exec', f'{PROJECT}-nginx-1', 'nginx', '-t'])
        after_policy = policy()
        assert after_policy['sha'] == SOURCE_SHA
        assert {k: v for k, v in before_policy.items() if k != 'sha'} == {k: v for k, v in after_policy.items() if k != 'sha'}
        evidence['policy_after'] = after_policy
        after_data = fingerprints()
        assert before_data == after_data, 'Database fingerprints changed; never restore automatically'
        assert before_media == media_manifest(release), 'Media manifest changed'
        evidence['data_unchanged'] = True
        evidence['media_unchanged'] = True
        evidence['verified_table_count'] = len(before_data)
        evidence['verified_media_file_count'] = len(before_media)
        artisan('up')
        paused = False
        compose(release, ['up', '-d', '--no-build', '--no-deps', '--force-recreate',
                          *[s for s in app_services if s != 'backend']])
        compose(release, ['restart', 'backend'])
        evidence['stage'] = 'health'
        for _ in range(30):
            try:
                request = urllib.request.Request(ORIGIN + '/api/v1/health',
                                                 headers={'Accept': 'application/json', 'Cache-Control': 'no-cache'})
                with urllib.request.urlopen(request, timeout=15) as response:
                    value = json.load(response)
                if value['success'] and value['data']['build']['sha'] == SOURCE_SHA:
                    break
            except Exception:
                pass
            time.sleep(2)
        else:
            raise RuntimeError('Public release health did not match candidate')
        for service in app_services:
            row = json.loads(run(['docker', 'inspect', f'{PROJECT}-{service}-1']))[0]
            assert row['State']['Running'] and row['Image'] == IMAGE_ID
        evidence['health'] = value
        evidence['stage'] = 'complete'
        evidence['free_bytes_after'] = shutil.disk_usage(ROOT).free
        emit('deployed', {'sha': SOURCE_SHA, 'release': str(release), 'data_unchanged': True,
                          'media_unchanged': True, 'migration_run': False, 'seed_run': False,
                          'request_id': value['data']['request_id'], 'free_bytes': evidence['free_bytes_after']})
    except Exception as error:
        evidence['failure'] = str(error)
        emit('deployment_failed', {'stage': evidence['stage'], 'error': str(error)})
        if switched:
            switch(previous, stamp)
        if stopped or paused:
            compose(previous, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', *app_services, 'nginx'])
            artisan('up')
            evidence['application_rollback'] = True
        raise
    finally:
        with open(report, 'w') as stream:
            json.dump(evidence, stream, indent=2)
        os.chmod(report, 0o600)
        emit('evidence_saved', {'path': str(report)})


if __name__ == '__main__':
    main()
