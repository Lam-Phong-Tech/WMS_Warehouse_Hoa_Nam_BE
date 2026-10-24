#!/usr/bin/env python3
"""Real local nginx + synthetic FastCGI transport; no live app or account."""
import gzip
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import socket
import struct
import subprocess
import tempfile
import threading
import time

ROOT = Path(os.environ.get('NGINX_TEMPLATE_ROOT', Path.cwd()))
NGINX = os.environ.get('NGINX_BINARY') or shutil.which('nginx')
if not NGINX:
    raise SystemExit('nginx is required; transport test must not be skipped')
CONFIG_DIR = Path(NGINX).parent / 'conf' if os.name == 'nt' else Path('/etc/nginx')
POPEN_FLAGS = {'creationflags': subprocess.CREATE_NO_WINDOW} if os.name == 'nt' else {}


def take(conn, size):
    result = b''
    while len(result) < size:
        part = conn.recv(size - len(result))
        if not part:
            raise EOFError('short FastCGI request')
        result += part
    return result


def params(data):
    result = {}
    pos = 0
    def length():
        nonlocal pos
        value = data[pos]
        if value & 128:
            value = struct.unpack('!I', data[pos:pos + 4])[0] & 0x7fffffff
            pos += 4
        else:
            pos += 1
        return value
    while pos < len(data):
        name_size, value_size = length(), length()
        name = data[pos:pos + name_size].decode(); pos += name_size
        value = data[pos:pos + value_size].decode(); pos += value_size
        result[name] = value
    return result


def send(conn, kind, req, data):
    conn.sendall(struct.pack('!BBHHBB', 1, kind, req, len(data), 0, 0) + data)


class Fixture:
    def __init__(self):
        self.sock = socket.socket()
        self.sock.bind(('127.0.0.1', 0))
        self.sock.listen()
        self.port = self.sock.getsockname()[1]
        self.stop = False
        self.errors = []
        self.requests = []
        threading.Thread(target=self.loop, daemon=True).start()

    def loop(self):
        while not self.stop:
            try:
                conn, _ = self.sock.accept()
            except OSError:
                break
            reply_sent = False
            try:
                with conn:
                    conn.settimeout(5)
                    raw = b''
                    while True:
                        version, kind, req, size, padding, _ = struct.unpack('!BBHHBB', take(conn, 8))
                        assert version == 1
                        content = take(conn, size)
                        if padding:
                            take(conn, padding)
                        if kind == 4:
                            raw += content
                        if kind == 5 and size == 0:
                            break
                    env = params(raw)
                    self.requests.append(env)
                    body = json.dumps({'padding': 'isolated-transport-' * 200,
                        'method': env.get('REQUEST_METHOD'), 'uri': env.get('REQUEST_URI'),
                        'script': env.get('SCRIPT_FILENAME'), 'if_match': env.get('HTTP_IF_MATCH'),
                        'accept_encoding': env.get('HTTP_ACCEPT_ENCODING'), 'request_id': env.get('HTTP_X_REQUEST_ID'),
                        'origin': env.get('HTTP_ORIGIN'), 'forwarded_proto': env.get('HTTP_X_FORWARDED_PROTO')}).encode()
                    owner_etag = 'W/"7"' if 'owner_weak=1' in env.get('REQUEST_URI', '') else '"7"'
                    status = '409 Conflict' if 'owner_error=1' in env.get('REQUEST_URI', '') else '200 OK'
                    cache = 'private, no-store' if '/work-shifts/' in env.get('REQUEST_URI', '') else 'private, no-store, no-transform'
                    head = ('Status: ' + status + '\r\nContent-Type: application/json\r\nETag: ' + owner_etag + '\r\n'
                            'Cache-Control: ' + cache + '\r\n'
                            'Vary: Authorization, Origin\r\nX-Content-Type-Options: nosniff\r\n'
                            'X-Powered-By: synthetic-fixture\r\nContent-Length: ' + str(len(body)) + '\r\n\r\n').encode()
                    send(conn, 6, req, head + (b'' if env.get('REQUEST_METHOD') == 'HEAD' else body))
                    reply_sent = True
                    send(conn, 6, req, b'')
                    send(conn, 3, req, b'\0' * 8)
            except Exception as error:
                # Windows nginx may close after Content-Length/HEAD, before the
                # fixture's terminal FastCGI records. Body/headers are checked by HTTP.
                if not self.stop and not (reply_sent and isinstance(error, (ConnectionResetError, BrokenPipeError))):
                    self.errors.append(type(error).__name__ + ': ' + str(error))

    def close(self):
        self.stop = True
        self.sock.close()


