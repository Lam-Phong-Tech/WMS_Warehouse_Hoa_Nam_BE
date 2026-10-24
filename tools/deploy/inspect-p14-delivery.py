#!/usr/bin/env python3
"""Read-only transport inspection. Never exports auth, bodies, logs or check tokens."""

import datetime
import hashlib
import json
from pathlib import Path
import re
import shutil
import socket
import stat
import subprocess
from urllib.parse import urlsplit


HOSTS = ('khohoanamdev.lptech.info.vn', 'appqr.lptech.info.vn')
IDS = ('76199893-8402-4b3a-b57d-46a16e591772', '0267280c-e1fe-469b-be54-1bef1120d700',
       'c7c17c5f-a486-48e8-98e8-26709a30510a')
EXPECTED = {
    'docker/nginx/default.conf.template': '12dc7f6a5c7314af3fbfa5b4edd5c82c8bbc3517aeaa120610e0606b0dde6cc3',
    'docker/nginx/perf/default.conf.template': '68713e735b40ddcac599d228b2644be5dc9a5eeb8653ebd34de9ab466d165c51',
}


def command(args):
    result = subprocess.run(args, capture_output=True, text=True, timeout=45)
    if result.returncode:
        raise RuntimeError(args[0] + ' exited ' + str(result.returncode))
    return result.stdout


def describe_config(text):
    chunks = re.split(r'^# configuration file (.+):\s*$', text, flags=re.M)
    results = []
    for index in range(1, len(chunks), 2):
        filename, content = chunks[index], chunks[index + 1]
        hosts = [h for h in HOSTS if h in content]
        global_file = filename.rstrip('/').endswith('/nginx.conf')
        if not hosts and not global_file and '/conf.d/default.conf' not in filename:
            continue
        directives = []
        for line in content.splitlines():
            stripped = line.strip()
            if re.match(r'^(?:gzip(?:_[a-z_]+)?|location|listen|include|fastcgi_pass)\s', stripped):
                directives.append(stripped[:400])
            elif stripped.startswith('proxy_pass '):
                value = stripped[len('proxy_pass '):].rstrip(';')
                parsed = urlsplit(value)
                directives.append(stripped if not parsed.username and not parsed.password and not parsed.query
                                  else 'proxy_pass REDACTED_NON_ROUTING_VALUE;')
            elif re.match(r'^proxy_set_header\s+(?:Accept-Encoding|X-Request-ID)\s', stripped):
                directives.append(stripped[:200])
            elif stripped.startswith('log_format '):
                directives.append('log_format ' + stripped.split()[1] + ' [format omitted];')
        results.append({'file': filename, 'sha256': hashlib.sha256(content.encode()).hexdigest(),
                        'target_hosts': hosts, 'global': global_file, 'routing_compression_directives': directives,
                        'mentions_etag_log_fields': bool(re.search(r'\$(?:upstream_http_etag|sent_http_etag)', content)),
                        'mentions_response_request_id_log_field': '$upstream_http_x_request_id' in content})
    return results


def log_matches(filename):
    if not filename.exists() or not stat.S_ISREG(filename.stat().st_mode):
        return None
    with filename.open('rb') as stream:
        size = filename.stat().st_size
        stream.seek(max(0, size - 8 * 1024 * 1024))
        content = stream.read().decode('utf-8', errors='replace')
    return {'file': str(filename), 'bytes_scanned': min(size, 8 * 1024 * 1024),
            'request_id_matches': {rid: content.count(rid) for rid in IDS},
            'raw_log_exported': False, 'historical_response_headers_not_inferred': True}


def main():
    release = Path('/opt/hoa-nam-green/current').resolve()
    if not release.is_relative_to('/opt/hoa-nam-green/releases'):
        raise RuntimeError('Unexpected release root')
    digests = {name: hashlib.sha256((release / name).read_bytes()).hexdigest() for name in EXPECTED}
    if digests != EXPECTED:
        raise RuntimeError('Inner nginx source drift')
    host_nginx = shutil.which('nginx')
    host_config = describe_config(command([host_nginx, '-T'])) if host_nginx else []
    inner_config = describe_config(command(['docker', 'exec', 'hoa_nam_green-nginx-1', 'nginx', '-T']))
    logs = [Path('/var/log/nginx/access.log'), release / 'storage/logs/laravel.log']
    for row in host_config:
        file = Path(row['file'])
        if file.is_file():
            content = file.read_text(errors='replace')
            for item in re.findall(r'^\s*(?:access_log|error_log)\s+([^;\s]+)', content, flags=re.M):
                path = Path(item)
                if path.is_absolute() and path.resolve().is_relative_to('/var/log/nginx'):
                    logs.append(path)
    result = {
        'observed_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'scope': 'READ_ONLY_NO_CONFIG_RELOAD_NO_AUTHENTICATED_REPLAY',
        'dns': {host: sorted({info[4][0] for info in socket.getaddrinfo(host, 443)}) for host in HOSTS},
        'inner_template_hashes': digests, 'host_nginx_available': bool(host_nginx),
        'host_nginx': host_config, 'inner_nginx': inner_config,
        'log_match_counts_only': [value for path in sorted(set(logs)) if (value := log_matches(path)) is not None],
        'real_request_replayed': False, 'new_login': False, 'live_write': False,
    }
    print(json.dumps({'event': 'p14_delivery_inspection', 'data': result}))


if __name__ == '__main__':
    main()
