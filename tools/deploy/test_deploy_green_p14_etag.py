import importlib.util
from pathlib import Path
import tempfile
import unittest


spec = importlib.util.spec_from_file_location('p14_etag', Path(__file__).with_name('deploy-green-p14-etag.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class P14NginxDeployTest(unittest.TestCase):
    def test_targets_only_green_and_two_templates(self):
        self.assertEqual(release.green.DATABASE, 'wms_hoanam_green')
        self.assertEqual(release.CONTAINER, 'hoa_nam_green-nginx-1')
        self.assertEqual(set(release.FILES), {'docker/nginx/default.conf.template', 'docker/nginx/perf/default.conf.template'})
        self.assertEqual(release.PHP_SHA, 'a9053a21bc35ece367be0b76db727d4749b59855')

    def test_invalid_sha_stops_before_any_package_file_write(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            with self.assertRaisesRegex(RuntimeError, 'Invalid SHA'):
                release.package(folder, 'bad')
            self.assertEqual(list(folder.iterdir()), [])

    def test_tool_has_no_migration_seed_auth_or_real_end_command(self):
        source = Path(release.__file__).read_text()
        for forbidden in ('migrate:fresh', 'db:seed', 'green.artisan(', "'php', 'artisan'", "'/api/v1/auth/login'", "'method': 'POST'", 'pg_restore'):
            self.assertNotIn(forbidden, source)
        self.assertIn("'nginx', '-s', 'reload'", source)
        self.assertIn('target.stat().st_ino == inode', source)
        self.assertIn("'nginx_rollback_only'", source)


if __name__ == '__main__':
    unittest.main()
