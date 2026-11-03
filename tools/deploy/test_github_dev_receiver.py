import importlib.util
from pathlib import Path
import sys
import types
import unittest


sys.modules.setdefault('fcntl', types.ModuleType('fcntl'))
spec = importlib.util.spec_from_file_location('receiver', Path(__file__).with_name('github_dev_receiver.py'))
receiver = importlib.util.module_from_spec(spec)
spec.loader.exec_module(receiver)


class BackendBuildIdentityTest(unittest.TestCase):
    def test_explicit_compose_environment_is_updated_for_every_php_service(self):
        previous = 'a' * 40
        candidate = 'b' * 40
        services = ['backend', 'nginx', 'queue', 'scheduler', 'reverb']
        config = {'services': {
            name: {'environment': {
                'AUTO_MIGRATE': 'false',
                'APP_BUILD_SHA': previous,
                'APP_BUILD_REF': 'dev-p05-save-numbering@' + previous,
                'APP_BUILD_TIME': '2026-01-01T00:00:00Z',
                'P19_ENABLED': 'true',
            }} for name in services
        }}

        receiver.update_backend_build_identity(config, services, candidate, '2026-11-03T00:00:00Z')

        for name in services:
            env = config['services'][name]['environment']
            self.assertEqual(env['P19_ENABLED'], 'true')
            if name == 'nginx':
                self.assertEqual(env['APP_BUILD_SHA'], previous)
            else:
                self.assertEqual(env['APP_BUILD_SHA'], candidate)
                self.assertEqual(env['APP_BUILD_REF'], 'dev@' + candidate)
                self.assertEqual(env['APP_BUILD_TIME'], '2026-11-03T00:00:00Z')

    def test_migration_flag_must_remain_disabled(self):
        config = {'services': {'backend': {'environment': {'AUTO_MIGRATE': 'true'}}}}
        with self.assertRaisesRegex(ValueError, 'AUTO_MIGRATE'):
            receiver.update_backend_build_identity(config, ['backend'], 'b' * 40, '2026-11-03T00:00:00Z')


if __name__ == '__main__':
    unittest.main()
