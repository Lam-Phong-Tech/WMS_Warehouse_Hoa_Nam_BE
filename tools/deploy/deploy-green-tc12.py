#!/usr/bin/env python3
"""Exact-SHA code promotion, preserving green data and runtime overlays."""

import argparse
import datetime
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import time
import urllib.request


spec = importlib.util.spec_from_file_location('green', Path(__file__).with_name('deploy-green-verified.py'))
green = importlib.util.module_from_spec(spec)
spec.loader.exec_module(green)
PREVIOUS = 'e2442df9cb03a50f57847aca0bcba78f0b2b2717'
TESTED = '6bfa66dc5d0edaddd91b67e59811e269983c5d71'
RELEASE_NAME = PREVIOUS + '-time-20261020T092246Z'
RUNTIME_PATHS = ('app', 'bootstrap', 'config', 'database', 'docker', 'Modules', 'routes',
                 'composer.json', 'composer.lock', 'modules_statuses.json', 'artisan')
DEPLOY_RESERVE = 960 * 1024 ** 2
# Inspected in run 39291454968; neither image is used by a container or retained rollback.
APPROVED_CLEANUP_TAGS = {
    'sha256:275447c94b11b151decd1f29877965301d5a77032037c46de93d990f739f00a9': ['postgres:16'],
    'sha256:7c3b093add7c43400ee83b815ab2cda98794a10045bcf76ce9bb2f89b97cbc5c': ['node:22-alpine'],
    'sha256:02c75d57183914675907ac7abbdd445445888b23e04f76a6d10f4c00236addda': ['hoa_nam_green-reverb:pre-d019d630'],
    'sha256:7313fceb0b58d7264b034011b19a94dbb73288df379ce632f955e66510cc0efc': ['hoa_nam_green-queue:pre-d019d630'],
    'sha256:769e9dcfe1f9dfe5ed8ce9260f93d1efead37156c70ae57e1cbe4cedd909467a': ['hoa_nam_green-scheduler:pre-d019d630'],
    'sha256:b284aacc4bc2f0045f6b5e08c630b47a40db4472d0e04c510a4d09136e4e90eb': ['hoa_nam_green-backend:pre-d019d630'],
    'sha256:17cfc4aff0987c3b8c207951d07ae6d633a4dc64a5ca2a6ce732cd72f10013ee': ['hoa_nam_backend-scheduler:latest'],
    'sha256:5c1f1652006f62bef48bc12b5b96a37d6e2ffb684971cbe59dae8ea682f58035': ['hoa_nam_backend-reverb:latest'],
    'sha256:70f2b64c96c423ce2f23f2c1e8141e8347a5b83a01066dffdd1561c2765d6f23': ['hoa_nam_backend-queue:latest'],
    'sha256:85bd670b8ece3d87dcce5d1d6f83849c64dcbc99fb7558063aa843d15f21a7b5': ['hoa_nam_backend-backend:latest'],
}


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def git(folder, *args):
    result = subprocess.run(['git', '-C', str(folder), *args], capture_output=True, check=False)
    require(result.returncode == 0, 'Git source verification failed')
    return result.stdout


def file_hashes(folder, sha):
    import hashlib
    with tarfile.open(fileobj=io.BytesIO(git(folder, 'archive', '--format=tar', sha, '--', *RUNTIME_PATHS))) as bundle:
        return {m.name: hashlib.sha256(bundle.extractfile(m).read()).hexdigest()
                for m in bundle.getmembers() if m.isfile()}


