"""Read-only exact-release evidence. Never creates a session or business fixture."""
import importlib.util
import json
from pathlib import Path

spec = importlib.util.spec_from_file_location('release', Path(__file__).with_name('deploy-green-p06-master.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
green, require = release.green, release.require


def main():
    root = (green.ROOT / 'current').resolve()
    require(root.parent == green.ROOT / 'releases' and root.name.startswith(release.SOURCE + '-p06-master-'), 'Unexpected active release')
    report = json.loads((root / 'release-operations.json').read_text())
    require(report['source_sha'] == release.SOURCE and report['stage'] == 'complete'
            and report['migration_run'] is False and report['seed_run'] is False
            and report['audit_exception_used'] is False, 'Deployment proof incomplete')
    release.audit_gate(report['audits'])
    services = {}
    probe = r"""require 'vendor/autoload.php'; echo json_encode([
      'lock'=>hash_file('sha256','composer.lock'),'installed_lock'=>trim(file_get_contents('vendor/.composer-lock.sha256')),
      'spreadsheet'=>Composer\InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet'),
      'contract'=>Modules\Warehouse\Http\Controllers\P06CatalogController::CONTRACT,
      'revision'=>Modules\Warehouse\Http\Controllers\P06CatalogController::CARD_METADATA_REVISION]);"""
    for service in release.SERVICES:
        policy = release.app_release.configuration(service)
        require(policy['sha'] == release.SOURCE and policy == report['policy_after'], 'Service identity/config drift')
        value = json.loads(green.run(['docker', 'exec', '-u', 'www-data', 'hoa_nam_green-' + service + '-1', 'php', '-r', probe]))
        require(value['lock'] == value['installed_lock'] and value['spreadsheet'] == '5.10.0'
                and value['revision'] == 'p06-card-master-r1', 'Installed dependency/protocol mismatch')
        services[service] = {'policy': policy, 'installed': value}
    schema = release.app_release.migration_state()
    release.schema_guard(schema)
    release.transport_guard(root)
    release.app_release.SOURCE = release.SOURCE
    public = release.app_release.public_probe(green.ORIGIN) + release.app_release.public_probe('https://appqr.lptech.info.vn/wms-api')
    routes = json.loads(green.artisan('route:list', '--json', '--path=api/v1/mini-app/catalog'))
    require(len(routes) == 9 and all(r['method'] == 'GET|HEAD' for r in routes), 'P06 route mismatch')
    swagger = json.loads((root / 'storage/api-docs/api-docs.json').read_text())
    openapi = {'paths': {k: v for k, v in swagger['paths'].items() if k.startswith('/api/v1/mini-app/catalog/')},
               'schemas': {k: swagger['components']['schemas'][k] for k in ('P06CardMetadataR1', 'P06MetadataRevision')}}
    data = {key: report[key] for key in ('source_sha', 'ops_sha', 'release', 'previous_release', 'backup', 'business_data_unchanged',
            'media_unchanged', 'verified_tables', 'media_files', 'counts_before', 'counts_after', 'comparison_scope',
            'migration_run', 'seed_run', 'ci_run', 'production_activation', 'private_vendor', 'node_lock_sha256',
            'dependency_update', 'audits', 'audit_exception_used', 'completed_at_utc', 'swagger_sha256', 'source_sha256_after')}
    data.update({'services': services, 'schema': {'ran_count': len(schema['ran']), 'pending': schema['pending'], 'orphaned': schema['orphaned']},
                 'public_http': public, 'routes': routes, 'openapi_p06': openapi, 'authenticated_owner_http': False,
                 'no_new_login_or_business_write': True})
    payload = json.dumps(data)
    require(len(payload.encode()) < 60000, 'Evidence exceeds SSH bounded output; do not silently truncate')
    green.emit('p06_deployed_runtime_verified', data)


if __name__ == '__main__':
    main()
