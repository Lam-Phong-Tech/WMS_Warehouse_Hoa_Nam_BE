import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('future', Path(__file__).with_name('deploy-green-future-evidence.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class FutureReleaseTest(unittest.TestCase):
    def test_only_declared_source_and_staging_overlay_may_change(self):
        before = {'old': 'same', **{key: 'before' for key in release.APPLICATION_DELTA}, **release.NGINX}
        after = {**before, **{key: 'after' for key in release.APPLICATION_DELTA}}
        release.validate_delta(before, after)
        for bad in ({**after, 'old': 'modified'}, {**after, 'extra': 'new'},
                    {**after, next(iter(release.NGINX)): 'wrong'}):
            with self.assertRaises(RuntimeError):
                release.validate_delta(before, bad)

    def test_migration_and_private_coordinator_are_bounded(self):
        code = Path(release.__file__).read_text()
        self.assertEqual(code.count("'artisan', 'migrate', '--path=' + MIGRATION"), 1)
        self.assertEqual(release.NEW_TABLES, {'user_business_profiles', 'notification_source_snapshots',
                                            'stock_document_event_evidence', 'warranty_product_snapshots'})
        for forbidden in ('db:seed', 'migrate:fresh', "'--clean'", "'volume', 'rm'", "'image', 'rm'"):
            self.assertNotIn(forbidden, code)
        coordinator = Path(__file__).with_name('run-tc26-fixtures.py').read_text()
        self.assertIn('capture_output=True', coordinator)
        self.assertNotIn('print(secret)', coordinator)
        self.assertIn('before == after', coordinator)

    def test_fixture_imports_precede_bootstrap_and_stderr_is_stdin_safe(self):
        code = Path(__file__).with_name('prepare-tc26-fixtures.php').read_text(encoding='utf-8')
        self.assertLess(code.index('use Illuminate\\Contracts\\Console\\Kernel;'), code.index('$app->make(Kernel::class)'))
        self.assertLess(code.index('try {'), code.index("require '/var/www/html/vendor/autoload.php'"))
        self.assertNotIn('fwrite(STDERR', code)
        self.assertIn("file_put_contents('php://stderr'", code)

    def test_creator_is_not_fabricated_as_notification_recipient(self):
        code = Path(__file__).with_name('tc26-owner-fixtures.mjs').read_text(encoding='utf-8')
        self.assertIn('RECIPIENT_RETEST_PENDING', code)
        self.assertNotIn('Queued TEST notification snapshot not available yet', code)
        self.assertNotIn("call('tc02-detail'", code)


if __name__ == '__main__':
    unittest.main()