def package(folder, sha, output):
    require(re.fullmatch('[0-9a-f]{40}', sha), 'Invalid source SHA')
    require(git(folder, 'rev-parse', 'HEAD').decode().strip() == sha, 'Wrong checkout')
    git(folder, 'diff', '--exit-code', 'HEAD')
    git(folder, 'merge-base', '--is-ancestor', TESTED, sha)
    git(folder, 'merge-base', '--is-ancestor', PREVIOUS, sha)
    git(folder, 'diff', '--exit-code', TESTED, sha, '--', *RUNTIME_PATHS, 'tests', 'public', 'resources',
        'package.json', 'package-lock.json', 'vite.config.js', 'vite-module-loader.js')
    old, new = file_hashes(folder, PREVIOUS), file_hashes(folder, sha)
    allowed = {
        'Modules/AuditLog/app/Models/AuditLog.php',
        'Modules/AuditLog/app/Services/AuditLogger.php',
        'Modules/Warehouse/app/Http/Controllers/MiniAppNfcController.php',
        'Modules/Warehouse/app/Http/Controllers/P07NfcController.php',
        'Modules/Warehouse/app/Support/NfcTimestamp.php',
        'Modules/Warehouse/app/Support/P22NfcHistory.php',
        'app/Console/Commands/ReconcileAuditOutbox.php',
    }
    changed = {name for name in set(old) | set(new) if old.get(name) != new.get(name)}
    require(changed == allowed, 'Unexpected application source delta')
    require(not any(name in changed for name in green.OVERLAYS), 'Overlay conflict')
    output.mkdir(mode=0o700)
    archive = output / 'source.tar.gz'
    git(folder, 'archive', '--format=tar.gz', '--output=' + str(archive.resolve()), sha)
    proof = {'source_sha': sha, 'previous_sha': PREVIOUS, 'tested_sha': TESTED,
             'archive_sha256': green.digest(archive), 'previous_files': old, 'source_files': new,
             'ci_run': False, 'migration_run': False, 'seed_run': False}
    (output / 'proof.json').write_text(json.dumps(proof, indent=2))
    green.emit('nfc_time_packaged', {'sha': sha, 'tested_sha': TESTED, 'runtime_delta': sorted(changed),
                                 'archive_bytes': archive.stat().st_size})


def configuration(service='backend'):
    code = r'''require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo json_encode(['sha'=>config('health.build.sha'),'env'=>config('app.env'),
      'database'=>Illuminate\Support\Facades\DB::connection()->getDatabaseName(),
      'protected'=>(bool)config('deployment_safety.protect_database'),
      'allow_seed'=>(bool)config('deployment_safety.allow_protected_database_seed'),
      'p12'=>(bool)config('p12_mixed_documents.enabled'),'p14'=>(bool)config('p14.shift_enabled'),
      'p14_recovery'=>(bool)config('p14.recovery_enabled'),'p19'=>(bool)config('p19.commands_enabled'),
      'session_replacement'=>(bool)config('refresh_token.single_active_session.enabled'),
      'cached'=>app()->configurationIsCached(),'routes_cached'=>app()->routesAreCached()]);'''
    return json.loads(green.run(['docker', 'exec', '-u', 'www-data',
                                'hoa_nam_green-' + service + '-1', 'php', '-r', code]))


def environment(text, values):
    for key, value in values.items():
        require(len(re.findall(r'(?m)^' + re.escape(key) + '=', text)) <= 1, 'Duplicate environment key')
        text, count = re.subn(r'(?m)^' + re.escape(key) + '=.*$', key + '=' + value, text)
        if not count:
            text = text.rstrip('\n') + '\n' + key + '=' + value + '\n'
    return text


def inspect_images():
    ids = green.run(['docker', 'ps', '-aq']).splitlines()
    containers = json.loads(green.run(['docker', 'inspect', *ids])) if ids else []
    protected = {row['Image'] for row in containers}
    # Retained releases and verification records remain independent rollback roots.
    for root in (Path('/opt/hoa-nam-green/releases'), Path('/opt/hoa-nam-blue/releases')):
        if root.is_dir():
            for file in root.rglob('*.json'):
                if file.name not in ('release-operations.json',) and not file.name.endswith('proof.json'):
                    continue
                if file.stat().st_size < 4 * 1024 ** 2:
                    protected.update(re.findall(r'sha256:[0-9a-f]{64}', file.read_text(errors='replace')))
            for file in root.glob('*/docker-compose*.yml'):
                for image in re.findall(r'(?m)^\s*image:\s*["\']?([^\s"\']+)', file.read_text()):
                    value = subprocess.run(['docker', 'image', 'inspect', image, '--format', '{{.Id}}'],
                                           capture_output=True, text=True)
                    if value.returncode == 0:
                        protected.add(value.stdout.strip())
    protected.add(green.IMAGE_ID)
    image_ids = green.run(['docker', 'image', 'ls', '-aq', '--no-trunc']).splitlines()
    images = json.loads(green.run(['docker', 'image', 'inspect', *sorted(set(image_ids))])) if image_ids else []
    return protected, images


