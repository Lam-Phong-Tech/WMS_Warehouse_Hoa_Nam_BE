"""Exact-source DEV-only rollout. Never migrates, seeds or modifies production."""
import argparse
import copy
import datetime
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tarfile
import time
import urllib.request

ROOT = Path('/opt/wms-hoanam')
BASE = '570289567c8ffc06324892f40f123c4b40610294'
COMPOSE_HASH = '851b61d6009c31b702ef5d6a85f6e9d2def3b7ef373b5f692cb0b0e69069b4c1'
SERVICES = ('backend', 'queue', 'scheduler', 'reverb', 'nginx')
APP_FILES = {
    'Modules/Warehouse/app/Support/P18ComponentLocationRead.php',
    'Modules/Warehouse/app/Http/Controllers/ContainerController.php',
    'Modules/Warehouse/app/Http/Controllers/ItemInstanceController.php',
    'app/Http/Middleware/ExplicitReadFields.php',
}


def run(args):
    result = subprocess.run(args, check=False, capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError('Command failed: ' + ' '.join(args[:7]) + ' (exit ' + str(result.returncode) + ')')
    return result.stdout.strip()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def compose_args():
    return ['docker', 'compose', '-p', 'hoa_nam_dev', '-f', str(ROOT / 'compose.json')]


def production_ids():
    return run(['docker', 'ps', '-a', '--filter', 'name=hoa_nam_prod_', '--format', '{{.ID}} {{.Names}}']).splitlines()


def schema_state():
    sql = "BEGIN READ ONLY; SELECT count(*) FROM migrations; SELECT md5(string_agg(migration || ':' || batch::text, ',' ORDER BY migration)) FROM migrations; ROLLBACK;"
    return run(['docker', 'exec', 'hoa_nam_dev-postgres-1', 'psql', '-X', '-U', 'postgres', '-d', 'wms_hoanam_green', '-Atc', sql])


def allowed(name):
    return name in APP_FILES or name.startswith(('docs/contracts/p18-component-location-', 'docs/fe-handoff/P18_COMPONENT_LOCATION_', 'docs/superpowers/plans/2026-11-03-p18-', 'tests/Feature/P18ComponentLocation', 'tools/deploy/deploy-devtest-p18-location', 'tools/deploy/test_deploy_devtest_p18_location'))


def archive_files(path):
    files = {}
    with tarfile.open(path, 'r:*') as archive:
        for entry in archive.getmembers():
            p = PurePosixPath(entry.name)
            if p.is_absolute() or '..' in p.parts or '\\' in entry.name or entry.issym() or entry.islnk():
                raise RuntimeError('Unsafe archive path')
            if entry.isdir():
                continue
            if not entry.isfile() or not allowed(entry.name) or entry.name in files or entry.size > 2_000_000:
                raise RuntimeError('Archive file outside P18 scope: ' + entry.name)
            files[entry.name] = archive.extractfile(entry).read()
    if not APP_FILES.issubset(files):
        raise RuntimeError('Incomplete P18 application package')
    return files


def patched_compose(config, source, candidate):
    result = copy.deepcopy(config)
    old_roots = set()
    for name in SERVICES:
        service = result['services'][name]
        matches = [m for m in service['volumes'] if isinstance(m, dict) and m.get('target') == '/var/www/html']
        if len(matches) != 1 or matches[0].get('type') != 'bind' or not matches[0].get('read_only'):
            raise RuntimeError('Unexpected source mount')
        old_roots.add(matches[0]['source'])
        matches[0]['source'] = str(source)
        if name != 'nginx':
            env = service['environment']
            if env.get('AUTO_MIGRATE') != 'false' or env.get('APP_BUILD_SHA') != BASE:
                raise RuntimeError('Unsafe migration flag or build drift')
            env['APP_BUILD_SHA'] = candidate
            env['APP_BUILD_REF'] = 'dev-p18-component-location@' + candidate
            env['APP_BUILD_TIME'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
    if len(old_roots) != 1:
        raise RuntimeError('Services do not share one release')
    return result, Path(old_roots.pop())


def healthy(candidate):
    request = urllib.request.Request('http://127.0.0.1:8080/api/v1/health', headers={'Host': 'khohoanamdev.lptech.info.vn', 'Accept': 'application/json'})
    for _ in range(35):
        try:
            with urllib.request.urlopen(request, timeout=5) as response:
                body = json.load(response)
            if body.get('data', {}).get('build', {}).get('sha') == candidate:
                return body
        except (OSError, ValueError):
            pass
        time.sleep(2)
    raise RuntimeError('DEV health did not confirm exact candidate')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--sha', required=True)
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--manifest', type=Path, required=True)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if not re.fullmatch('[a-f0-9]{40}', args.sha) or args.sha == BASE:
        raise RuntimeError('Exact new candidate SHA required')
    if os.getuid() != 0:
        raise RuntimeError('Approved DEV operator required')
    path = ROOT / 'compose.json'
    original = path.read_bytes()
    if hashlib.sha256(original).hexdigest() != COMPOSE_HASH:
        raise RuntimeError('Compose drift; re-inspect before rollout')
    config = json.loads(original)
    target = ROOT / 'releases' / (args.sha + '-p18-component-location-20261103')
    modified, current = patched_compose(config, target, args.sha)
    if current.parent != ROOT / 'releases' or not current.is_dir() or target.exists():
        raise RuntimeError('Unexpected current/new release path')
    files = archive_files(args.archive)
    manifest = json.loads(args.manifest.read_text())
    if manifest['base'] != BASE or manifest['candidate'] != args.sha or digest(args.archive) != manifest['archive_sha256']:
        raise RuntimeError('Package manifest mismatch')
    for name, checksum in manifest['base_files'].items():
        if digest(current / name) != checksum:
            raise RuntimeError('Runtime source/asset drift: ' + name)
    for name, blob in files.items():
        if hashlib.sha256(blob).hexdigest() != manifest['files'].get(name):
            raise RuntimeError('Candidate file mismatch: ' + name)
    prod_before = sorted(production_ids())
    schema_before = schema_state()
    swagger_path = ROOT / 'shared' / 'storage' / 'api-docs' / 'api-docs.json'
    swagger_before = swagger_path.read_bytes() if swagger_path.exists() else None
    report = {'base': BASE, 'candidate': args.sha, 'environment': 'DEV_TEST', 'schema_before': schema_before,
              'files': sorted(files), 'production_container_ids_before': prod_before, 'apply': args.apply, 'migration_executed': False, 'seed_executed': False}
    if not args.apply:
        print(json.dumps(report, indent=2))
        return
    os.umask(0o077)
    backup = ROOT / ('compose-before-p18-' + args.sha + '.json')
    backup.write_bytes(original)
    shutil.copytree(current, target, symlinks=True)
    for name, blob in files.items():
        out = target / name
        if out.is_symlink() or any(p.is_symlink() for p in out.parents if p != target.parent):
            raise RuntimeError('Candidate destination has a symlink')
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_bytes(blob)
        out.chmod(0o644)
    run(['chmod', '755', str(target)])
    # Source-only overlay: vendor, environment, storage, nginx mounts, assets
    # and dependency locks stay identical to the retained release.
    for name, checksum in manifest['base_files'].items():
        if name not in files and digest(target / name) != checksum:
            raise RuntimeError('Retained source/asset changed: ' + name)
    temp = ROOT / 'compose.p18.new.json'
    temp.write_text(json.dumps(modified, indent=2) + '\n')
    os.replace(temp, path)
    try:
        run(compose_args() + ['up', '-d', '--no-deps', '--force-recreate', *SERVICES])
        run(['docker', 'exec', 'hoa_nam_dev-backend-1', 'php', 'artisan', 'route:cache'])
        run(['docker', 'exec', 'hoa_nam_dev-backend-1', 'php', 'artisan', 'l5-swagger:generate'])
        report['health'] = healthy(args.sha)
        report['schema_after'] = schema_state()
        report['production_container_ids_after'] = sorted(production_ids())
        if report['schema_after'] != schema_before or report['production_container_ids_after'] != prod_before:
            raise RuntimeError('Unexpected schema/production container drift')
        report['source_root'] = str(target)
        report['swagger_regenerated'] = True
        report['deployed_at'] = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=7))).isoformat()
        report['status'] = 'DEPLOYED_PENDING_AUTHENTICATED_APP_ACCEPTANCE'
    except Exception:
        path.write_bytes(original)
        if swagger_before is not None:
            swagger_path.write_bytes(swagger_before)
        run(compose_args() + ['up', '-d', '--no-deps', '--force-recreate', *SERVICES])
        raise
    (ROOT / ('p18-deploy-' + args.sha + '.json')).write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
