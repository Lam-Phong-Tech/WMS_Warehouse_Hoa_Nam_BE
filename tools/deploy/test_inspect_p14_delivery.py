import importlib.util
from pathlib import Path
import tempfile
import unittest


spec = importlib.util.spec_from_file_location('inspection', Path(__file__).with_name('inspect-p14-delivery.py'))
inspection = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inspection)


class InspectionTest(unittest.TestCase):
    def test_scope_check_supports_the_older_host_runtime_and_rejects_prefix_siblings(self):
        self.assertTrue(inspection.within(Path('/opt/green/releases/one'), '/opt/green/releases'))
        self.assertFalse(inspection.within(Path('/opt/green/releases-other/one'), '/opt/green/releases'))

    def test_routing_summary_never_exports_auth_or_url_credentials(self):
        text = '''# configuration file /etc/nginx/sites-enabled/dev:
server_name khohoanamdev.lptech.info.vn;
gzip on;
proxy_pass https://user:private@backend.example/?secret=value;
proxy_set_header Authorization private-bearer;
proxy_set_header Accept-Encoding identity;
'''
        result = inspection.describe_config(text)
        self.assertEqual(len(result), 1)
        self.assertIn('gzip on;', result[0]['routing_compression_directives'])
        rendered = str(result)
        self.assertNotIn('private-bearer', rendered)
        self.assertNotIn('user:private', rendered)
        self.assertNotIn('secret=value', rendered)
        self.assertIn('REDACTED_NON_ROUTING_VALUE', rendered)

    def test_non_target_sites_are_not_exported(self):
        text = '# configuration file /etc/nginx/sites-enabled/unrelated:\nserver_name unrelated.example;\ngzip on;\n'
        self.assertEqual(inspection.describe_config(text), [])

    def test_logs_export_counts_not_the_matched_line(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'access.log'
            path.write_text('private-body ' + inspection.IDS[0] + ' secret-check-token', encoding='utf-8')
            result = inspection.log_matches(path)
        self.assertEqual(result['request_id_matches'][inspection.IDS[0]], 1)
        self.assertFalse(result['raw_log_exported'])
        self.assertNotIn('private-body', str(result))
        self.assertNotIn('secret-check-token', str(result))


if __name__ == '__main__':
    unittest.main()
