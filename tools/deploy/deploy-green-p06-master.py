#!/usr/bin/env python3
"""Pinned PHP-only P06 release; no schema, seed, stock or dependency writes."""

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


spec = importlib.util.spec_from_file_location('app_release', Path(__file__).with_name('deploy-green-app-20261024.py'))
app_release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(app_release)
green, tc12 = app_release.green, app_release.tc12
require = app_release.require
SOURCE = '5e92f64de61eb7a36aac254294541309957f343d'
PREVIOUS = 'cdda3076a950be1f3860083768d9c9de6c7783ad'
PREVIOUS_RELEASE = PREVIOUS + '-future-evidence-20261026T051148Z'
RISK_DECISION = 'docs/evidence/P06_DEV_ONLY_RELEASE_DECISION_20261027.json'
TLS = Path('/etc/nginx/sites-available/khohoanamdev.lptech.info.vn')
TLS_SHA256 = '373414205df3f1604349736a51050d1f6f3c0922a0b6790837ec149fd0d168f9'
NGINX = {
    'docker/nginx/default.conf.template': '12dc7f6a5c7314af3fbfa5b4edd5c82c8bbc3517aeaa120610e0606b0dde6cc3',
    'docker/nginx/perf/default.conf.template': '68713e735b40ddcac599d228b2644be5dc9a5eeb8653ebd34de9ab466d165c51',
}
OVERLAYS = {**green.OVERLAYS, app_release.STAGING:
            '06211cb3e0c532951f005f49aa882b6fcf3d08ac5fedcf3a20296a7b80d93767'}
APPLICATION_DELTA = {
    'Modules/Warehouse/app/Http/Controllers/P06CatalogController.php',
    'Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php',
    'app/Swagger/P06CatalogOpenApi.php',
}
SERVICES = ('backend', 'queue', 'scheduler')
RESERVE = 1024 ** 3


def validate_delta(before, after):
    changed = {name for name in set(before) | set(after) if before.get(name) != after.get(name)}
    require(changed == APPLICATION_DELTA, 'Unreviewed runtime delta')
    require(not set(OVERLAYS).intersection(changed), 'Runtime overlay conflict')
    for name, expected in NGINX.items():
        require(after[name] == expected, 'ETag template changed')


def package(folder, ops_sha, output):
    require(re.fullmatch('[0-9a-f]{40}', ops_sha), 'Invalid operations SHA')
    require(tc12.git(folder, 'rev-parse', 'HEAD').decode().strip() == ops_sha, 'Checkout changed')
    tc12.git(folder, 'diff', '--exit-code', 'HEAD')
    tc12.git(folder, 'merge-base', '--is-ancestor', SOURCE, ops_sha)
    tc12.git(folder, 'merge-base', '--is-ancestor', PREVIOUS, SOURCE)
    runtime = (*app_release.RUNTIME, 'docker-compose.yml', 'docker-compose.perf.yml')
    tc12.git(folder, 'diff', '--exit-code', SOURCE, ops_sha, '--', *runtime)
    # Reuse built assets only when their entire input and dependency tree is unchanged.
    tc12.git(folder, 'diff', '--exit-code', PREVIOUS, SOURCE, '--', 'composer.json', 'composer.lock',
             'package.json', 'package-lock.json', 'public', 'resources', 'vite.config.js', 'vite-module-loader.js')
    before, after = app_release.files(folder, PREVIOUS), app_release.files(folder, SOURCE)
    validate_delta(before, after)
    output.mkdir(mode=0o700)
    archive = output / 'source.tar.gz'
    tc12.git(folder, 'archive', '--format=tar.gz', '--output=' + str(archive.resolve()), SOURCE)
    proof = {'source_sha': SOURCE, 'ops_sha': ops_sha, 'previous_sha': PREVIOUS,
             'archive_sha256': green.digest(archive), 'previous_files': before, 'source_files': after,
             'migration_run': False, 'seed_run': False, 'ci_run': False,
             'dev_audit_decision': json.loads((folder / RISK_DECISION).read_text()) if (folder / RISK_DECISION).is_file()
                 else {'status': 'PENDING_EXPLICIT_USER_DECISION'}}
    (output / 'proof.json').write_text(json.dumps(proof, indent=2))
    green.emit('p06_release_packaged', {k: proof[k] for k in ('source_sha', 'ops_sha', 'archive_sha256')})


