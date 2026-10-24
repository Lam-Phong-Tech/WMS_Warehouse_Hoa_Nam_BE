#!/usr/bin/env python3
"""Scoped DEV TLS gzip fix with real host-nginx loopback reproduction. Never END/login."""

import argparse
import datetime
import gzip
import hashlib
import http.client
import http.server
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import threading
import time


DOMAIN = 'khohoanamdev.lptech.info.vn'
UPSTREAM = 'proxy_pass http://127.0.0.1:18082;'
PATTERN = '^/api/v1/mini-app/work-shifts/[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}/(?:close-check|end)$'


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def location_blocks(source, signature):
    result = []
    for match in re.finditer(r'(?m)^(?P<indent>[ \t]*)' + re.escape(signature) + r'[ \t]*\{[ \t]*\n', source):
        close = re.search(r'(?m)^' + re.escape(match['indent']) + r'\}[ \t]*(?:\n|$)', source[match.end():])
        require(close is not None, 'Unclosed source location')
        end = match.end() + close.end()
        body = source[match.end():match.end() + close.start()]
        require('{' not in body and '}' not in body, 'Nested/dynamic location not supported')
        result.append({'start': match.start(), 'end': end, 'body': body, 'indent': match['indent'],
                       'source': source[match.start():end]})
    return result


def candidate(original):
    source = original.decode('utf-8')
    names = re.findall(r'(?m)^\s*server_name\s+([^;]+);', source)
    require(names and all(set(name.split()) == {DOMAIN} for name in names), 'Non-DEV hostname in target file')
    require('/mini-app/work-shifts/' not in source, 'P14 gateway rule already present; review instead of overwrite')
    auth = location_blocks(source, 'location = /api/v1/auth/me')
    generic = [b for b in location_blocks(source, 'location /') if UPSTREAM in b['body']]
    require(len(auth) == 1 and len(generic) == 1, 'Ambiguous owner routing')
    auth = auth[0]
    require(auth['body'].count(UPSTREAM) == 1 and 'gzip off;' in auth['body']
            and 'proxy_set_header Accept-Encoding "";' in auth['body'], 'Existing profile protection differs')
    require(source.count(UPSTREAM) == 2 and not re.search(r'\b(?:rewrite|return|proxy_cache|proxy_store)\b', auth['body']),
            'Unexpected owner proxy semantics')
    require(not re.search(r'proxy_set_header\s+Authorization\s+(?!\$http_authorization\s*;)', auth['body']),
            'Literal or nonstandard upstream credential prohibited')
    addition = (auth['indent'] + '# P14 owner validators must not be recompressed at the public TLS hop.\n'
                + auth['indent'] + 'location ~ "' + PATTERN + '" {\n' + auth['body'] + auth['indent'] + '}\n')
    updated = source[:auth['end']] + '\n' + addition + source[auth['end']:]
    require(updated.replace('\n' + addition, '', 1) == source, 'Non-additive gateway edit')
    return updated.encode('utf-8'), auth['source'], generic[0]['source'], addition


