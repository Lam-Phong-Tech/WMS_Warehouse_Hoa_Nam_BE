"""DEV-only P05 rollout with one allowlisted additive migration; no seed/Post."""
import argparse
import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil

spec = importlib.util.spec_from_file_location('p18_deploy', Path(__file__).with_name('deploy-devtest-p18-location.py'))
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
BASE = '99ffd6c652a662470c762da3487e743ccb67497a'
COMPOSE_HASH = '08c9d07fa050bbc4fc0157dd1c1b652b974f523bfb42165434be4205b5b4da66'
MIGRATION = '2026_11_03_000001_add_p05_outbound_number_reservations'
MIGRATION_FILE = 'database/migrations/' + MIGRATION + '.php'
APP_FILES = {
    'Modules/Warehouse/app/Services/OutboundDocumentNumber.php',
    'Modules/Warehouse/app/Services/P05NumberedDraft.php',
    'Modules/Warehouse/app/Services/MiniAppDraftService.php',
    'Modules/Warehouse/app/Services/StockDocumentService.php',
    'Modules/Warehouse/app/Http/Controllers/P03DraftController.php',
    'Modules/Warehouse/app/Http/Controllers/P05OutboundController.php',
    'Modules/Warehouse/app/Http/Controllers/MiniAppOutboundDraftController.php',
    'Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php',
    'Modules/Warehouse/app/Http/Controllers/BaM1InboundDocumentController.php',
    'Modules/Warehouse/app/Http/Middleware/MiniAppDraftHandoff.php',
    'app/Swagger/P05SaveNumberingSchemas.php',
    'app/Swagger/MiniAppOutboundStagingOpenApi.php',
    'config/l5-swagger.php',
    MIGRATION_FILE,
}
base.BASE = BASE
base.APP_FILES = APP_FILES


def allowed(path):
    return path in APP_FILES or path.startswith(('tests/Feature/P05SaveNumbering', 'tests/Feature/P05NumberingConcurrency',
        'docs/contracts/p05-save-numbering-', 'docs/fe-handoff/P05_SAVE_NUMBERING_', 'tools/deploy/deploy-devtest-p05-numbering',
        'tools/deploy/test_deploy_devtest_p05_numbering'))


base.allowed = allowed


def db_json(sql):
    text = base.run(['docker', 'exec', 'hoa_nam_dev-postgres-1', 'psql', '-X', '-q', '-U', 'postgres', '-d', 'wms_hoanam_green',
        '-v', 'ON_ERROR_STOP=1', '-Atc', 'BEGIN READ ONLY; SET LOCAL statement_timeout=15000; ' + sql + '; ROLLBACK;'])
    return json.loads(text)


def schema():
    return db_json("SELECT coalesce(json_object_agg(migration,batch),'{}'::json) FROM migrations")


def domain():
    result = {}
    for table, order in [('sku', 'sku_id'), ('item_instances', 'item_id'), ('stock_documents', 'id'),
                         ('stock_movements', 'id'), ('inventory_balances', 'id'), ('containers', 'container_id')]:
        result[table] = db_json("SELECT json_build_object('count',count(*),'fingerprint',md5(coalesce(string_agg(to_jsonb(t)::text,',' ORDER BY " + order + "),''))) FROM " + table + " t")
    return result


def assert_migration_delta(before, after):
    if MIGRATION in before or set(after) != set(before) | {MIGRATION} or any(after[k] != v for k, v in before.items()):
        raise RuntimeError('Only the approved P05 migration may be added')
    if after[MIGRATION] != max(before.values(), default=0) + 1:
        raise RuntimeError('Unexpected P05 migration batch')


def migration_command():
    return base.compose_args() + ['run', '--rm', '--no-deps', '-e', 'AUTO_MIGRATE=false',
        '-e', 'APP_CONFIG_CACHE=/tmp/p05-config.php', '-e', 'APP_ROUTES_CACHE=/tmp/p05-routes.php',
        '--entrypoint', 'php', 'backend', 'artisan', 'migrate', '--force', '--no-interaction', '--path=' + MIGRATION_FILE]


def swagger_command():
    return ['docker', 'exec', 'hoa_nam_dev-backend-1', 'php', '-d', 'memory_limit=512M', 'artisan', 'l5-swagger:generate']


def schema_mode(current, baseline, resume):
    if len(current) == 221 and MIGRATION not in current and not resume:
        return 'APPLY_ONCE'
    if resume and len(current) == 222 and len(baseline) == 221:
        assert_migration_delta(baseline, current)
        return 'ALREADY_APPLIED_SKIP'
    raise RuntimeError('Unexpected schema or resume decision; no migration is inferred')