def retired_image_manifest():
    manifest = Path(__file__).with_name('nfc-unused-images-20261021.json')
    require(green.digest(manifest) == 'f29c7ffc3e3f6964bf2d471f302066a8ac149c65dd3f03c9ecc4441fd75de7e5',
            'Retired image inspection manifest changed')
    data = json.loads(manifest.read_text())
    require(data['inspection_run'] == '39557299070' and len(data['images']) == 7, 'Wrong image inspection')
    protected, _ = inspect_images()
    require(set(data['protected_current_rollback_ids']).issubset(protected), 'Rollback image reference changed')
    return data['images']


def cleanup_images(requested, approved_tags=None):
    protected, images = inspect_images()
    by_id = {row['Id']: row for row in images}
    cutoff = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=2)
    for image in requested:
        require(re.fullmatch('sha256:[0-9a-f]{64}', image), 'Full image ID required')
        require(image in by_id and image not in protected, 'Image referenced by container or rollback')
        row = by_id[image]
        tags = row.get('RepoTags') or []
        allowed = APPROVED_CLEANUP_TAGS if approved_tags is None else approved_tags
        require(not tags or sorted(tags) == sorted(allowed.get(image, [])), 'Unapproved image tags')
        created = datetime.datetime.fromisoformat(row['Created'][:26].replace('Z', '+00:00'))
        if created.tzinfo is None:
            created = created.replace(tzinfo=datetime.timezone.utc)
        require(created < cutoff, 'Image too recent to remove')
        require(not green.run(['docker', 'ps', '-aq', '--filter', 'ancestor=' + image]).strip(),
                'Image acquired a container reference')
        for tag in tags:
            require(green.run(['docker', 'image', 'inspect', tag, '--format', '{{.Id}}']).strip() == image,
                    'Approved image tag changed')
        green.run(['docker', 'image', 'rm', *(tags or [image])])  # No force, prune, volumes or container removal.
        green.emit('unused_image_removed', {'id': image, 'tags': row.get('RepoTags'), 'bytes': row['Size']})


def wait_ready():
    for _ in range(45):
        try:
            row = json.loads(green.run(['docker', 'inspect', 'hoa_nam_green-backend-1']))[0]
            if row['State'].get('Health', {}).get('Status') == 'healthy':
                return
        except RuntimeError:
            pass
        time.sleep(2)
    raise RuntimeError('Backend readiness timeout')


