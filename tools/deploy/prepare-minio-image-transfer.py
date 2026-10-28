"""Prepare a one-time, source-only MinIO image transfer; no database or app writes."""
import datetime
import hashlib
import json
import os
from pathlib import Path
import subprocess

IMAGE = 'minio/minio@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e'
NEW_HOST = '14.225.37.158'
NEW_HOST_KEY = '14.225.37.158 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIkPmCoxeO+4XyrbHSw3ojHzzfvtovcPyi/x1NwuOGZF\n'
BASE = Path('/opt/hoa-nam-green/backups/minio-image-transfer-20261028')


def run(args):
    result = subprocess.run(args, capture_output=True, text=True, check=True)
    return result.stdout.strip()


def main():
    os.umask(0o077)
    BASE.mkdir(mode=0o700, parents=True, exist_ok=False)
    key = BASE / 'transfer_key'
    subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'wms-minio-image-20261028', '-f', str(key)], check=True)
    (BASE / 'known_hosts').write_text(NEW_HOST_KEY)
    inspect = json.loads(run(['docker', 'image', 'inspect', IMAGE]))[0]
    assert IMAGE.split('@', 1)[1] in inspect.get('RepoDigests', [])
    public = key.with_suffix('.pub').read_text().strip()
    print(json.dumps({'event': 'minio_image_transfer_prepared', 'data': {
        'image': IMAGE, 'image_id': inspect['Id'], 'public_key': public,
        'new_host': NEW_HOST, 'created_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'database_write': False, 'business_write': False,
    }}), flush=True)


if __name__ == '__main__':
    main()
