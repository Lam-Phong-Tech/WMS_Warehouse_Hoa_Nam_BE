"""One approved DEV/TEST transfer; no production, seed, schema or data repair."""
import argparse
import datetime
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile

HERE = Path(__file__).parent
spec = importlib.util.spec_from_file_location('p06_release', HERE / 'deploy-green-p06-master.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
green = release.green
NEW_HOST_KEY = '14.225.37.158 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIkPmCoxeO+4XyrbHSw3ojHzzfvtovcPyi/x1NwuOGZF\n'
TRANSFER = Path('/opt/hoa-nam-green/backups/vps-transfer-20261028')
SERVICES = ('backend', 'queue', 'scheduler', 'reverb', 'minio', 'valkey')


class TransferWriter:
    def __init__(self, stream):
        self.stream = stream
        self.digest = hashlib.sha256()
        self.bytes = 0

    def write(self, block):
        result = self.stream.write(block)
        assert result == len(block)
        self.digest.update(block)
        self.bytes += result
        return result


def routing_inspect():
    found = {}
    for folder in ('sites-enabled', 'sites-available', 'conf.d'):
        for path in (Path('/etc/nginx') / folder).glob('*'):
            if not path.is_file() or path.suffix in ('.key', '.pem', '.crt'):
                continue
            text = path.read_text()
            if 'appqr.lptech.info.vn' in text:
                found[str(path.resolve())] = {'sha256': green.digest(path), 'routes': [line.strip() for line in text.splitlines() if re.match(r'\s*(server_name|location|proxy_pass)\s', line)]}
    green.emit('devtest_preview_routing', found)


def container(service):
    row = json.loads(green.run(['docker', 'inspect', 'hoa_nam_green-' + service + '-1']))[0]
    assert row['Config']['Labels']['com.docker.compose.project'] == 'hoa_nam_green'
    return row


def volume(service, destination):
    rows = [m for m in container(service)['Mounts'] if m['Destination'] == destination]
    assert len(rows) == 1 and rows[0]['Type'] == 'volume'
    path = Path(rows[0]['Source']).resolve()
    path.relative_to('/var/lib/docker/volumes')
    return path


def manifest_tree(path):
    result = {}
    for row in path.rglob('*'):
        if row.is_symlink():
            raise RuntimeError('Unexpected storage/volume symlink')
        if row.is_file():
            result[str(row.relative_to(path))] = green.digest(row)
    return result


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser()
    parser.add_argument('--export', action='store_true')
    parser.add_argument('--routing-inspect', action='store_true')
    args = parser.parse_args()
    assert os.getuid() == 0
    if args.routing_inspect:
        routing_inspect()
        return
    TRANSFER.mkdir(mode=0o700, exist_ok=True)
    key = TRANSFER / 'transfer_key'
    if not key.exists():
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'wms-devtest-migration-20261028', '-f', str(key)], check=True)
    (TRANSFER / 'known_hosts').write_text(NEW_HOST_KEY)
    root = (green.ROOT / 'current').resolve()
    assert root.parent == green.ROOT / 'releases'
    policy = release.app_release.configuration()
    assert policy['sha'] == release.SOURCE and policy['environment'] == 'testing'
    assert policy['database'] == 'wms_hoanam_green' and policy['protected'] and not policy['allow_seed']
    report = json.loads((root / 'release-operations.json').read_text())
    for name, value in report['source_sha256_after'].items():
        assert green.digest(root / name) == value
    schema = release.app_release.migration_state()
    release.schema_guard(schema)
    minio = volume('minio', '/data')
    valkey = volume('valkey', '/data')
    safe_env = {}
    for line in (root / '.env').read_text().splitlines():
        if '=' in line and line.split('=', 1)[0] in ('DB_DATABASE', 'DB_USERNAME', 'FILESYSTEM_DISK', 'DOCUMENT_ATTACHMENT_DISK', 'PV_IMAGE_DISK'):
            k, v = line.split('=', 1)
            safe_env[k] = v
    green.emit('devtest_vps_transfer_preflight', {
        'source_sha': policy['sha'], 'new_host': '14.225.37.158', 'environment': 'DEV_TEST_ONLY',
        'new_public_key': key.with_suffix('.pub').read_text().strip(), 'schema_count': len(schema['ran']),
        'safe_configuration': safe_env, 'free_bytes': shutil.disk_usage(TRANSFER).free,
        'database_bytes': int(green.pg('SELECT pg_database_size(current_database())')),
        'storage_bytes': int(green.run(['du', '-sb', str(root / 'storage/app')]).split()[0]),
        'minio_bytes': int(green.run(['du', '-sb', str(minio)]).split()[0]),
        'data_written': False, 'services_stopped': False, 'production_touched': False,
    })
    if not args.export:
        return
    assert shutil.disk_usage(TRANSFER).free > 128 * 1024 ** 2
    done = TRANSFER / 'export-complete.json'
    assert not done.exists(), 'Transfer already completed; never stop or resnapshot blindly'
    with (green.ROOT / '.deployment.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        present = set(green.run(['docker', 'ps', '-a', '--filter', 'label=com.docker.compose.project=hoa_nam_green', '--format', '{{.Names}}']).splitlines())
        assert all('hoa_nam_green-' + s + '-1' in present for s in ('backend', 'queue', 'scheduler', 'minio', 'valkey'))
        running = [s for s in SERVICES if 'hoa_nam_green-' + s + '-1' in present and container(s)['State']['Running']]
        stopped = False
        try:
            # Quiesce only the approved green application and private state stores.
            stopped = True
            green.run(['docker', 'stop', '--time', '120', *['hoa_nam_green-' + s + '-1' for s in running]], timeout=240)
            before = green.fingerprints()
            media = green.media_manifest(root)
            attempt = TRANSFER / datetime.datetime.now(datetime.timezone.utc).strftime('attempt-%Y%m%dT%H%M%SZ')
            attempt.mkdir(mode=0o700)
            dump = attempt / 'database.dump'
            assert not dump.exists()
            with dump.open('xb') as stream:
                result = subprocess.run(['docker', 'exec', 'hoa_nam_green-postgres-1', 'pg_dump', '-U', 'postgres', '-d', 'wms_hoanam_green', '-Fc', '--no-owner', '--no-acl'], stdout=stream, stderr=subprocess.PIPE, timeout=600)
            assert result.returncode == 0, 'Database dump failed; sensitive stderr suppressed'
            assert dump.stat().st_size > 1024
            contents = subprocess.run(['docker', 'exec', '-i', 'hoa_nam_green-postgres-1', 'pg_restore', '--list'], stdin=dump.open('rb'), stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=60)
            assert contents.returncode == 0, 'Database dump validation failed'
            data = {
                'source_sha': policy['sha'], 'database': 'wms_hoanam_green', 'created_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                'tables': before, 'schema': schema, 'media': media, 'minio': manifest_tree(minio), 'valkey': manifest_tree(valkey),
                'overlays': report['source_sha256_after'], 'policy': policy, 'safe_configuration': safe_env,
                'dump_sha256': green.digest(dump), 'dump_bytes': dump.stat().st_size,
                'images': {s: json.loads(green.run(['docker', 'image', 'inspect', container(s)['Image']]))[0].get('RepoDigests', []) for s in ('postgres', 'minio', 'valkey', 'nginx')},
                'stopped_services': running, 'production_touched': False, 'seed_run': False, 'migration_run': False,
            }
            assert before == green.fingerprints(), 'Database changed after quiesce'
            assert media == green.media_manifest(root), 'Media changed after quiesce'
            manifest = attempt / 'manifest.json'
            manifest.write_text(json.dumps(data))
            ssh = subprocess.Popen(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(TRANSFER / 'known_hosts'), '-i', str(key), 'root@14.225.37.158', 'receive-devtest-20261028'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            try:
                writer = TransferWriter(ssh.stdin)
                with tarfile.open(fileobj=writer, mode='w|gz') as archive:
                    for path, name in ((manifest, 'manifest.json'), (dump, 'database.dump'), (root / '.env', 'private/app.env'), (root / 'storage/app', 'storage/app'), (minio, 'minio'), (valkey, 'valkey')):
                        archive.add(path, arcname=name, recursive=True)
                    for name in data['overlays']:
                        archive.add(root / name, arcname='overlays/' + name, recursive=False)
                ssh.stdin.close()
                response = ssh.stdout.read(4096)
                error = ssh.stderr.read(4096)
                code = ssh.wait(timeout=600)
                assert code == 0, 'Secure receiver failed; sensitive error suppressed'
                receipt = json.loads(response)
                assert receipt['received'] and receipt['bytes'] == writer.bytes and receipt['sha256'] == writer.digest.hexdigest()
            finally:
                if ssh.poll() is None:
                    ssh.kill()
                    ssh.wait()
            done.write_text(json.dumps({'receipt': receipt, 'manifest_sha256': green.digest(manifest)}))
            green.emit('devtest_vps_export_complete', {'receipt': receipt, 'manifest_sha256': green.digest(manifest), 'table_count': len(before), 'old_dev_standby': True, 'old_production_unchanged': True, 'seed_run': False, 'migration_run': False})
        except Exception:
            if stopped:
                green.run(['docker', 'start', *['hoa_nam_green-' + s + '-1' for s in running]], timeout=240)
            raise


if __name__ == '__main__':
    main()