def loopback_test(nginx, auth, generic, addition, global_config):
    requests = []

    class Upstream(http.server.BaseHTTPRequestHandler):
        protocol_version = 'HTTP/1.1'

        def serve(self):
            if self.command == 'POST':
                self.rfile.read(int(self.headers.get('Content-Length', '0')))
            requests.append((self.command, self.path))
            body = json.dumps({'padding': 'synthetic-only-' * 400, 'uri': self.path, 'method': self.command,
                               'if_match': self.headers.get('If-Match'), 'request_id': self.headers.get('X-Request-ID')}).encode()
            self.send_response(409 if 'owner_error=1' in self.path else 200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.send_header('ETag', 'W/"7"' if 'owner_weak=1' in self.path else '"7"')
            self.send_header('Cache-Control', 'private, no-store, no-transform')
            self.send_header('Vary', 'Authorization, Origin')
            self.end_headers()
            if self.command != 'HEAD':
                self.wfile.write(body)

        do_GET = serve
        do_HEAD = serve
        do_POST = serve

        def log_message(self, *args):
            pass

    compression = re.findall(r'(?m)^\s*gzip(?:_[a-z_]+)?\s+[^;]+;', global_config)
    require(any('gzip on;' in d for d in compression) and any('application/json' in d for d in compression),
            'Inspected global JSON gzip rule changed')
    upstream = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Upstream)
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    upstream_url = 'proxy_pass http://127.0.0.1:' + str(upstream.server_port) + ';'
    results = []
    shift = 'aabbccdd-1234-4234-8234-aabbccddeeff'
    cases = [('GET', '/api/v1/auth/me'), ('GET', '/api/v1/mini-app/work-shifts/' + shift + '/close-check?warehouse_id=fixture'),
             ('HEAD', '/api/v1/mini-app/work-shifts/' + shift + '/close-check'),
             ('POST', '/api/v1/mini-app/work-shifts/' + shift + '/end'), ('GET', '/api/v1/transport-control'),
             ('GET', '/api/v1/mini-app/work-shifts/not-a-uuid/close-check'),
             ('GET', '/api/v1/mini-app/work-shifts/' + shift + '/close-check?owner_weak=1'),
             ('POST', '/api/v1/mini-app/work-shifts/' + shift + '/end?owner_error=1')]
    try:
        for variant in ('baseline', 'candidate'):
            with tempfile.TemporaryDirectory(prefix='p14-tls-loopback-', dir=os.environ.get('PUBLIC') if os.name == 'nt' else None) as directory:
                base = Path(directory)
                base.chmod(0o755)
                (base / 'logs').mkdir(mode=0o755)
                prefix = base.as_posix()
                with socket.socket() as reserve:
                    reserve.bind(('127.0.0.1', 0))
                    port = reserve.getsockname()[1]
                body = auth + '\n' + (addition if variant == 'candidate' else '') + '\n' + generic
                body = body.replace(UPSTREAM, upstream_url)
                config = base / 'nginx.conf'
                config.write_text('pid "' + prefix + '/nginx.pid"; error_log "' + prefix + '/error.log";\n'
                                  'events {worker_connections 64;}\nhttp { access_log off;\n'
                                  + ''.join(name + '_temp_path "' + prefix + '/' + name + '";\n'
                                            for name in ('client_body', 'proxy', 'fastcgi', 'uwsgi', 'scgi'))
                                  + '\n'.join(compression) + '\nserver { listen 127.0.0.1:' + str(port)
                                  + '; server_name _;\n' + body + '\n}}\n', encoding='utf-8')
                flags = {'creationflags': subprocess.CREATE_NO_WINDOW} if os.name == 'nt' else {}
                tested = subprocess.run([nginx, '-p', prefix + '/', '-c', config.as_posix(), '-t'], capture_output=True, **flags)
                require(tested.returncode == 0, 'Isolated gateway syntax failed: ' + tested.stderr.decode(errors='replace')[-500:])
                process = subprocess.Popen([nginx, '-p', prefix + '/', '-c', config.as_posix(), '-g', 'daemon off;'],
                                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, **flags)
                try:
                    for _ in range(50):
                        try:
                            with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                                break
                        except OSError:
                            time.sleep(0.05)
                    for method, uri in cases:
                        for encoding in ('gzip', 'identity', 'gzip, deflate, br, zstd'):
                            before = len(requests)
                            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=6)
                            connection.request(method, uri, body='{}' if method == 'POST' else None,
                                               headers={'Accept-Encoding': encoding, 'If-Match': '"7"',
                                                        'X-Request-ID': '11111111-1111-4111-8111-111111111111'})
                            response = connection.getresponse()
                            payload = response.read()
                            headers = {k.lower(): v for k, v in response.getheaders()}
                            connection.close()
                            is_p14 = re.match(PATTERN, uri.split('?')[0]) is not None
                            compressed = (encoding != 'identity' and uri != '/api/v1/auth/me' and 'owner_error' not in uri
                                          and not (variant == 'candidate' and is_p14))
                            etag = 'W/"7"' if compressed or 'owner_weak' in uri else '"7"'
                            require(response.status == (409 if 'owner_error' in uri else 200)
                                    and headers.get('etag') == etag
                                    and (headers.get('content-encoding') == 'gzip') == compressed,
                                    'Gateway transport mismatch: ' + json.dumps({'variant': variant, 'method': method,
                                        'uri': uri, 'encoding': encoding, 'status': response.status,
                                        'etag': headers.get('etag'), 'content_encoding': headers.get('content-encoding')}))
                            require(len(requests) == before + 1, 'Synthetic command replayed')
                            if method == 'HEAD':
                                require(not payload, 'HEAD contained body')
                            else:
                                value = json.loads(gzip.decompress(payload) if compressed else payload)
                                require(value['uri'] == uri and value['method'] == method and value['if_match'] == '"7"'
                                        and value['request_id'] == '11111111-1111-4111-8111-111111111111',
                                        'Gateway request identity/query changed')
                            results.append({'variant': variant, 'method': method, 'uri': uri, 'accept_encoding': encoding,
                                            'status': response.status, 'etag': headers.get('etag'),
                                            'content_encoding': headers.get('content-encoding'), 'pass': True})
                finally:
                    subprocess.run([nginx, '-p', prefix + '/', '-c', config.as_posix(), '-s', 'quit'],
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, **flags)
                    try:
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        if os.name == 'nt':
                            subprocess.run(['taskkill', '/PID', str(process.pid), '/T', '/F'],
                                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, **flags)
                        else:
                            process.kill()
                        process.wait(timeout=5)
    finally:
        upstream.shutdown()
        upstream.server_close()
    return {'count': len(results), 'cases': results, 'real_business_requests': 0, 'authenticated_live': False}


