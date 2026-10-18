import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('release', Path(__file__).with_name('deploy-green-auth-replacement.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class AuthReleaseTest(unittest.TestCase):
    def test_environment_edits_preserve_existing_secrets_without_logging_them(self):
        value = release.set_environment('PRIVATE_VALUE=do-not-print\nP14_SHIFT_ENABLED=true\n',
            {'AUTH_SINGLE_ACTIVE_SESSION_ENABLED': 'true', 'APP_BUILD_SHA': release.SOURCE})
        self.assertIn('PRIVATE_VALUE=do-not-print\n', value)
        self.assertIn('P14_SHIFT_ENABLED=true\n', value)
        self.assertEqual(value.count('AUTH_SINGLE_ACTIVE_SESSION_ENABLED='), 1)
        with self.assertRaisesRegex(RuntimeError, 'Duplicate environment key'):
            release.set_environment('APP_BUILD_SHA=a\nAPP_BUILD_SHA=b\n', {'APP_BUILD_SHA': 'c'})

    def test_only_exact_additive_migration_is_allowed(self):
        with tempfile.TemporaryDirectory() as directory:
            old, new = [Path(directory) / name for name in ['old', 'new']]
            for root in [old, new]:
                (root / 'database/migrations').mkdir(parents=True)
                (root / 'database/migrations/old.php').write_text('<?php')
            migration = new / release.MIGRATION
            migration.write_text('<?php // reviewed additive migration')
            release.verify_migration_delta(old, new, release.green.digest(migration))
            (new / 'database/migrations/old.php').write_text('<?php // changed old migration')
            with self.assertRaisesRegex(RuntimeError, 'Unreviewed migration content'):
                release.verify_migration_delta(old, new, release.green.digest(migration))
            (new / 'database/migrations/extra.php').write_text('<?php')
            with self.assertRaisesRegex(RuntimeError, 'Unexpected migration file delta'):
                release.verify_migration_delta(old, new, release.green.digest(migration))

    def test_target_is_only_green_and_candidate_is_explicit(self):
        self.assertEqual(release.ROOT.as_posix(), '/opt/hoa-nam-green')
        self.assertEqual(release.green.DATABASE, 'wms_hoanam_green')
        self.assertEqual(release.SOURCE, '9d9e87382eea39deb3f3d660b3955110b526ea3c')
        self.assertNotEqual(release.SOURCE, release.PREVIOUS)


if __name__ == '__main__':
    unittest.main()
