import importlib.util
from pathlib import Path
import unittest


spec = importlib.util.spec_from_file_location('gateway', Path(__file__).with_name('fix-p14-tls-gateway.py'))
gateway = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gateway)


SOURCE = '''server {
    server_name khohoanamdev.lptech.info.vn;
    location = /api/v1/auth/me {
        gzip off;
        proxy_set_header Accept-Encoding "";
        proxy_set_header Host $host;
        proxy_pass http://127.0.0.1:18082;
    }
    location / {
        proxy_set_header Host $host;
        proxy_pass http://127.0.0.1:18082;
    }
}
'''


class GatewayTest(unittest.TestCase):
    def test_candidate_is_only_an_additive_dev_route_and_clones_existing_owner_transport(self):
        updated, auth, generic, addition = gateway.candidate(SOURCE.encode())
        self.assertEqual(updated.replace(('\n' + addition).encode(), b'', 1), SOURCE.encode())
        self.assertIn('gzip off;', addition)
        self.assertIn('proxy_set_header Accept-Encoding "";', addition)
        self.assertIn(gateway.UPSTREAM, addition)
        self.assertIn(auth.encode(), updated)
        self.assertIn(generic.encode(), updated)

    def test_wrong_host_or_port_or_missing_owner_guard_cannot_be_applied(self):
        for invalid in (SOURCE.replace(gateway.DOMAIN, 'production.example'),
                        SOURCE.replace('18082', '8080'), SOURCE.replace('gzip off;', 'gzip on;'),
                        SOURCE.replace('proxy_set_header Accept-Encoding "";', ''), SOURCE + SOURCE):
            with self.assertRaises(RuntimeError):
                gateway.candidate(invalid.encode())

    def test_literal_credential_or_existing_p14_is_not_copied_or_overwritten(self):
        bad = SOURCE.replace('gzip off;', 'gzip off;\n        proxy_set_header Authorization private;')
        with self.assertRaises(RuntimeError):
            gateway.candidate(bad.encode())
        updated, _, _, _ = gateway.candidate(SOURCE.encode())
        with self.assertRaises(RuntimeError):
            gateway.candidate(updated)


if __name__ == '__main__':
    unittest.main()
