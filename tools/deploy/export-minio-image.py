"""Stream the exact existing MinIO image to the new DEV/TEST host."""
import datetime
import gzip
import hashlib
import json
import os
from pathlib import Path
import subprocess

IMAGE = 'minio/minio@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e'
NEW_HOST = '14.225.37.158'
BASE = Path('/opt/hoa-nam-green/backups/minio-image-transfer-20261028')


class DigestWriter:
    def __init__(self, stream):
        self.stream = stream
        self.hash = hashlib.sha256()
        self.bytes = 0

    def write(self, block):
        written = self.stream.write(block)
        assert written == len(block)
        self.hash.update(block)
        self.bytes += written
        return written


def main():
    os.umask(0o077)
    key = BASE / 'transfer_key'
    known_hosts = BASE / 'known_hosts'
    assert key.is_file() and known_hosts.is_file()
    receiver = ['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile=' + str(known_hosts), '-i', str(key),
                'root@' + NEW_HOST, 'receive-minio-image-20261028']
    saved = subprocess.Popen(['docker', 'save', IMAGE], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    ssh = subprocess.Popen(receiver, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    writer = DigestWriter(ssh.stdin)
    try:
        with gzip.GzipFile(fileobj=writer, mode='wb', compresslevel=1) as compressed:
            while True:
                block = saved.stdout.read(1024 * 1024)
                if not block:
                    break
                compressed.write(block)
        ssh.stdin.close()
        saved_stdout, saved_stderr = saved.communicate(timeout=600)
        response = ssh.stdout.read(4096)
        error = ssh.stderr.read(4096)
        ssh_code = ssh.wait(timeout=600)
        assert saved.returncode == 0 and ssh_code == 0, 'Image stream failed'
        receipt = json.loads(response)
        assert receipt['received'] and receipt['bytes'] == writer.bytes and receipt['sha256'] == writer.hash.hexdigest()
        print(json.dumps({'event': 'minio_image_transfer_complete', 'data': {
            'image': IMAGE, 'receipt': receipt, 'created_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
            'database_write': False, 'business_write': False,
        }}), flush=True)
    finally:
        if saved.poll() is None:
            saved.kill()
        if ssh.poll() is None:
            ssh.kill()


if __name__ == '__main__':
    main()
