#!/usr/bin/env python3
"""Real nginx transport regression; only synthetic FastCGI, never an account."""
import json
import os
from pathlib import Path
import re
import runpy
import socket
import subprocess
import tempfile
import time

ROOT = Path(os.environ.get('NGINX_TEMPLATE_ROOT', Path(__file__).resolve().parents[1]))
# Run the unchanged P10/P14 suite first, then reuse its transport fixture.
legacy = runpy.run_path(str(ROOT / 'scripts/test-p10-nginx-etag.py'))
Fixture, request = legacy['Fixture'], legacy['request']
NGINX, CONFIG_DIR = legacy['NGINX'], legacy['CONFIG_DIR']
results = list(legacy['results'])
installation = 'e4ee0820-c600-4036-b7b8-89dbe9f33192'

for variant in ('default', 'perf'):
    fixture = Fixture()
    process = None
    with tempfile.TemporaryDirectory(prefix='fcm-etag-') as directory:
        base = Path(directory)
        base.chmod(0o755)
        public = base / 'public'
        public.mkdir(mode=0o755)
        (public / 'index.php').write_text('synthetic transport only')
        with socket.socket() as reserved:
            reserved.bind(('127.0.0.1', 0))
            port = reserved.getsockname()[1]
        source = (ROOT / ('docker/nginx/default.conf.template' if variant == 'default'
                           else 'docker/nginx/perf/default.conf.template')).read_text(encoding='utf-8')
        replacements = {'${WMS_NGINX_EXTERNAL_PORT}': str(port), '${WMS_CORS_ALLOWED_ORIGINS}': 'http://localhost:5173',
                        '${WMS_NGINX_MAX_BODY}': '2m', '${WMS_PHP_DOCROOT}': public.as_posix(),
                        '${WMS_NGINX_FASTCGI_TIMEOUT}': '5',
                        '${WMS_PHP_UPSTREAM_SERVERS}': f'server 127.0.0.1:{fixture.port};'}
        for key, value in replacements.items():
            source = source.replace(key, value)
        source = source.replace('listen 80;', f'listen 127.0.0.1:{port};')
        source = source.replace('listen 80 backlog=8192;', f'listen 127.0.0.1:{port} backlog=8192;')
        source = source.replace('root /var/www/html/public;', f'root "{public.as_posix()}";')
        source = source.replace('include fastcgi_params;', f'include "{CONFIG_DIR.as_posix()}/fastcgi_params";')
        source = source.replace('set $php_upstream backend:9000;', f'set $php_upstream 127.0.0.1:{fixture.port};')
        assert not re.search(r'\$\{WMS_', source)
        (base / 'server.conf').write_text(source, encoding='utf-8')
        config = base / 'nginx.conf'
        prefix = base.as_posix()
        config.write_text(f'pid "{prefix}/nginx.pid"; error_log "{prefix}/error.log";\n'
                          'events { worker_connections 64; }\n'
                          f'http {{ access_log off; client_body_temp_path "{prefix}/body"; '
                          f'proxy_temp_path "{prefix}/proxy"; fastcgi_temp_path "{prefix}/fastcgi"; '
                          f'uwsgi_temp_path "{prefix}/uwsgi"; scgi_temp_path "{prefix}/scgi"; '
                          f'include "{CONFIG_DIR.as_posix()}/mime.types"; include "{prefix}/server.conf"; }}\n')
        checked = subprocess.run([NGINX, '-p', prefix + '/', '-c', str(config), '-t'], capture_output=True, text=True)
        assert checked.returncode == 0, checked.stderr
        try:
            process = subprocess.Popen([NGINX, '-p', prefix + '/', '-c', str(config), '-g', 'daemon off;'],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            for _ in range(60):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    assert process.poll() is None, (base / 'error.log').read_text()
                    time.sleep(.05)
            path = '/api/v1/notification-delivery/installations/' + installation
            cases = [('GET', path), ('HEAD', path), ('PUT', path), ('DELETE', path),
                     ('GET', path.upper().replace('/API/V1/NOTIFICATION-DELIVERY/INSTALLATIONS/',
                                                 '/api/v1/notification-delivery/installations/')),
                     ('GET', path + '?owner_weak=1'), ('PUT', path + '?owner_error=1')]
            for method, uri in cases:
                for encoding in ('gzip', 'identity'):
                    before = len(fixture.requests)
                    status, headers, body = request(port, method, uri, encoding)
                    assert status == (409 if 'owner_error' in uri else 200), (variant, method, status)
                    expected = 'W/"7"' if 'owner_weak' in uri else '"7"'
                    assert headers.get('etag') == expected, (variant, method, uri, headers)
                    assert not headers.get('content-encoding'), (variant, method, headers)
                    assert headers.get('cache-control') == 'private, no-store, no-transform', headers
                    assert headers.get('x-content-type-options') == 'nosniff'
                    assert 'Authorization' in headers.get('vary', '') and 'x-powered-by' not in headers
                    assert len(fixture.requests) == before + 1, 'Owner request must never be duplicated'
                    if method == 'HEAD':
                        assert body == b''
                    else:
                        payload = json.loads(body)
                        assert payload['method'] == method and payload['uri'] == uri
                        assert payload['script'] == (public / 'index.php').as_posix()
                        assert payload['if_match'] == '"7"'
                        assert payload['request_id'] == '11111111-1111-4111-8111-111111111112'
                        assert payload['forwarded_proto'] == 'https' and payload['accept_encoding'] in ('', None)
                    results.append({'variant': variant, 'case': 'FCM_' + method + '_' + encoding,
                                    'uri': uri, 'pass': True})
            for uri in ('/api/v1/notification-delivery/capabilities',
                        '/api/v1/notification-delivery/installations/not-a-uuid', path + '/extra'):
                status, headers, body = request(port, 'GET', uri)
                assert status == 200 and headers.get('etag') == 'W/"7"'
                assert headers.get('content-encoding') == 'gzip', (variant, uri, headers)
                results.append({'variant': variant, 'case': 'unrelated_compression_unchanged', 'uri': uri, 'pass': True})
            assert not fixture.errors, fixture.errors
        finally:
            if process is not None:
                subprocess.run([NGINX, '-p', prefix + '/', '-c', str(config), '-s', 'quit'],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                process.wait(timeout=5)
            fixture.close()

report = {'count': len(results), 'transport_cases': results, 'live_verified': False,
          'scope': 'DEFAULT_AND_PERF_NGINX_SYNTHETIC_FCGI_P10_P14_FCM_NO_REAL_ACCOUNT'}
print(json.dumps(report, sort_keys=True))
if os.environ.get('FCM_NGINX_TEST_REPORT'):
    Path(os.environ['FCM_NGINX_TEST_REPORT']).write_text(json.dumps(report, indent=2))
