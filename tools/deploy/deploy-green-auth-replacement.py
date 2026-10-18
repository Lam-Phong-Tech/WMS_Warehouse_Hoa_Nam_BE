#!/usr/bin/env python3
"""Operator-approved TC_UAT_05 release, one additive migration, green only."""

import argparse
import datetime
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import time
import urllib.request

spec = importlib.util.spec_from_file_location('green_release', Path(__file__).with_name('deploy-green-verified.py'))
green = importlib.util.module_from_spec(spec)
spec.loader.exec_module(green)

SOURCE = '9d9e87382eea39deb3f3d660b3955110b526ea3c'
PREVIOUS = '898d447eba868f0fb2ea6362b2e4766534ddf76a'
MIGRATION = 'database/migrations/2026_10_18_000001_add_refresh_session_replacement_metadata.php'
ROOT = green.ROOT


def set_environment(text, settings):
    for key, value in settings.items():
        updated, count = re.subn(r'(?m)^' + re.escape(key) + r'=.*$', key + '=' + value, text)
        if count > 1:
            raise RuntimeError('Duplicate environment key: ' + key)
        text = updated if count else text.rstrip('\n') + '\n' + key + '=' + value + '\n'
    return text


def unchanged_tables():
    result = green.fingerprints()
    result.pop('migrations')
    result.pop('refresh_tokens')
    return result


def refresh_fingerprint():
    return json.loads(green.pg("BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY; "
        "SELECT json_build_object('count', count(*), 'hash', md5(COALESCE(string_agg("
        "(to_jsonb(t)-'replaced_by_auth_session_id')::text, '' ORDER BY "
        "(to_jsonb(t)-'replaced_by_auth_session_id')::text), ''))) FROM refresh_tokens t; COMMIT;"))


def migration_names():
    return json.loads(green.pg('SELECT json_agg(migration ORDER BY migration) FROM migrations'))


def column_exists():
    return green.pg("SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' "
        "AND table_name='refresh_tokens' AND column_name='replaced_by_auth_session_id')") == 't'


def configuration(service):
    code = r'''require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo json_encode(['sha'=>config('health.build.sha'),'environment'=>config('app.env'),
      'database'=>Illuminate\Support\Facades\DB::connection()->getDatabaseName(),
      'session_replacement'=>(bool)config('refresh_token.single_active_session.enabled',false),
      'cached'=>app()->configurationIsCached(),'cache_path'=>app()->getCachedConfigPath(),
      'protected'=>(bool)config('deployment_safety.protect_database'),
      'allow_seed'=>(bool)config('deployment_safety.allow_protected_database_seed')]);'''
    return json.loads(green.run(['docker', 'exec', '-u', 'www-data', f'{green.PROJECT}-{service}-1', 'php', '-r', code]))


def verify_migration_delta(old, new, expected_hash):
    previous = {key.replace('\\', '/'): value for key, value in green.migrations(old).items()}
    current = {key.replace('\\', '/'): value for key, value in green.migrations(new).items()}
    if set(current) - set(previous) != {MIGRATION} or set(previous) - set(current):
        raise RuntimeError('Unexpected migration file delta')
    if any(previous[key] != current[key] for key in previous) or current[MIGRATION] != expected_hash:
        raise RuntimeError('Unreviewed migration content delta')