def request(port, method, path, encoding='gzip'):
    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    headers = {'Accept-Encoding': encoding, 'If-Match': '"7"',
        'Origin': 'http://localhost:5173', 'X-Forwarded-Proto': 'https',
        'X-Request-ID': '11111111-1111-4111-8111-111111111112'}
    conn.request(method, path, body='{}' if method in ('PATCH', 'POST') else None, headers=headers)
    response = conn.getresponse()
    body = response.read()
    result = response.status, dict((k.lower(), v) for k, v in response.getheaders()), body
    conn.close()
    return result


results = []
for variant in ['default', 'perf']:
    fixture = Fixture()
    process = None
    temp_parent = os.environ.get('NGINX_TEST_TMP') or (os.environ.get('PUBLIC') if os.name == 'nt' else None)
    with tempfile.TemporaryDirectory(prefix='owner-nginx-', dir=temp_parent) as directory:
        base = Path(directory)
        prefix = base.as_posix()
        base.chmod(0o755)
        public = base / 'public'; public.mkdir(mode=0o755)
        (public / 'index.php').write_text('synthetic transport target, not PHP application code')
        with socket.socket() as reserve:
            reserve.bind(('127.0.0.1', 0)); port = reserve.getsockname()[1]
        path = ROOT / ('docker/nginx/default.conf.template' if variant == 'default' else 'docker/nginx/perf/default.conf.template')
        source = path.read_text(encoding='utf-8')
        replacements = {'${WMS_NGINX_EXTERNAL_PORT}': str(port), '${WMS_CORS_ALLOWED_ORIGINS}': 'http://localhost:5173',
            '${WMS_NGINX_MAX_BODY}': '2m', '${WMS_PHP_DOCROOT}': public.as_posix(),
            '${WMS_NGINX_FASTCGI_TIMEOUT}': '5', '${WMS_PHP_UPSTREAM_SERVERS}': f'server 127.0.0.1:{fixture.port};'}
        for key, value in replacements.items():
            source = source.replace(key, value)
        source = source.replace('listen 80;', f'listen 127.0.0.1:{port};')
        source = source.replace('listen 80 backlog=8192;', f'listen 127.0.0.1:{port} backlog=8192;')
        source = source.replace('root /var/www/html/public;', f'root "{public.as_posix()}";')
        source = source.replace('include fastcgi_params;', f'include "{CONFIG_DIR.as_posix()}/fastcgi_params";')
        source = source.replace('set $php_upstream backend:9000;', f'set $php_upstream 127.0.0.1:{fixture.port};')
        assert not re.search(r'\$\{WMS_', source), 'Unsubstituted template environment'
        (base / 'server.conf').write_text(source, encoding='utf-8')
        config = base / 'nginx.conf'
        config.write_text(f'pid "{prefix}/nginx.pid"; error_log "{prefix}/error.log";\nevents {{ worker_connections 64; }}\nhttp {{ access_log off; client_body_temp_path "{prefix}/body"; proxy_temp_path "{prefix}/proxy"; fastcgi_temp_path "{prefix}/fastcgi"; uwsgi_temp_path "{prefix}/uwsgi"; scgi_temp_path "{prefix}/scgi"; include "{CONFIG_DIR.as_posix()}/mime.types"; include "{prefix}/server.conf"; }}\n')
        checked = subprocess.run([NGINX, '-p', prefix + '/', '-c', config.as_posix(), '-t'], capture_output=True, text=True, **POPEN_FLAGS)
        if checked.returncode:
            raise AssertionError(checked.stderr)
        process = subprocess.Popen([NGINX, '-p', prefix + '/', '-c', config.as_posix(), '-g', 'daemon off;'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, **POPEN_FLAGS)
        try:
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                        break
                except OSError:
                    if process.poll() is not None:
                        raise AssertionError((base / 'error.log').read_text())
                    time.sleep(0.05)
            status, head, body = request(port, 'GET', '/api/v1/transport-control')
            assert status == 200 and head.get('content-encoding') == 'gzip', (variant, status, head)
            assert head.get('etag') == 'W/"7"', (variant, head)
            assert json.loads(gzip.decompress(body))['uri'] == '/api/v1/transport-control'
            results.append({'variant': variant, 'case': 'unrelated_json_remains_compressed', 'pass': True})
            for method, encoding in [('GET', 'gzip'), ('PATCH', 'gzip'), ('HEAD', 'gzip'), ('GET', 'identity')]:
                status, head, body = request(port, method, '/api/v1/auth/me', encoding)
                assert status == 200, (variant, status)
                assert head.get('etag') == '"7"', (variant, method, head.get('etag'))
                assert not head.get('content-encoding'), (variant, method, head)
                assert 'x-powered-by' not in head
                assert 'no-transform' in head.get('cache-control', '')
                if method == 'HEAD':
                    assert body == b''
                else:
                    payload = json.loads(body)
                    assert payload['method'] == method and payload['uri'] == '/api/v1/auth/me'
                    assert payload['script'] == (public / 'index.php').as_posix()
                    assert payload['if_match'] == '"7"'
                    assert payload['origin'] == 'http://localhost:5173' and payload['forwarded_proto'] == 'https'
                results.append({'variant': variant, 'case': method + '_' + encoding, 'pass': True})
            status, head, body = request(port, 'GET', '/api/v1/auth/me?unexpected=1')
            assert status == 200 and head.get('etag') == '"7"'
            assert json.loads(body)['uri'] == '/api/v1/auth/me?unexpected=1'
            results.append({'variant': variant, 'case': 'query_preserved_for_application_validation', 'pass': True})
            shift = 'aabbccdd-1234-4234-8234-aabbccddeeff'
            cases = [('GET', f'/api/v1/mini-app/work-shifts/{shift}/close-check?warehouse_id=unit'),
                     ('HEAD', f'/api/v1/mini-app/work-shifts/{shift}/close-check'),
                     ('POST', f'/api/v1/mini-app/work-shifts/{shift}/end'),
                     ('GET', f'/api/v1/mini-app/work-shifts/{shift.upper()}/close-check'),
                     ('GET', f'/api/v1/mini-app/work-shifts/{shift}/close-check?owner_weak=1'),
                     ('POST', f'/api/v1/mini-app/work-shifts/{shift}/end?owner_error=1')]
            for method, uri in cases:
                for encoding in ('gzip', 'identity'):
                    before = len(fixture.requests)
                    status, head, body = request(port, method, uri, encoding)
                    assert status == (409 if 'owner_error' in uri else 200), (variant, method, uri, status)
                    expected = 'W/"7"' if 'owner_weak' in uri else '"7"'
                    assert head.get('etag') == expected and not head.get('content-encoding'), (variant, uri, head)
                    assert 'x-powered-by' not in head and 'no-store' in head.get('cache-control', '')
                    assert head.get('cache-control') == 'private, no-store, no-transform', head
                    assert 'Authorization' in head.get('vary', '') and head.get('x-content-type-options') == 'nosniff'
                    assert len(fixture.requests) == before + 1, 'Synthetic command must not be replayed'
                    if method == 'HEAD':
                        assert not body
                    else:
                        payload = json.loads(body)
                        assert payload['uri'] == uri and payload['method'] == method
                        assert payload['script'] == (public / 'index.php').as_posix()
                        assert payload['if_match'] == '"7"' and payload['request_id'] == '11111111-1111-4111-8111-111111111112'
                        assert payload['accept_encoding'] in ('', None) and payload['forwarded_proto'] == 'https', payload
                    results.append({'variant': variant, 'case': 'P14_' + method + '_' + encoding, 'uri': uri, 'pass': True})
            for uri in ('/api/v1/mini-app/work-shifts/not-a-uuid/close-check',
                        f'/api/v1/mini-app/work-shifts/{shift}/summary',
                        f'/api/v1/mini-app/work-shifts/{shift}/close-check/extra'):
                status, head, body = request(port, 'GET', uri)
                assert status == 200 and head.get('etag') == 'W/"7"' and head.get('content-encoding') == 'gzip', (variant, uri, head)
                assert json.loads(gzip.decompress(body))['uri'] == uri
                results.append({'variant': variant, 'case': 'non_p14_validator_route_compression_unchanged', 'uri': uri, 'pass': True})
            assert fixture.errors == [], fixture.errors
        finally:
            if process:
                subprocess.run([NGINX, '-p', prefix + '/', '-c', config.as_posix(), '-s', 'quit'],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, **POPEN_FLAGS)
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    if os.name == 'nt':
                        subprocess.run(['taskkill', '/PID', str(process.pid), '/T', '/F'],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, **POPEN_FLAGS)
                    else:
                        process.kill()
                    process.wait(timeout=5)
            fixture.close()

report = {'transport_cases': results, 'count': len(results), 'live_verified': False,
    'scope': 'CHECKED_IN_NGINX_TEMPLATES_WITH_SYNTHETIC_FASTCGI_NOT_AUTHENTICATED_UAT'}
print(json.dumps(report, sort_keys=True))
if os.environ.get('NGINX_TEST_REPORT'):
    Path(os.environ['NGINX_TEST_REPORT']).write_text(json.dumps(report, indent=2), encoding='utf-8')
if os.environ.get('GITHUB_STEP_SUMMARY'):
    with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as output:
        output.write('```json\n' + json.dumps(report, indent=2) + '\n```\n')