def candidate_compose(config, target, sha):
    modified, current = base.patched_compose(config, target, sha)
    for service in base.SERVICES:
        if service != 'nginx':
            modified['services'][service]['environment']['APP_BUILD_REF'] = 'dev-p05-save-numbering@' + sha
            # Build once in the bounded CLI process; FPM serves the release JSON.
            modified['services'][service]['environment']['L5_SWAGGER_GENERATE_ALWAYS'] = 'false'
    return modified, current


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--sha', required=True)
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--manifest', type=Path, required=True)
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--resume-schema', action='store_true')
    args = parser.parse_args()
    if not re.fullmatch('[a-f0-9]{40}', args.sha) or args.sha == BASE or os.getuid() != 0:
        raise RuntimeError('Exact candidate and approved DEV operator required')
    path = base.ROOT / 'compose.json'
    original = path.read_bytes()
    if hashlib.sha256(original).hexdigest() != COMPOSE_HASH:
        raise RuntimeError('DEV compose drift; inspect again')
    target = base.ROOT / 'releases' / (args.sha + '-p05-save-numbering-20261103')
    modified, current = candidate_compose(json.loads(original), target, args.sha)
    if current.parent != base.ROOT / 'releases' or not current.is_dir() or target.exists():
        raise RuntimeError('Unexpected source release')
    files = base.archive_files(args.archive)
    manifest = json.loads(args.manifest.read_text())
    if manifest['base'] != BASE or manifest['candidate'] != args.sha or base.digest(args.archive) != manifest['archive_sha256']:
        raise RuntimeError('Package manifest mismatch')
    for name, checksum in manifest['base_files'].items():
        if base.digest(current / name) != checksum:
            raise RuntimeError('Retained runtime source/asset drift: ' + name)
    if set(manifest['files']) != set(files):
        raise RuntimeError('File manifest mismatch')
    for name, blob in files.items():
        if hashlib.sha256(blob).hexdigest() != manifest['files'][name]:
            raise RuntimeError('Candidate file hash mismatch')
    before = schema()
    mode = schema_mode(before, manifest.get('migration_baseline', {}), args.resume_schema)
    if mode == 'ALREADY_APPLIED_SKIP' and db_json('SELECT to_json(count(*)) FROM outbound_draft_numbers') != 0:
        raise RuntimeError('Existing issued numbers require a separately reviewed forward release')
    prod = sorted(base.production_ids())
    baseline_data = domain()
    report = {'base': BASE, 'candidate': args.sha, 'environment': 'DEV_TEST', 'apply': args.apply,
        'migration_allowlist': [MIGRATION_FILE], 'migrations_before': before, 'domain_before': baseline_data,
        'production_container_ids_before': prod, 'seed_executed': False, 'repair_executed': False,
        'schema_mode': mode, 'migration_executed': False}
    if not args.apply:
        print(json.dumps(report, indent=2))
        return
    os.umask(0o077)
    backup = base.ROOT / ('compose-before-p05-' + args.sha + '.json')
    backup.write_bytes(original)
    swagger_path = base.ROOT / 'shared/storage/api-docs/api-docs.json'
    swagger = swagger_path.read_bytes() if swagger_path.exists() else None
    shutil.copytree(current, target, symlinks=True)
    target.chmod(0o755)
    for name, blob in files.items():
        out = target / name
        if out.is_symlink() or any(p.is_symlink() for p in out.parents):
            raise RuntimeError('Symlink in candidate destination')
        out.parent.mkdir(parents=True, exist_ok=True)
        # This new migration directory/file must be readable by the PHP user.
        out.write_bytes(blob)
        out.chmod(0o644)
    for name, checksum in manifest['base_files'].items():
        if name not in files and base.digest(target / name) != checksum:
            raise RuntimeError('Retained file changed after staging: ' + name)
    temp = base.ROOT / 'compose.p05.new.json'
    temp.write_text(json.dumps(modified, indent=2) + '\n')
    os.replace(temp, path)
    try:
        # One-shot CLI uses the candidate mount, existing DEV connection and
        # private cache paths. No pending P11 or other migration is executed.
        if mode == 'APPLY_ONCE':
            base.run(migration_command())
            report['migration_executed'] = True
        after = schema()
        if mode == 'APPLY_ONCE':
            assert_migration_delta(before, after)
        elif after != before:
            raise RuntimeError('Resumed rollout must not change schema history')
        report['migrations_after'] = after
        report['domain_after_migration'] = domain()
        if report['domain_after_migration'] != baseline_data:
            raise RuntimeError('Business data changed during additive schema step; inspect, never restore/repair automatically')
        base.run(base.compose_args() + ['up', '-d', '--no-deps', '--force-recreate', *base.SERVICES])
        base.run(['docker', 'exec', 'hoa_nam_dev-backend-1', 'php', 'artisan', 'route:cache'])
        base.run(swagger_command())
        report['health'] = base.healthy(args.sha)
        report['production_container_ids_after'] = sorted(base.production_ids())
        if report['production_container_ids_after'] != prod:
            raise RuntimeError('Production container drift')
        report['domain_after_rollout'] = domain()
        report['domain_unchanged_through_rollout'] = report['domain_after_rollout'] == baseline_data
        report['source_root'] = str(target)
        report['swagger_policy'] = 'CACHED_RELEASE_JSON_EXPLICIT_CLI_BUILD_512M'
        report['deployed_at'] = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=7))).isoformat()
        report['status'] = 'DEPLOYED_PENDING_AUTHENTICATED_OWNER_ACCEPTANCE'
    except Exception:
        # The old release is unaware of PX reservations. Once a caller has
        # received a number, only a reservation-aware forward fix is safe.
        table_exists = db_json("SELECT to_json(to_regclass('public.outbound_draft_numbers') IS NOT NULL)")
        count = db_json("SELECT to_json(count(*)) FROM outbound_draft_numbers") if table_exists else 0
        if count != 0:
            raise RuntimeError('Rollback held: PX reservations already exist; retain candidate and apply a reviewed forward fix')
        # No P05 number has escaped; restore code only and retain additive schema.
        path.write_bytes(original)
        if swagger is not None:
            swagger_path.write_bytes(swagger)
        base.run(base.compose_args() + ['up', '-d', '--no-deps', '--force-recreate', *base.SERVICES])
        raise
    (base.ROOT / ('p05-deploy-' + args.sha + '.json')).write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