def main():
    if not __debug__:
        raise RuntimeError('Release guards require normal Python assertion mode')
    os.umask(0o077)
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True)
    parser.add_argument('--ops-sha', required=True)
    args = parser.parse_args()
    package = Path(args.source).resolve()
    proof = json.loads((package / 'auth-proof.json').read_text())
    archive = package / 'auth-source.tar.gz'
    assert proof['source_sha'] == SOURCE and proof['ops_sha'] == args.ops_sha
    assert proof['source_archive_sha256'] == green.digest(archive)
    assert proof['local_tests'] == {'sqlite_tests': 179, 'sqlite_assertions': 3190,
                                   'postgres_tests': 56, 'postgres_assertions': 596}
    assert proof['ci_run'] is None and proof['seed_authorized'] is False
    previous = (ROOT / 'current').resolve()
    assert previous == ROOT / 'releases' / (PREVIOUS + '-verified-20261017T195224Z')
    assert ROOT.resolve() == ROOT
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    release = ROOT / 'releases' / (SOURCE + '-uat05-' + stamp)
    report = ROOT / 'backups' / ('deploy-uat05-' + stamp + '.json')
    evidence = {'source_sha': SOURCE, 'ops_sha': args.ops_sha, 'previous_release': str(previous),
        'new_release': str(release), 'stage': 'preflight', 'ci_run': False, 'local_tests': proof['local_tests'],
        'seed_run': False, 'database_restore': False, 'production_activated': False,
        'authorized_flag_change': {'AUTH_SINGLE_ACTIVE_SESSION_ENABLED': True},
        'authorized_migration': MIGRATION, 'migration_run': False}
    stopped = False
    paused = False
    switched = False
    services = []
    try:
        before_policy = green.policy()
        assert before_policy['sha'] == PREVIOUS and before_policy['database'] == green.DATABASE
        assert before_policy['environment'] == 'testing' and before_policy['protected']
        assert not before_policy['allow_seed'] and not before_policy['p19'] and not before_policy['p14_recovery']
        assert not column_exists(), 'Session migration state changed since review'
        before_migrations = migration_names()
        assert len(before_migrations) == 217
        evidence['policy_before'] = before_policy
        free = shutil.disk_usage(ROOT).free
        storage_bytes = int(green.run(['du', '-sb', str(previous / 'storage')]).split()[0])
        with tarfile.open(archive) as bundle:
            source_bytes = sum(member.size for member in bundle.getmembers() if member.isfile())
        required = green.RESERVE + source_bytes + storage_bytes + 128 * 1024 ** 2
        evidence['capacity'] = {'free_before': free, 'required_bytes': required, 'reserve_bytes': green.RESERVE}
        green.emit('uat05_preflight', {'sha': SOURCE, 'free_bytes': free, 'required_bytes': required,
            'migration_count': len(before_migrations), 'seed_run': False})
        assert free > required, 'Insufficient deployment reserve; no automatic deletion'
        for filename, expected in green.OVERLAYS.items():
            assert green.digest(previous / filename) == expected, 'Runtime overlay drift: ' + filename
        vendors = set()
        images = {}
        for service in ('backend', 'queue', 'scheduler', 'reverb'):
            value = subprocess.run(['docker', 'inspect', f'{green.PROJECT}-{service}-1'],
                text=True, capture_output=True, timeout=30)
            if value.returncode:
                if service == 'reverb':
                    continue
                raise RuntimeError('Required application container missing')
            row = json.loads(value.stdout)[0]
            assert row['Config']['Labels']['com.docker.compose.project'] == green.PROJECT
            assert 'AUTO_MIGRATE=false' in row['Config']['Env']
            assert any(m['Destination'] == '/var/www/html' and m['Source'] == str(previous) for m in row['Mounts'])
            services.append(service)
            images[service] = row['Image']
            vendors.add(next(m['Name'] for m in row['Mounts'] if m['Destination'] == '/var/www/html/vendor'))
        assert len(vendors) == 1
        vendor = vendors.pop()
        assert green.digest(previous / 'composer.lock') == proof['composer_lock_sha256']
        assert green.run(['docker', 'exec', f'{green.PROJECT}-backend-1', 'cat',
            'vendor/.composer-lock.sha256']).strip() == proof['composer_lock_sha256']
        evidence.update({'vendor_volume': vendor, 'vendor_policy': 'VERIFIED_UNCHANGED_LOCK_NO_DEPENDENCY_WRITES',
            'images': images, 'source_archive_sha256': proof['source_archive_sha256'],
            'preserved_runtime_hotfixes': [{'file': key, 'sha256': value, 'mode': 'PRESERVED_RUNTIME_VERIFIED'}
                                         for key, value in green.OVERLAYS.items()]})
        release.mkdir(mode=0o755)
        os.chmod(release, 0o755)
        with tarfile.open(archive) as bundle:
            assert all(green.safe_archive_member(release, member) for member in bundle.getmembers())
            bundle.extractall(release)
        for filename in green.OVERLAYS:
            assert (green.digest(release / filename) if (release / filename).is_file() else None) == proof['official_overlay_hashes'][filename]
            (release / filename).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(previous / filename, release / filename)
        verify_migration_delta(previous, release, proof['migration_sha256'])
        assert green.digest(release / 'composer.lock') == proof['composer_lock_sha256']
        if (previous / 'public/build').is_dir():
            shutil.copytree(previous / 'public/build', release / 'public/build', dirs_exist_ok=True)
        info = (previous / '.env').stat()
        env = set_environment((previous / '.env').read_text(), {
            'APP_BUILD_SHA': SOURCE, 'APP_BUILD_REF': 'dev@9d9e8738+uat05+preserved-runtime-hotfixes',
            'APP_BUILD_TIME': datetime.datetime.now(datetime.timezone.utc).isoformat(),
            'AUTH_SINGLE_ACTIVE_SESSION_ENABLED': 'true', 'AUTO_MIGRATE': 'false',
            'APP_URL': green.ORIGIN, 'L5_SWAGGER_CONST_HOST': green.ORIGIN,
            'COMPOSE_FILE': 'docker-compose.yml:docker-compose.vendor-release.yml'})
        (release / '.env').write_text(env)
        os.chown(release / '.env', info.st_uid, info.st_gid)
        os.chmod(release / '.env', 0o640)
        override = 'volumes:\n  wms_vendor:\n    name: ' + vendor + '\nservices:\n'
        for service in services:
            override += f'  {service}:\n    image: {images[service]}\n    environment:\n'
            override += '      AUTO_MIGRATE: "false"\n      WMS_CACHE_CONFIG: "true"\n      APP_CONFIG_CACHE: /var/cache/wms/config.php\n'
        (release / 'docker-compose.vendor-release.yml').write_text(override)
        green.compose(release, ['config', '--quiet'])
        resolved = json.loads(green.compose(release, ['config', '--format', 'json']))
        for service in services:
            configured = resolved['services'][service]
            assert configured['environment']['AUTO_MIGRATE'] == 'false'
            assert configured['environment']['DB_DATABASE'] == green.DATABASE
            assert configured['image'] == images[service]
        evidence['stage'] = 'backup'
        green.artisan('down', '--retry=30')
        paused = True
        stopped = True
        green.compose(previous, ['stop', '-t', '120', *services], timeout=240)
        before_data = unchanged_tables()
        before_refresh = refresh_fingerprint()
        before_media = green.media_manifest(previous)
        backup = ROOT / 'backups' / ('wms_hoanam_green_pre_uat05_' + stamp + '.dump')
        with open(backup, 'xb') as output:
            value = subprocess.run(['docker', 'exec', f'{green.PROJECT}-postgres-1', 'pg_dump',
                '-U', 'postgres', '-d', green.DATABASE, '-Fc'], stdout=output, stderr=subprocess.PIPE, timeout=180)
        os.chmod(backup, 0o600)
        assert value.returncode == 0 and backup.stat().st_size > 100000
        evidence['backup'] = {'path': str(backup), 'sha256': green.digest(backup), 'bytes': backup.stat().st_size}
        evidence['data_before'] = before_data
        shutil.copytree(previous / 'storage', release / 'storage', dirs_exist_ok=True, symlinks=True)
        assert before_media == green.media_manifest(release)
        assert shutil.disk_usage(ROOT).free > green.RESERVE
        green.emit('uat05_backup_complete', {'backup_bytes': backup.stat().st_size, 'tables': len(before_data)})
        evidence['stage'] = 'switch'
        green.switch(release, stamp)
        switched = True
        green.compose(release, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', 'backend', 'nginx'])
        for _ in range(45):
            row = json.loads(green.run(['docker', 'inspect', f'{green.PROJECT}-backend-1']))[0]
            if row['State'].get('Health', {}).get('Status') == 'healthy':
                break
            time.sleep(2)
        else:
            raise RuntimeError('Candidate backend readiness timeout')
        green.artisan('package:discover')
        evidence['stage'] = 'migration'
        evidence['migration_attempted'] = True
        green.artisan('migrate', '--force', '--no-interaction', '--path=' + MIGRATION)
        evidence['migration_run'] = True
        assert column_exists()
        assert green.pg('SELECT count(*) FROM refresh_tokens WHERE replaced_by_auth_session_id IS NOT NULL') == '0'
        assert set(migration_names()) - set(before_migrations) == {Path(MIGRATION).stem}
        assert len(migration_names()) == 218
        assert 'Pending' not in green.artisan('migrate:status', '--no-ansi')
        for command in ['config:clear', 'route:clear', 'event:clear', 'view:clear']:
            green.artisan(command)
        green.artisan('config:cache')
        green.artisan('route:cache')
        green.artisan('l5-swagger:generate', timeout=180)
        green.run(['docker', 'exec', f'{green.PROJECT}-nginx-1', 'nginx', '-t'])
        after_policy = green.policy()
        assert after_policy['sha'] == SOURCE
        assert {k: v for k, v in before_policy.items() if k != 'sha'} == {k: v for k, v in after_policy.items() if k != 'sha'}
        assert before_data == unchanged_tables(), 'Business/state table fingerprints changed'
        assert before_refresh == refresh_fingerprint(), 'Migration changed existing refresh-token fields'
        assert before_media == green.media_manifest(release), 'Media file manifest changed'
        evidence.update({'policy_after': after_policy, 'migration_count': 218,
            'data_unchanged_except_additive_schema_and_migration_row': True, 'media_unchanged': True,
            'refresh_values_preserved': True, 'session_revocation_during_deploy': False})
        assert configuration('backend')['session_replacement'] is True
        green.artisan('up')
        paused = False
        green.compose(release, ['up', '-d', '--no-build', '--no-deps', '--force-recreate',
            *[service for service in services if service != 'backend']])
        green.compose(release, ['restart', 'backend'])
        evidence['stage'] = 'runtime'
        evidence['runtime'] = {}
        for service in services:
            for _ in range(20):
                try:
                    config = configuration(service)
                    assert config['sha'] == SOURCE and config['session_replacement'] and config['cached']
                    assert config['database'] == green.DATABASE and config['environment'] == 'testing'
                    assert config['protected'] and not config['allow_seed']
                    break
                except Exception:
                    time.sleep(2)
            else:
                raise RuntimeError('Runtime config verification failed for ' + service)
            evidence['runtime'][service] = config
        for _ in range(20):
            try:
                request = urllib.request.Request(green.ORIGIN + '/api/v1/health',
                    headers={'Accept': 'application/json', 'Cache-Control': 'no-cache'})
                with urllib.request.urlopen(request, timeout=15) as response:
                    health = json.load(response)
                if health['success'] and health['data']['build']['sha'] == SOURCE:
                    break
            except Exception:
                pass
            time.sleep(2)
        else:
            raise RuntimeError('Public exact-SHA health failed')
        evidence.update({'stage': 'complete', 'health': health, 'free_bytes_after': shutil.disk_usage(ROOT).free})
        (release / 'release-operations.json').write_text(json.dumps(evidence, indent=2))
        green.emit('uat05_deployed', {'sha': SOURCE, 'release': str(release), 'migration_count': 218,
            'auth_policy_enabled': True, 'business_data_unchanged': True, 'media_unchanged': True,
            'request_id': health['data']['request_id'], 'free_bytes_after': evidence['free_bytes_after']})
    except Exception as error:
        evidence['failure'] = str(error)
        green.emit('uat05_deploy_failed', {'stage': evidence['stage'], 'error': str(error)})
        evidence['migration_column_exists_at_failure'] = column_exists()
        markers = evidence['migration_column_exists_at_failure'] and green.pg('SELECT count(*) FROM refresh_tokens WHERE replaced_by_auth_session_id IS NOT NULL') != '0'
        if switched and markers:
            green.artisan('down', '--retry=30')
            green.compose(release, ['stop', '-t', '120', *[service for service in services if service != 'backend']])
            evidence['manual_recovery_required'] = 'Retain source/schema/replacement markers; no automatic old-code or DB restore'
        elif stopped or paused:
            if switched:
                green.switch(previous, stamp)
            green.compose(previous, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', *services, 'nginx'])
            green.artisan('up')
            evidence['application_rollback'] = True
        raise
    finally:
        with open(report, 'w') as stream:
            json.dump(evidence, stream, indent=2)
        os.chmod(report, 0o600)
        green.emit('uat05_evidence_saved', {'path': str(report)})


if __name__ == '__main__':
    main()
