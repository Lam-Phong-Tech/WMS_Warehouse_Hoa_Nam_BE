import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('p05_deploy', Path(__file__).with_name('deploy-devtest-p05-numbering.py'))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class P05DeploymentSafetyTest(unittest.TestCase):
    def test_exactly_one_migration_is_allowed(self):
        before = {'old': 3, 'older': 1}
        deploy.assert_migration_delta(before, {**before, deploy.MIGRATION: 4})
        for after in [before, {**before, deploy.MIGRATION: 4, 'pending_p11': 4}, {'old': 4, 'older': 1, deploy.MIGRATION: 5}, {**before, deploy.MIGRATION: 9}]:
            with self.subTest(after=after), self.assertRaises(RuntimeError):
                deploy.assert_migration_delta(before, after)

    def test_cli_uses_only_dev_oneshot_and_scoped_path(self):
        command = deploy.migration_command()
        self.assertEqual(command[:5], ['docker', 'compose', '-p', 'hoa_nam_dev', '-f'])
        self.assertEqual(command[5].replace('\\', '/'), '/opt/wms-hoanam/compose.json')
        self.assertEqual(command[6], 'run')
        self.assertIn('--path=' + deploy.MIGRATION_FILE, command)
        self.assertIn('AUTO_MIGRATE=false', command)
        self.assertIn('APP_CONFIG_CACHE=/tmp/p05-config.php', command)
        self.assertNotIn('migrate:fresh', command)
        self.assertNotIn('db:seed', command)
        self.assertNotIn('--seed', command)

    def test_payload_rejects_p11_secrets_locks_and_unrelated_source(self):
        for path in ['.env', 'composer.lock', 'database/migrations/2026_10_24_000001_create_p11_security_command_receipts.php',
                     'Modules/Auth/app/Models/User.php', 'Modules/Warehouse/app/Support/P18ComponentLocationRead.php']:
            with self.subTest(path=path):
                self.assertFalse(deploy.allowed(path))
        self.assertTrue(deploy.allowed(deploy.MIGRATION_FILE))


if __name__ == '__main__':
    unittest.main()
