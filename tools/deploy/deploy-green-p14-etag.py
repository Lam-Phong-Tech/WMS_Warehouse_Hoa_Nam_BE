#!/usr/bin/env python3
"""Nginx-only green promotion. No PHP release, auth login, END or database write."""

import argparse
import datetime
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import urllib.error
import urllib.request
import uuid


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


green = module('green', 'deploy-green-verified.py')
app_release = module('app_release', 'deploy-green-app-20261024.py')
BASE = '4e83257c0dc982250e50b409c2afc158ac2d6bb1'
PHP_SHA = 'a9053a21bc35ece367be0b76db727d4749b59855'
RELEASE = PHP_SHA + '-app-20261024T022535Z'
FILES = {
    'docker/nginx/default.conf.template': '6ae6f30c4bfaec1f0fe039c929b71368a1da795ea33ed667dcef7d22069a85ae',
    'docker/nginx/perf/default.conf.template': 'fb40d68877e99a5f981c6a7d239266b783ab825c10f8a1a6cd7ec49d468d5a5a',
}
SHIFT = '5e8e6150-4c76-4313-879f-d0884ee9c085'
WAREHOUSE = '01a06f33-6bff-70b8-a089-3c68a83b11a8'
CONTAINER = 'hoa_nam_green-nginx-1'


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def package(folder, sha):
    require(re.fullmatch('[0-9a-f]{40}', sha), 'Invalid SHA')
    git = app_release.tc12.git
    require(git(folder, 'rev-parse', 'HEAD').decode().strip() == sha, 'Checkout mismatch')
    git(folder, 'diff', '--exit-code', 'HEAD')
    git(folder, 'merge-base', '--is-ancestor', BASE, sha)
    git(folder, 'diff', '--exit-code', BASE, sha, '--', 'app', 'bootstrap', 'config', 'database', 'Modules', 'routes',
        'composer.json', 'composer.lock', 'artisan', 'docker/php', 'docker-compose.yml', 'docker-compose.perf.yml',
        'public', 'resources', 'package.json', 'package-lock.json')
    changed = git(folder, 'diff', '--name-only', BASE, sha, '--', 'docker').decode().splitlines()
    require(set(changed) == set(FILES), 'Unexpected Docker runtime change')
    for name, expected in FILES.items():
        require(app_release.sha(git(folder, 'show', BASE + ':' + name)) == expected, 'Base template changed')
        require((folder / name).read_bytes() == git(folder, 'show', sha + ':' + name), 'Uncommitted template')
    proof = {'nginx_config_sha': sha, 'php_sha_unchanged': PHP_SHA, 'base': BASE,
             'base_hashes': FILES, 'candidate_hashes': {name: green.digest(folder / name) for name in FILES}}
    (folder / 'p14-nginx-proof.json').write_text(json.dumps(proof, indent=2))
    green.emit('p14_nginx_packaged', proof)


def probe(base):
    results = []
    for path in ('/api/v1/health', '/api/v1/mini-app/work-shifts/' + SHIFT + '/close-check?warehouse_id=' + WAREHOUSE):
        for encoding in ('gzip', 'identity'):
            rid = str(uuid.uuid4())
            request = urllib.request.Request(base + path, headers={'Accept': 'application/json',
                'Accept-Encoding': encoding, 'Origin': 'https://appqr.lptech.info.vn',
                'Cache-Control': 'no-cache', 'X-Request-ID': rid})
            try:
                response = urllib.request.urlopen(request, timeout=25)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                raw = response.read()
                import gzip
                if response.headers.get('Content-Encoding') == 'gzip':
                    raw = gzip.decompress(raw)
                value = json.loads(raw)
                health = path.endswith('/health')
                require(response.status == (200 if health else 401), 'Public health/unauthenticated guard failed')
                if health:
                    require(value['data']['build']['sha'] == PHP_SHA and value['data']['checks']['migrations']['ran_count'] == 219,
                            'PHP build/schema unexpectedly changed')
                results.append({'method': 'GET', 'url': base + path, 'accept_encoding': encoding,
                                'status': response.status, 'sent_request_id': rid, 'credential_sent': False,
                                'observed_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                                'headers': {k: v for k, v in response.headers.items()
                                            if k.lower() not in ('authorization', 'cookie', 'set-cookie')},
                                'raw_body': raw.decode(), 'authenticated_p14_etag_acceptance': False})
    return results