def schema_guard(state):
    require(len(state['ran']) == 220 and state['pending'] == [Path(app_release.P11).stem]
            and not state['orphaned'], 'Schema drift; no migration is authorized')


def transport_guard(release):
    require(TLS.is_file() and green.digest(TLS) == TLS_SHA256, 'DEV TLS ETag fix drift')
    for name, expected in NGINX.items():
        require(green.digest(release / name) == expected, 'Inner ETag fix drift')
    green.run(['nginx', '-t'])
    green.run(['docker', 'exec', 'hoa_nam_green-nginx-1', 'nginx', '-t'])


def assert_unchanged(before, after, label):
    require(before == after, label + ' changed; automatic data restore is prohibited')


def audit_gate(decision):
    require(decision.get('status') == 'APPROVED_BY_USER_FOR_DEV_ONLY'
            and decision.get('approved_by') == 'USER'
            and decision.get('source_sha') == SOURCE and decision.get('environment') == 'DEV_TEST_ONLY'
            and decision.get('npm_audit_status') == 'OPEN_NOT_PASS'
            and decision.get('dependency_changes') is False and decision.get('production_activation') is False
            and decision.get('migration_seed_box_assignment') is False,
            'Npm audit remains OPEN; exact DEV-only user risk decision required before activation')


def master_diagnostics():
    # Read-only source inspection, not an authenticated owner HTTP smoke or a
    # claim that stock is free to pack. Only explicit master/BOX identities.
    skus = json.loads(green.pg("SELECT coalesce(json_agg(row_to_json(q)),'[]'::json) FROM ("
        "SELECT s.sku_id,s.sku_code,s.sku_name,s.sku_type,s.status,s.unit,s.is_serial_tracked,s.version,"
        "s.category_id,c.category_name,c.status AS category_status,s.brand_id,b.brand_name,b.status AS brand_status "
        "FROM sku s LEFT JOIN product_categories c ON c.id=s.category_id LEFT JOIN brands b ON b.id=s.brand_id "
        "WHERE s.sku_code IN ('DCJZ2090','DCJZ2090i','DCJZ2090I','DCA-BITSET','HN-COMP-SEED-002') ORDER BY s.sku_code,s.sku_id) q"))
    box = json.loads(green.pg("SELECT coalesce(json_agg(row_to_json(q)),'[]'::json) FROM ("
        "SELECT c.container_id,c.container_code,c.container_type,c.primary_sku_id,c.status,c.version,"
        "(SELECT coalesce(json_agg(json_build_object('content_id',i.container_item_id,'sku_id',i.sku_id,'item_id',i.item_id,"
        "'quantity',i.quantity,'status',i.status,'is_active',i.is_active,'version',i.version)),'[]'::json) "
        "FROM container_items i WHERE i.container_id=c.container_id AND i.is_active=true) AS active_contents "
        "FROM containers c WHERE c.container_code='BOX-SEED-0005') q"))
    green.emit('p06_read_only_master_diagnostics', {'skus': skus, 'box': box, 'mutations': 0,
        'authenticated_owner_http': False, 'packing_quantity_confirmed': False})