def main(inspect_only):
    spec = importlib.util.spec_from_file_location('p14', Path(__file__).with_name('deploy-green-p14-etag.py'))
    p14 = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(p14)
    nginx = shutil.which('nginx')
    require(nginx is not None, 'Host nginx unavailable')
    path = Path('/etc/nginx/sites-enabled/' + DOMAIN).resolve()
    require(path.parent == Path('/etc/nginx/sites-available') and path.name == DOMAIN, 'Unexpected vhost path')
    original = path.read_bytes()
    updated, auth, generic, addition = candidate(original)
    before = p14.app_release.configuration()
    require(before['sha'] == p14.PHP_SHA and before['database'] == p14.green.DATABASE
            and before['protected'] and not before['allow_seed'] and not before['p11'] and not before['p19'], 'PHP policy drift')
    counts = p14.app_release.business_counts()
    schema = p14.green.pg('SELECT count(*) FROM migrations')
    tests = loopback_test(nginx, auth, generic, addition, Path('/etc/nginx/nginx.conf').read_text())
    require(tests['count'] == 48 and all(t['pass'] for t in tests['cases']), 'Host transport verification failed')
    version = subprocess.run([nginx, '-v'], capture_output=True, text=True, check=True).stderr.strip()
    report = {'scope': 'DEV_TLS_GATEWAY_ONLY_AUTHENTICATED_UAT_PENDING', 'nginx_version': version,
              'target': str(path), 'source_before_sha256': digest(original), 'source_after_sha256': digest(updated),
              'php_sha_unchanged': p14.PHP_SHA, 'loopback': tests, 'policy_before': before,
              'counts_before': counts, 'migration_count': schema, 'real_end_command': False,
              'new_login': False, 'seed_migration_repair_restore': False, 'ci_run': False, 'stage': 'tested'}
    p14.green.emit('p14_tls_gateway_tested', report)
    if inspect_only:
        return
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = Path('/opt/hoa-nam-green/backups') / ('p14-tls-' + stamp)
    backup.mkdir(mode=0o700)
    (backup / 'original.conf').write_bytes(original)
    (backup / 'candidate.conf').write_bytes(updated)
    temporary = path.with_name(path.name + '.p14-' + stamp + '.tmp')
    require(not temporary.exists() and path.read_bytes() == original, 'Concurrent vhost edit')
    changed = False
    try:
        info = path.stat()
        with temporary.open('xb') as stream:
            stream.write(updated)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, info.st_mode & 0o777)
        os.chown(temporary, info.st_uid, info.st_gid)
        os.replace(temporary, path)
        changed = True
        p14.green.run([nginx, '-t'])
        p14.green.run(['systemctl', 'reload', 'nginx'])
        after = p14.app_release.configuration()
        require(after == before and p14.green.pg('SELECT count(*) FROM migrations') == schema, 'PHP/schema changed')
        report.update({'stage': 'complete', 'policy_after': after, 'counts_after': p14.app_release.business_counts(),
                       'public_http': p14.probe(p14.green.ORIGIN) + p14.probe('https://appqr.lptech.info.vn/wms-api'),
                       'completed_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                       'backup_directory': str(backup), 'authenticated_200_verified': False,
                       'appqr_server_changed': False, 'production_config_changed': False})
        p14.green.emit('p14_tls_gateway_deployed', report)
    except Exception:
        if changed and path.read_bytes() == updated:
            path.write_bytes(original)
            p14.green.run([nginx, '-t'])
            p14.green.run(['systemctl', 'reload', 'nginx'])
            report['vhost_rollback_only'] = True
        raise
    finally:
        (backup / 'report.json').write_text(json.dumps(report, indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--inspect', action='store_true')
    args = parser.parse_args()
    main(args.inspect)
