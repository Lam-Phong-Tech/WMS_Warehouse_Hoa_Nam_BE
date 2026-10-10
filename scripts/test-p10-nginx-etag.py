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

ROOT = Path.cwd()
NGINX = shutil.which('nginx')
if not NGINX:
    raise SystemExit('nginx is required; transport test must not be skipped')


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
        threading.Thread(target=self.loop, daemon=True).start()

    def loop(self):
        while not self.stop:
            try:
                conn, _ = self.sock.accept()
            except OSError:
                break
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
                    body = json.dumps({'padding': 'isolated-transport-' * 200,
                        'method': env.get('REQUEST_METHOD'), 'uri': env.get('REQUEST_URI'),
                        'script': env.get('SCRIPT_FILENAME'), 'if_match': env.get('HTTP_IF_MATCH'),
                        'origin': env.get('HTTP_ORIGIN'), 'forwarded_proto': env.get('HTTP_X_FORWARDED_PROTO')}).encode()
                    head = ('Status: 200 OK\r\nContent-Type: application/json\r\nETag: "7"\r\n'
                            'Cache-Control: private, no-store, no-transform\r\n'
                            'X-Powered-By: synthetic-fixture\r\nContent-Length: ' + str(len(body)) + '\r\n\r\n').encode()
                    send(conn, 6, req, head + (b'' if env.get('REQUEST_METHOD') == 'HEAD' else body))
                    send(conn, 6, req, b'')
                    send(conn, 3, req, b'\0' * 8)
            except Exception as error:
                if not self.stop:
                    self.errors.append(type(error).__name__ + ': ' + str(error))

    def close(self):
        self.stop = True
        self.sock.close()


def request(port, method, path, encoding='gzip'):
    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    headers = {'Accept-Encoding': encoding, 'If-Match': '"7"',
        'Origin': 'http://localhost:5173', 'X-Forwarded-Proto': 'https'}
    conn.request(method, path, body='{}' if method == 'PATCH' else None, headers=headers)
    response = conn.getresponse()
    body = response.read()
    result = response.status, dict((k.lower(), v) for k, v in response.getheaders()), body
    conn.close()
    return result


results = []
for variant in ['default', 'perf']:
    fixture = Fixture()
    process = None
    with tempfile.TemporaryDirectory(prefix='p10-nginx-') as directory:
        base = Path(directory)
        base.chmod(0o755)
        public = base / 'public'; public.mkdir(mode=0o755)
        (public / 'index.php').write_text('synthetic transport target, not PHP application code')
        with socket.socket() as reserve:
            reserve.bind(('127.0.0.1', 0)); port = reserve.getsockname()[1]
        path = ROOT / ('docker/nginx/default.conf.template' if variant == 'default' else 'docker/nginx/perf/default.conf.template')
        source = path.read_text()
        replacements = {'${WMS_NGINX_EXTERNAL_PORT}': str(port), '${WMS_CORS_ALLOWED_ORIGINS}': 'http://localhost:5173',
            '${WMS_NGINX_MAX_BODY}': '2m', '${WMS_PHP_DOCROOT}': str(public),
            '${WMS_NGINX_FASTCGI_TIMEOUT}': '5', '${WMS_PHP_UPSTREAM_SERVERS}': f'server 127.0.0.1:{fixture.port};'}
        for key, value in replacements.items():
            source = source.replace(key, value)
        source = source.replace('listen 80;', f'listen 127.0.0.1:{port};')
        source = source.replace('listen 80 backlog=8192;', f'listen 127.0.0.1:{port} backlog=8192;')
        source = source.replace('root /var/www/html/public;', f'root {public};')
        source = source.replace('include fastcgi_params;', 'include /etc/nginx/fastcgi_params;')
        source = source.replace('set $php_upstream backend:9000;', f'set $php_upstream 127.0.0.1:{fixture.port};')
        assert not re.search(r'\$\{WMS_', source), 'Unsubstituted template environment'
        (base / 'server.conf').write_text(source)
        config = base / 'nginx.conf'
        config.write_text(f'pid {base}/nginx.pid; error_log {base}/error.log;\nevents {{ worker_connections 64; }}\nhttp {{ access_log off; client_body_temp_path {base}/body; proxy_temp_path {base}/proxy; fastcgi_temp_path {base}/fastcgi; uwsgi_temp_path {base}/uwsgi; scgi_temp_path {base}/scgi; include /etc/nginx/mime.types; include {base}/server.conf; }}\n')
        checked = subprocess.run([NGINX, '-p', directory, '-c', str(config), '-t'], capture_output=True, text=True)
        if checked.returncode:
            raise AssertionError(checked.stderr)
        process = subprocess.Popen([NGINX, '-p', directory, '-c', str(config), '-g', 'daemon off;'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
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
                    assert payload['script'] == str(public / 'index.php')
                    assert payload['if_match'] == '"7"'
                    assert payload['origin'] == 'http://localhost:5173' and payload['forwarded_proto'] == 'https'
                results.append({'variant': variant, 'case': method + '_' + encoding, 'pass': True})
            status, head, body = request(port, 'GET', '/api/v1/auth/me?unexpected=1')
            assert status == 200 and head.get('etag') == '"7"'
            assert json.loads(body)['uri'] == '/api/v1/auth/me?unexpected=1'
            results.append({'variant': variant, 'case': 'query_preserved_for_application_validation', 'pass': True})
            assert fixture.errors == [], fixture.errors
        finally:
            if process:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill(); process.wait(timeout=5)
            fixture.close()

report = {'transport_cases': results, 'count': len(results), 'live_verified': False,
    'scope': 'CHECKED_IN_NGINX_TEMPLATES_WITH_SYNTHETIC_FASTCGI_NOT_AUTHENTICATED_UAT'}
print(json.dumps(report, sort_keys=True))
if os.environ.get('GITHUB_STEP_SUMMARY'):
    with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as output:
        output.write('```json\n' + json.dumps(report, indent=2) + '\n```\n')
