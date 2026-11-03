import copy
import importlib.util
import io
from pathlib import Path
import tarfile
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('deploy_p18', Path(__file__).with_name('deploy-devtest-p18-location.py'))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class DeployScopeTest(unittest.TestCase):
    def config(self):
        source = str(deploy.ROOT / 'releases' / deploy.BASE)
        return {'services': {name: {'volumes': [
            {'type': 'bind', 'source': source, 'target': '/var/www/html', 'read_only': True},
            {'type': 'bind', 'source': '/original/nginx.conf', 'target': '/etc/nginx/nginx.conf', 'read_only': True}],
            'environment': {'AUTO_MIGRATE': 'false', 'APP_BUILD_SHA': deploy.BASE, 'P14_SHIFT_ENABLED': 'true'},
            'image': 'unchanged-pinned-image', 'networks': ['hoa_nam_dev_wms']} for name in deploy.SERVICES}}

    def test_only_source_mount_and_build_identity_change(self):
        original = self.config()
        new, old = deploy.patched_compose(original, deploy.ROOT / 'releases' / 'candidate', 'a' * 40)
        self.assertEqual(original, self.config())
        self.assertEqual(old, deploy.ROOT / 'releases' / deploy.BASE)
        for name in deploy.SERVICES:
            a, b = original['services'][name], new['services'][name]
            self.assertEqual(a['image'], b['image'])
            self.assertEqual(a['networks'], b['networks'])
            self.assertEqual(a['volumes'][1], b['volumes'][1])
            self.assertEqual(a['environment']['AUTO_MIGRATE'], b['environment']['AUTO_MIGRATE'])
            self.assertEqual(a['environment']['P14_SHIFT_ENABLED'], b['environment']['P14_SHIFT_ENABLED'])

    def test_migration_flag_build_drift_or_unexpected_mount_fail_closed(self):
        for bad in ['migration', 'sha', 'mount', 'different_roots']:
            config = self.config()
            if bad == 'migration': config['services']['queue']['environment']['AUTO_MIGRATE'] = 'true'
            if bad == 'sha': config['services']['backend']['environment']['APP_BUILD_SHA'] = 'unknown'
            if bad == 'mount': config['services']['nginx']['volumes'][0]['read_only'] = False
            if bad == 'different_roots': config['services']['queue']['volumes'][0]['source'] = '/other/release'
            with self.subTest(bad=bad), self.assertRaises(RuntimeError):
                deploy.patched_compose(config, Path('/candidate'), 'a' * 40)

    def archive(self, entries):
        target = tempfile.NamedTemporaryFile(suffix='.tar', delete=False)
        target.close()
        with tarfile.open(target.name, 'w') as tar:
            for name, kind in entries:
                info = tarfile.TarInfo(name)
                if kind == 'link':
                    info.type = tarfile.SYMTYPE
                    info.linkname = '/etc/passwd'
                    tar.addfile(info)
                else:
                    blob = b'approved code'
                    info.size = len(blob)
                    tar.addfile(info, io.BytesIO(blob))
        self.addCleanup(Path(target.name).unlink)
        return Path(target.name)

    def test_archive_requires_complete_scoped_files(self):
        files = [(name, 'file') for name in deploy.APP_FILES]
        self.assertEqual(set(deploy.archive_files(self.archive(files))), deploy.APP_FILES)
        with self.assertRaises(RuntimeError): deploy.archive_files(self.archive(files[:1]))

    def test_archive_rejects_traversal_symlinks_secrets_locks_and_unrelated_code(self):
        for name, kind in [('../escape', 'file'), ('/etc/passwd', 'file'), ('Modules/link', 'link'), ('.env', 'file'),
                           ('composer.lock', 'file'), ('public/build/manifest.json', 'file'), ('database/migrations/new.php', 'file'),
                           ('Modules/Auth/app/Models/User.php', 'file'), ('tools/deploy/..\\secret', 'file')]:
            with self.subTest(name=name), self.assertRaises(RuntimeError):
                deploy.archive_files(self.archive([(name, kind)]))


if __name__ == '__main__':
    unittest.main()