def deploy(folder, ops_sha, inspect_only):
    os.umask(0o077)
    require(__debug__ and green.ROOT.resolve() == green.ROOT, 'Invalid runtime/root')
    previous = (green.ROOT / 'current').resolve()
    require(previous == green.ROOT / 'releases' / PREVIOUS_RELEASE, 'Serving release changed')
    global OVERLAYS
    previous_report = json.loads((previous / 'release-operations.json').read_text())
    require(previous_report['source_sha'] == PREVIOUS and previous_report['stage'] == 'complete'
            and previous_report['ops_sha'] == 'b26d8bb87773ba530168b5e9cc496fbf65323f10', 'Previous release provenance changed')
    observed_overlays = previous_report['source_sha256_after']
    require(set(observed_overlays) == set(OVERLAYS)
            and all(re.fullmatch('[0-9a-f]{64}', v) for v in observed_overlays.values())
            and all(observed_overlays[k] == v for k, v in OVERLAYS.items() if k != app_release.STAGING), 'Previous overlay manifest changed')
    OVERLAYS = observed_overlays
    proof = json.loads((folder / 'proof.json').read_text())
    require(proof['source_sha'] == SOURCE and proof['ops_sha'] == ops_sha
            and proof['previous_sha'] == PREVIOUS, 'Package identity mismatch')
    require(green.digest(folder / 'source.tar.gz') == proof['archive_sha256'], 'Archive mismatch')
    validate_delta(proof['previous_files'], proof['source_files'])
    before_policy = app_release.configuration()
    require(before_policy['sha'] == PREVIOUS and before_policy['database'] == green.DATABASE
            and before_policy['environment'] == 'testing' and before_policy['protected']
            and not before_policy['allow_seed'] and not before_policy['p11']
            and before_policy['p12'] and before_policy['p14'] and not before_policy['p14_recovery']
            and not before_policy['p19'] and before_policy['session_replacement']
            and before_policy['cached'] and before_policy['routes_cached'], 'Target policy mismatch')
    before_schema = app_release.migration_state()
    schema_guard(before_schema)
    transport_guard(previous)
    for name, expected in proof['previous_files'].items():
        require(green.digest(previous / name) == OVERLAYS.get(name, NGINX.get(name, expected)),
                'Runtime drift: ' + name)
    for name, expected in OVERLAYS.items():
        require(green.digest(previous / name) == expected, 'Overlay drift: ' + name)
    require(green.digest(previous / app_release.HISTORY) == app_release.HISTORY_SHA256,
            'Retained migration artifact drift')
    images, vendors = {}, set()
    for service in SERVICES:
        row = json.loads(green.run(['docker', 'inspect', 'hoa_nam_green-' + service + '-1']))[0]
        require(row['State']['Running'] and row['Image'] == green.IMAGE_ID
                and row['Config']['Labels']['com.docker.compose.project'] == green.PROJECT,
                'Service identity mismatch')
        require('AUTO_MIGRATE=false' in row['Config']['Env'], 'Startup migration enabled')
        require(any(m['Destination'] == '/var/www/html' and m['Source'] == str(previous) for m in row['Mounts']),
                'Source mount mismatch')
        vendors.add(next(m['Name'] for m in row['Mounts'] if m['Destination'] == '/var/www/html/vendor'))
        images[service] = row['Image']
    nginx_image = green.run(['docker', 'inspect', 'hoa_nam_green-nginx-1', '--format', '{{.Image}}']).strip()
    require(nginx_image == 'sha256:f0ba77f796e57c6fa89ae7f4fdad1665d6fcbd8e3f211535120542b337f9959e',
            'Nginx image changed')
    require(len(vendors) == 1, 'Dependency mounts differ')
    vendor = vendors.pop()
    require(green.run(['docker', 'exec', 'hoa_nam_green-backend-1', 'cat', 'vendor/.composer-lock.sha256']).strip()
            == proof['source_files']['composer.lock'], 'Dependency lock mismatch')
    with tarfile.open(folder / 'source.tar.gz') as bundle:
        source_bytes = sum(m.size for m in bundle.getmembers() if m.isfile())
    storage_bytes = int(green.run(['du', '-sb', str(previous / 'storage')]).split()[0])
    db_bytes = int(green.pg('SELECT pg_database_size(current_database())'))
    capacity = {'free_bytes': shutil.disk_usage(green.ROOT).free,
                'required_bytes': RESERVE + source_bytes + storage_bytes + db_bytes + 128 * 1024 ** 2}
    require(capacity['free_bytes'] > capacity['required_bytes'], 'Insufficient reserve; no cleanup authorized')
    green.emit('p06_release_preflight', {'source_sha': SOURCE, 'ops_sha': ops_sha, 'policy': before_policy,
               'migration_count': 220, 'pending': before_schema['pending'], 'capacity': capacity,
               'overlays_verified': len(OVERLAYS), 'inner_etag_preserved': True, 'tls_sha256': TLS_SHA256,
               'migration_run': False, 'seed_run': False, 'ci_run': False})
    master_diagnostics()
    if inspect_only:
        return
    audit_gate(proof['dev_audit_decision'])
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    release = green.ROOT / 'releases' / (SOURCE + '-p06-master-' + stamp)
    report = green.ROOT / 'backups' / ('deploy-p06-master-' + stamp + '.json')
    evidence = {'source_sha': SOURCE, 'ops_sha': ops_sha, 'previous_release': str(previous),
                'release': str(release), 'images': images, 'vendor': vendor, 'capacity': capacity,
                'policy_before': before_policy, 'migration_run': False, 'seed_run': False, 'ci_run': False,
                'data_restore': False, 'production_activation': False, 'authenticated_smoke': False,
                'stage': 'prepare', 'tls_sha256': TLS_SHA256, 'inner_nginx_sha256': NGINX}
    evidence['dev_audit_decision'] = proof['dev_audit_decision']
    paused = stopped = switched = reopened = False
    try:
        release.mkdir(mode=0o755)
        os.chmod(release, 0o755)
        with tarfile.open(folder / 'source.tar.gz') as bundle:
            require(all(green.safe_archive_member(release, m) for m in bundle.getmembers()), 'Unsafe source archive')
            bundle.extractall(release)
        for name, expected in proof['source_files'].items():
            require(green.digest(release / name) == expected, 'Source archive mismatch: ' + name)
        for name in OVERLAYS:
            (release / name).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(previous / name, release / name)
        shutil.copy2(previous / app_release.HISTORY, release / app_release.HISTORY)
        old_migrations, new_migrations = green.migrations(previous), green.migrations(release)
        require(new_migrations == old_migrations, 'No migration delta is authorized')
        shutil.copytree(previous / 'public/build', release / 'public/build', dirs_exist_ok=True)
        info = (previous / '.env').stat()
        (release / '.env').write_text(tc12.environment((previous / '.env').read_text(), {
            'APP_BUILD_SHA': SOURCE, 'APP_BUILD_REF': 'dev@' + SOURCE[:8] + '+p06-master+preserved-runtime-hotfixes',
            'APP_BUILD_TIME': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'AUTO_MIGRATE': 'false'}))
        os.chown(release / '.env', info.st_uid, info.st_gid)
        os.chmod(release / '.env', 0o640)
        shutil.copy2(previous / 'docker-compose.vendor-release.yml', release / 'docker-compose.vendor-release.yml')
        model = json.loads(green.compose(release, ['config', '--format', 'json']))
        for service in SERVICES:
            row = model['services'][service]
            require(row['image'] == images[service] and row['environment']['AUTO_MIGRATE'] == 'false'
                    and row['environment']['DB_DATABASE'] == green.DATABASE, 'Resolved service policy changed')
        # The lock and vendor remain byte-identical; PSR-4 must discover the new class without mutating vendor.
        code = r"$l=require 'vendor/autoload.php'; if($l->isClassMapAuthoritative() || !class_exists('Modules\Warehouse\Support\StockDocumentEventEvidence')) exit(2);"
        # Docker cannot create a nested volume mountpoint under a read-only source bind.
        (release / 'vendor').mkdir(mode=0o755)
        result = subprocess.run(['docker', 'run', '--rm', '--network', 'none', '-w', '/var/www/html',
                                 '-v', str(release) + ':/var/www/html:ro', '-v', vendor + ':/var/www/html/vendor:ro',
                                 '--entrypoint', 'php', green.IMAGE_ID, '-r', code],
                                capture_output=True, text=True, timeout=120)
        if result.returncode:
            reason = next((label for text, label in (
                ('read-only file system', 'READ_ONLY_MOUNTPOINT'), ('permission denied', 'MOUNT_PERMISSION'),
                ('no such file', 'MISSING_MOUNTPOINT'), ('out of memory', 'CONTAINER_MEMORY'))
                if text in result.stderr.lower()), 'PROBE_EXECUTION_FAILED')
            raise RuntimeError('Isolated autoload probe: ' + reason + ', exit=' + str(result.returncode))
        evidence['stage'] = 'pause-and-backup'
        green.artisan('down', '--retry=30')
        paused = stopped = True
        green.compose(previous, ['stop', '-t', '120', *SERVICES], timeout=240)
        before_data = green.fingerprints()
        before_counts = app_release.business_counts()
        before_media = green.media_manifest(previous)
        backup = green.ROOT / 'backups' / ('wms_hoanam_green_pre_p06_release_' + stamp + '.dump')
        with open(backup, 'xb') as stream:
            result = subprocess.run(['docker', 'exec', 'hoa_nam_green-postgres-1', 'pg_dump', '-U', 'postgres',
                                     '-d', green.DATABASE, '-Fc'], stdout=stream, stderr=subprocess.PIPE, timeout=240)
        os.chmod(backup, 0o600)
        require(result.returncode == 0 and backup.stat().st_size > 100000, 'Backup failed')
        with open(backup, 'rb') as stream:
            result = subprocess.run(['docker', 'exec', '-i', 'hoa_nam_green-postgres-1', 'pg_restore', '--list'],
                                    stdin=stream, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
        require(result.returncode == 0 and b'TABLE DATA' in result.stdout, 'Backup catalog invalid')
        evidence['backup'] = {'path': str(backup), 'bytes': backup.stat().st_size,
                              'sha256': green.digest(backup), 'catalog_validated': True}
        evidence['before_tables'] = before_data
        shutil.copytree(previous / 'storage', release / 'storage', dirs_exist_ok=True, symlinks=True)
        assert_unchanged(before_media, green.media_manifest(release), 'Copied media')
        require(shutil.disk_usage(green.ROOT).free > RESERVE, 'Disk reserve lost')
        green.run(['docker', 'run', '--rm', '--network', 'none', '-v', str(release) + ':/var/www/html',
                   '--entrypoint', '/usr/local/bin/ensure-runtime-permissions.sh', green.IMAGE_ID], timeout=180)
        evidence['stage'] = 'switch'
        green.switch(release, stamp)
        switched = True
        green.compose(release, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', 'backend', 'nginx'])
        tc12.wait_ready()
        green.artisan('package:discover')
        for command in ('config:clear', 'route:clear', 'event:clear', 'view:clear', 'config:cache', 'route:cache'):
            green.artisan(command)
        green.artisan('l5-swagger:generate', timeout=180)
        transport_guard(release)
        after_policy = app_release.configuration()
        require(after_policy['sha'] == SOURCE, 'Build SHA mismatch')
        assert_unchanged({k: v for k, v in before_policy.items() if k != 'sha'},
                         {k: v for k, v in after_policy.items() if k != 'sha'}, 'Runtime policy')
        after_schema = app_release.migration_state()
        require(after_schema == before_schema, 'Unexpected schema history')
        after_data = green.fingerprints()
        assert_unchanged(before_data, after_data, 'Full database fingerprints including migrations')
        assert_unchanged(before_media, green.media_manifest(release), 'Media')
        assert_unchanged(before_counts, app_release.business_counts(), 'Product/image/stock counts')
        swagger = json.loads((release / 'storage/api-docs/api-docs.json').read_text())
        p06_paths = [name for name in swagger['paths'] if name.startswith('/api/v1/mini-app/catalog/')]
        require(len(p06_paths) == 9 and all('get' in swagger['paths'][name] for name in p06_paths), 'P06 OpenAPI mismatch')
        metadata = swagger['components']['schemas']['P06CardMetadataR1']
        require(all(metadata['properties'][k]['type'] == 'string' and metadata['properties'][k]['nullable']
                    for k in ('category_name', 'brand_name', 'description')), 'P06 metadata schema mismatch')
        routes = json.loads(green.artisan('route:list', '--json', '--path=api/v1/mini-app/catalog'))
        details = [r for r in routes if r['uri'].startswith('api/v1/mini-app/catalog/')]
        require(len(details) == 9 and all(r['method'] == 'GET|HEAD' for r in details), 'P06 owner routes changed')
        evidence.update({'policy_after': after_policy, 'migration_count': 220, 'pending': after_schema['pending'],
                         'business_data_unchanged': True, 'media_unchanged': True, 'verified_tables': len(before_data),
                         'media_files': len(before_media), 'counts_before': before_counts, 'counts_after': before_counts,
                         'comparison_scope': 'MAINTENANCE_BEFORE_REOPEN', 'routes': details,
                         'swagger_sha256': green.digest(release / 'storage/api-docs/api-docs.json'),
                         'source_sha256_after': {name: green.digest(release / name) for name in OVERLAYS}})
        green.emit('p06_release_verified_maintenance', {k: evidence[k] for k in ('source_sha', 'migration_count',
                   'business_data_unchanged', 'media_unchanged', 'verified_tables', 'media_files', 'counts_before',
                   'counts_after', 'routes', 'swagger_sha256')})
        green.artisan('up')
        paused = False
        reopened = True
        green.compose(release, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', 'queue', 'scheduler'])
        green.compose(release, ['restart', 'backend'])
        tc12.wait_ready()
        for service in SERVICES:
            assert_unchanged(after_policy, app_release.configuration(service), 'Service release/config')
        evidence['stage'] = 'public-smoke'
        app_release.SOURCE = SOURCE
        evidence['public_http'] = app_release.public_probe(green.ORIGIN) + app_release.public_probe('https://appqr.lptech.info.vn/wms-api')
        transport_guard(release)
        evidence.update({'stage': 'complete', 'free_bytes_after': shutil.disk_usage(green.ROOT).free,
                         'completed_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat()})
        (release / 'release-operations.json').write_text(json.dumps(evidence, indent=2))
        green.emit('p06_release_deployed', {k: evidence[k] for k in ('source_sha', 'ops_sha', 'stage', 'migration_count',
                   'business_data_unchanged', 'media_unchanged', 'verified_tables', 'media_files', 'free_bytes_after',
                   'migration_run', 'seed_run', 'ci_run', 'authenticated_smoke', 'completed_at_utc')})
        green.emit('p06_release_public_http', evidence['public_http'])
    except Exception as error:
        evidence['error_type'] = type(error).__name__
        green.emit('p06_release_failed', {'stage': evidence['stage'], 'error_type': type(error).__name__,
                                     'safe_message': str(error) if isinstance(error, RuntimeError) else 'See private report'})
        if not reopened:
            if switched:
                green.switch(previous, stamp)
            if stopped or paused:
                green.compose(previous, ['up', '-d', '--no-build', '--no-deps', '--force-recreate', *SERVICES, 'nginx'])
                tc12.wait_ready()
                green.artisan('up')
                evidence['application_rollback'] = True
        else:
            evidence['manual_recovery_required'] = True
        raise
    finally:
        report.write_text(json.dumps(evidence, indent=2))
        os.chmod(report, 0o600)
        green.emit('p06_release_private_report', {'path': str(report)})


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