def apply(folder, sha, inspect_only):
    os.umask(0o077)
    require(__debug__ and green.ROOT.resolve() == green.ROOT, 'Invalid runtime/root')
    release = (green.ROOT / 'current').resolve()
    require(release == green.ROOT / 'releases' / RELEASE, 'PHP release changed; review needed')
    proof = json.loads((folder / 'p14-nginx-proof.json').read_text())
    require(proof['nginx_config_sha'] == sha and proof['php_sha_unchanged'] == PHP_SHA
            and proof['base_hashes'] == FILES, 'Source proof mismatch')
    prior = json.loads((release / 'release-operations.json').read_text())
    require(prior['source_sha'] == PHP_SHA and prior['stage'] == 'complete', 'Previous release proof mismatch')
    for name, expected in prior['source_sha256_after'].items():
        require(green.digest(release / name) == expected, 'Runtime overlay drift: ' + name)
    for name, expected in FILES.items():
        require(green.digest(release / name) == expected, 'Live nginx template drift: ' + name)
        require(green.digest(folder / name) == proof['candidate_hashes'][name], 'Candidate changed')
        require(not (release / name).is_symlink(), 'Template symlink prohibited')
    before = app_release.configuration()
    require(before['sha'] == PHP_SHA and before['database'] == green.DATABASE and before['environment'] == 'testing'
            and before['protected'] and not before['allow_seed'] and not before['p11'] and not before['p19'], 'Runtime policy mismatch')
    row = json.loads(green.run(['docker', 'inspect', CONTAINER]))[0]
    require(row['State']['Running'] and row['Config']['Labels']['com.docker.compose.project'] == green.PROJECT,
            'Wrong nginx service')
    require(any(m['Destination'] == '/var/www/html' and m['Source'] == str(release) for m in row['Mounts']),
            'Wrong nginx source mount')
    counts_before = app_release.business_counts()
    schema_before = green.pg('SELECT count(*) FROM migrations')
    version = subprocess.run(['docker', 'exec', CONTAINER, 'nginx', '-v'], capture_output=True, text=True, check=True)
    green.emit('p14_nginx_preflight', {'php_sha': PHP_SHA, 'nginx_config_sha': sha, 'nginx_image': row['Image'],
                                     'nginx_version': version.stderr.strip(), 'policy': before,
                                     'migration_count': schema_before, 'counts': counts_before,
                                     'candidate_hashes': proof['candidate_hashes']})
    # Same Linux nginx image as the serving container; no application mounts, secrets or real FastCGI.
    transport = green.run(['docker', 'run', '--rm', '--network', 'host', '-v', str(folder) + ':/workspace:ro',
                           '-w', '/workspace', '-e', 'NGINX_TEST_TMP=/tmp', '--entrypoint', 'sh', row['Image'],
                           '-lc', 'apk add --no-cache python3 >/dev/null && python3 scripts/test-p10-nginx-etag.py'], timeout=240)
    parsed = json.loads(transport.strip().splitlines()[-1])
    require(parsed['count'] == 42 and all(case['pass'] for case in parsed['transport_cases'])
            and not parsed['live_verified'], 'Linux transport verification failed')
    green.emit('p14_nginx_linux_transport', parsed)
    if inspect_only:
        return
    require(shutil.disk_usage(green.ROOT).free > 512 * 1024 ** 2, 'Insufficient reserve; no cleanup authorized')
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = green.ROOT / 'backups' / ('p14-nginx-' + stamp)
    backup.mkdir(mode=0o700)
    rendered = green.run(['docker', 'exec', CONTAINER, 'cat', '/etc/nginx/conf.d/default.conf'])
    (backup / 'rendered-default.conf').write_text(rendered)
    report = {'nginx_config_sha': sha, 'php_sha_unchanged': PHP_SHA, 'nginx_image': row['Image'],
              'nginx_version': version.stderr.strip(), 'source_base': BASE, 'policy_before': before,
              'base_hashes': FILES, 'candidate_hashes': proof['candidate_hashes'],
              'backup_directory': str(backup), 'stage': 'prepare', 'migration_run': False, 'seed_run': False,
              'ci_run': False, 'production_activation': False, 'new_login': False, 'real_end_command': False,
              'linux_transport': parsed, 'counts_before': counts_before, 'authenticated_acceptance': False}
    touched = False
    try:
        for name in FILES:
            target = release / name
            stored = backup / name
            stored.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(target, stored)
        report['stage'] = 'write-template-and-render'
        touched = True
        for name in FILES:
            target = release / name
            inode = target.stat().st_ino
            # Keep the bind-mounted inode; atomic rename would leave the container on the old template.
            target.write_bytes((folder / name).read_bytes())
            require(target.stat().st_ino == inode and green.digest(target) == proof['candidate_hashes'][name],
                    'Template inode/hash changed')
        green.run(['docker', 'exec', CONTAINER, 'sh', '/docker-entrypoint.d/20-envsubst-on-templates.sh'])
        green.run(['docker', 'exec', CONTAINER, 'nginx', '-t'])
        report['stage'] = 'graceful-nginx-reload'
        green.run(['docker', 'exec', CONTAINER, 'nginx', '-s', 'reload'])
        after = app_release.configuration()
        require(after == before and green.pg('SELECT count(*) FROM migrations') == schema_before,
                'PHP/schema/flags changed unexpectedly')
        report['policy_after'] = after
        report['counts_after'] = app_release.business_counts()
        report['counts_unchanged_at_observation'] = report['counts_after'] == counts_before
        report['count_scope'] = 'LIVE_READ_ONLY_OBSERVATION_NOT_FROZEN_ROW_FIELD_ACCEPTANCE'
        report['public_http'] = probe(green.ORIGIN) + probe('https://appqr.lptech.info.vn/wms-api')
        denied = [r for r in report['public_http'] if r['status'] == 401]
        report['origin_and_proxy_no_transform_on_401'] = all('no-transform' in next(
            (v for k, v in r['headers'].items() if k.lower() == 'cache-control'), '') for r in denied)
        report.update({'stage': 'complete', 'completed_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                       'free_bytes_after': shutil.disk_usage(green.ROOT).free,
                       'scope': 'NGINX_CONFIG_DEPLOYED_AUTHENTICATED_P14_ETAG_STILL_PENDING'})
        (release / 'p14-nginx-release.json').write_text(json.dumps(report, indent=2))
        green.emit('p14_nginx_deployed', report)
    except Exception as error:
        report['error_type'] = type(error).__name__
        if touched:
            for name in FILES:
                (release / name).write_bytes((backup / name).read_bytes())
            green.run(['docker', 'exec', '-i', CONTAINER, 'sh', '-c', 'cat > /etc/nginx/conf.d/default.conf'], input_data=rendered)
            green.run(['docker', 'exec', CONTAINER, 'nginx', '-t'])
            green.run(['docker', 'exec', CONTAINER, 'nginx', '-s', 'reload'])
            report['nginx_rollback_only'] = True
        green.emit('p14_nginx_failed', {'stage': report['stage'], 'error_type': type(error).__name__,
                                      'safe_message': str(error) if isinstance(error, RuntimeError) else 'See private report'})
        raise
    finally:
        (backup / 'report.json').write_text(json.dumps(report, indent=2))
        green.emit('p14_nginx_report', {'path': str(backup / 'report.json')})


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--package', action='store_true')
    parser.add_argument('--checkout', required=True)
    parser.add_argument('--ops-sha', required=True)
    parser.add_argument('--inspect', action='store_true')
    args = parser.parse_args()
    if args.package:
        package(Path(args.checkout), args.ops_sha)
    else:
        apply(Path(args.checkout), args.ops_sha, args.inspect)