def deploy(folder, sha, inspect_only, remove_images, cleanup_retired_ci=False):
    os.umask(0o077)
    require(__debug__, 'Python optimized mode prohibited')
    require(green.ROOT.resolve() == green.ROOT, 'Unexpected deployment root')
    previous = (green.ROOT / 'current').resolve()
    require(previous == green.ROOT / 'releases' / RELEASE_NAME, 'Previous release changed; review required')
    proof = json.loads((folder / 'proof.json').read_text())
    require(proof['source_sha'] == sha and proof['previous_sha'] == PREVIOUS
            and proof['tested_sha'] == TESTED, 'Proof source mismatch')
    archive = folder / 'source.tar.gz'
    require(green.digest(archive) == proof['archive_sha256'], 'Source archive mismatch')
    before = configuration()
    require(before['sha'] == PREVIOUS and before['database'] == green.DATABASE
            and before['env'] == 'testing' and before['protected'] and not before['allow_seed']
            and not before['p19'] and not before['p14_recovery'] and before['session_replacement'],
            'Target identity or release policy mismatch')
    for name, expected in proof['previous_files'].items():
        require(green.digest(previous / name) == green.OVERLAYS.get(name, expected), 'Runtime drift: ' + name)
    for name, expected in green.OVERLAYS.items():
        require(green.digest(previous / name) == expected, 'Overlay drift: ' + name)
    rows, services, vendors, images = {}, [], set(), {}
    for service in ('backend', 'queue', 'scheduler'):
        row = json.loads(green.run(['docker', 'inspect', 'hoa_nam_green-' + service + '-1']))[0]
        require(row['State']['Running'] and row['Config']['Labels']['com.docker.compose.project'] == green.PROJECT,
                'Application service identity mismatch')
        require('AUTO_MIGRATE=false' in row['Config']['Env'], 'Startup migration not disabled')
        require(any(m['Destination'] == '/var/www/html' and m['Source'] == str(previous) for m in row['Mounts']),
                'Unexpected serving source mount')
        vendors.add(next(m['Name'] for m in row['Mounts'] if m['Destination'] == '/var/www/html/vendor'))
        services.append(service)
        images[service] = row['Image']
        rows[service] = row
    require(len(vendors) == 1, 'Application dependency volume mismatch')
    vendor = vendors.pop()
    require(green.run(['docker', 'exec', 'hoa_nam_green-backend-1', 'cat', 'vendor/.composer-lock.sha256']).strip()
            == proof['source_files']['composer.lock'], 'Dependency lock stamp mismatch')
    with tarfile.open(archive) as bundle:
        source_bytes = sum(m.size for m in bundle.getmembers() if m.isfile())
    storage_bytes = int(green.run(['du', '-sb', str(previous / 'storage')]).split()[0])
    required = DEPLOY_RESERVE + source_bytes + storage_bytes + 32 * 1024 ** 2
    protected, all_images = inspect_images()
    capacity = {'free': shutil.disk_usage(green.ROOT).free, 'required': required,
                'source_bytes': source_bytes, 'storage_bytes': storage_bytes, 'reserve': DEPLOY_RESERVE,
                'rollback_images': images}
    green.emit('nfc_time_preflight', capacity)
    if inspect_only:
        require(not remove_images and not cleanup_retired_ci, 'Inspection cannot remove images')
        runner = Path('/opt/actions-runner/hoa-nam-vps42/_work/WMS_Warehouse_Hoa_Nam_BE/WMS_Warehouse_Hoa_Nam_BE')
        generated = [runner / 'devtest-ops-source/deploy-package', runner / 'devtest-auth-ops/auth-package',
                     runner / 'devtest-auth-application', runner / 'devtest-smoke-source']
        green.emit('generated_package_sizes', [{'path': str(p), 'bytes': int(green.run(['du', '-sb', str(p)]).split()[0])}
                                              for p in generated if p.is_dir()])
        usage = json.loads(green.run(['docker', 'system', 'df', '-v', '--format', '{{json .}}']))
        green.emit('image_disk_usage', usage.get('Images', usage))
        green.emit('image_inventory', [{'id': row['Id'], 'tags': row.get('RepoTags'), 'created': row['Created'],
                   'bytes': row['Size'], 'protected': row['Id'] in protected} for row in all_images])
        return
    if remove_images:
        cleanup_images(remove_images)
        green.emit('capacity_after_image_cleanup', {'free': shutil.disk_usage(green.ROOT).free, 'required': required})
    if cleanup_retired_ci:
        approved_tags = retired_image_manifest()
        cleanup_images(list(approved_tags), approved_tags)
        green.emit('capacity_after_retired_ci_cleanup', {'free': shutil.disk_usage(green.ROOT).free, 'required': required})
    require(shutil.disk_usage(green.ROOT).free > required, 'Insufficient disk reserve; no automatic cleanup')
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    release = green.ROOT / 'releases' / (sha + '-nfc-time-' + stamp)
    report = green.ROOT / 'backups' / ('deploy-nfc-time-' + stamp + '.json')
    evidence = {'source_sha': sha, 'tested_sha': TESTED, 'previous': str(previous), 'release': str(release),
                'images': images, 'vendor': vendor, 'stage': 'prepare', 'ci_run': False, 'seed_run': False,
                'migration_run': False, 'flags_changed': False, 'production_activation': False,
                'capacity': capacity, 'overlay_hashes': green.OVERLAYS}
    paused = stopped = switched = reopened = False
    try:
        release.mkdir(mode=0o755)
        os.chmod(release, 0o755)
        with tarfile.open(archive) as bundle:
            require(all(green.safe_archive_member(release, m) for m in bundle.getmembers()), 'Unsafe archive member')
            bundle.extractall(release)
        for name, expected in proof['source_files'].items():
            require(green.digest(release / name) == expected, 'Archive source mismatch: ' + name)
        for name in green.OVERLAYS:
            (release / name).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(previous / name, release / name)
        require(green.migrations(previous) == green.migrations(release), 'Migration file delta')
        if (previous / 'public/build').is_dir():
            shutil.copytree(previous / 'public/build', release / 'public/build', dirs_exist_ok=True)
        shutil.copy2(previous / '.env', release / '.env')
        info = (previous / '.env').stat()
        (release / '.env').write_text(environment((previous / '.env').read_text(), {
            'APP_BUILD_SHA': sha, 'APP_BUILD_REF': 'dev@' + sha[:8] + '+nfc-time-fix+preserved-runtime-hotfixes',
            'APP_BUILD_TIME': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'AUTO_MIGRATE': 'false'}))
        os.chown(release / '.env', info.st_uid, info.st_gid)
        os.chmod(release / '.env', 0o640)
        shutil.copy2(previous / 'docker-compose.vendor-release.yml', release / 'docker-compose.vendor-release.yml')
        model = json.loads(green.compose(release, ['config', '--format', 'json']))
        for service in services:
            value = model['services'][service]
            require(value['image'] == images[service] and value['environment']['AUTO_MIGRATE'] == 'false'
                    and value['environment']['DB_DATABASE'] == green.DATABASE, 'Resolved compose target mismatch')
        evidence['stage'] = 'pause-and-backup'
        green.artisan('down', '--retry=30')
        paused = True
        stopped = True
        green.compose(previous, ['stop', '-t', '120', *services], timeout=240)
        before_data = green.fingerprints()
        before_media = green.media_manifest(previous)
        backup = green.ROOT / 'backups' / ('wms_hoanam_green_pre_nfc_time_' + stamp + '.dump')
        with open(backup, 'xb') as stream:
            result = subprocess.run(['docker', 'exec', 'hoa_nam_green-postgres-1', 'pg_dump',
                                     '-U', 'postgres', '-d', green.DATABASE, '-Fc'],
                                    stdout=stream, stderr=subprocess.PIPE, timeout=180)
        require(result.returncode == 0 and backup.stat().st_size > 100000, 'Backup failed')
        evidence['backup'] = {'path': str(backup), 'bytes': backup.stat().st_size, 'sha256': green.digest(backup)}
        shutil.copytree(previous / 'storage', release / 'storage', dirs_exist_ok=True, symlinks=True)
        require(before_media == green.media_manifest(release), 'Copied media mismatch')
        require(shutil.disk_usage(green.ROOT).free > DEPLOY_RESERVE, 'Disk reserve lost before switch')
        evidence['stage'] = 'switch'
        green.switch(release, stamp)
        switched = True
        green.compose(release, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', 'backend', 'nginx'])
        wait_ready()
        green.artisan('package:discover')
        require('Pending' not in green.artisan('migrate:status', '--no-ansi'), 'Unexpected pending migration')
        require(green.pg('SELECT count(*) FROM migrations') == '218', 'Migration row count mismatch')
        for command in ('config:clear', 'route:clear', 'event:clear', 'view:clear', 'config:cache', 'route:cache'):
            green.artisan(command)
        green.artisan('l5-swagger:generate', timeout=180)
        green.run(['docker', 'exec', 'hoa_nam_green-nginx-1', 'nginx', '-t'])
        after = configuration()
        require(after['sha'] == sha and {k: v for k, v in before.items() if k != 'sha'}
                == {k: v for k, v in after.items() if k != 'sha'}, 'Runtime config policy changed')
        require(before_data == green.fingerprints(), 'DB fingerprint changed; no automatic restore permitted')
        require(before_media == green.media_manifest(release), 'Media changed during deployment')
        probe = green.run(['docker', 'exec', '-i', '-u', 'www-data', '-e', 'EXPECTED_BUILD_SHA=' + sha,
                           'hoa_nam_green-backend-1', 'php'],
                          input_data=(release / 'tools/deploy/inspect-nfc-timestamps.php').read_text())
        observation = json.loads(probe)['data']
        require(observation['runtime_sha'] == sha and observation['nfc_timestamp_probe']['contract'] == 'nfc-timestamps-v1',
                'Deployed NFC timestamp probe failed')
        require(observation['receipt_bodies_fingerprint'] == 'a100c78e2fa6f339e5af8c22029e6934',
                'Historical TEST receipt changed; manual investigation required')
        evidence['nfc_source_probe'] = observation
        green.emit('nfc_timestamp_verified_no_write', observation)
        evidence.update({'data_unchanged': True, 'media_unchanged': True, 'verified_tables': len(before_data),
                         'verified_media_files': len(before_media), 'migration_count': 218})
        green.artisan('up')
        paused = False
        reopened = True
        green.compose(release, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', 'queue', 'scheduler'])
        green.compose(release, ['restart', 'backend'])
        wait_ready()
        for service in services:
            require(configuration(service)['sha'] == sha, 'Worker build mismatch')
            require(configuration(service)['cached'], 'Worker configuration not cached')
        evidence['stage'] = 'public-health'
        request = urllib.request.Request(green.ORIGIN + '/api/v1/health',
                                         headers={'Accept': 'application/json', 'Cache-Control': 'no-cache'})
        with urllib.request.urlopen(request, timeout=25) as response:
            health = json.load(response)
            headers = dict(response.headers)
        require(health['data']['build']['sha'] == sha, 'Public SHA mismatch')
        evidence.update({'stage': 'complete', 'health': health, 'health_headers': headers,
                         'completed_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                         'free_bytes_after': shutil.disk_usage(green.ROOT).free})
        (release / 'release-operations.json').write_text(json.dumps(evidence, indent=2))
        green.emit('nfc_time_deployed', {k: evidence[k] for k in ('source_sha', 'stage', 'migration_count',
                   'data_unchanged', 'media_unchanged', 'verified_tables', 'verified_media_files', 'free_bytes_after')})
    except Exception as error:
        evidence['failure_type'] = type(error).__name__
        green.emit('nfc_time_failed', {'stage': evidence['stage'], 'error_type': type(error).__name__,
                                 'safe_message': str(error) if isinstance(error, RuntimeError) else 'See private report'})
        if not reopened:
            if switched:
                green.switch(previous, stamp)
            if stopped or paused:
                green.compose(previous, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', *services, 'nginx'])
                green.artisan('up')
                evidence['application_rollback'] = True
        else:
            evidence['manual_recovery_required'] = True
        raise
    finally:
        report.write_text(json.dumps(evidence, indent=2))
        os.chmod(report, 0o600)
        green.emit('nfc_time_private_report', {'path': str(report)})


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--package', action='store_true')
    parser.add_argument('--checkout')
    parser.add_argument('--output')
    parser.add_argument('--sha', required=True)
    parser.add_argument('--source')
    parser.add_argument('--inspect', action='store_true')
    parser.add_argument('--remove-image', action='append', default=[])
    parser.add_argument('--cleanup-retired-ci-images', action='store_true')
    args = parser.parse_args()
    if args.package:
        package(Path(args.checkout), args.sha, Path(args.output))
    else:
        deploy(Path(args.source), args.sha, args.inspect, args.remove_image, args.cleanup_retired_ci_images)
