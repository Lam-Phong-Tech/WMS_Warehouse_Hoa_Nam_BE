import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location('app_release', Path(__file__).with_name('deploy-green-app-20261024.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class AppReleaseTest(unittest.TestCase):
    def test_only_p13_schema_is_executable_and_p11_is_deferred(self):
        self.assertEqual(release.green.DATABASE, 'wms_hoanam_green')
        self.assertEqual(release.SOURCE, 'a9053a21bc35ece367be0b76db727d4749b59855')
        self.assertIn('2026_10_23', release.P13)
        self.assertIn('2026_10_24', release.P11)
        code = Path(release.__file__).read_text()
        self.assertEqual(code.count("'artisan', 'migrate', '--path=' + P13"), 1)
        self.assertNotIn('migrate:fresh', code)
        self.assertNotIn('db:seed', code)
        self.assertNotIn('pg_restore\', \'--clean', code)

    def test_migration_delta_rejects_removal_modification_or_extra(self):
        before = {'old.php': 'original'}
        after = {**before, release.P13: 'p13', release.P11: 'p11'}
        release.validate_migration_delta(before, after)
        for invalid in ({release.P13: 'p13', release.P11: 'p11'},
                        {**after, 'old.php': 'changed'}, {**after, 'other.php': 'new'},
                        {**before, release.P13: 'p13'}):
            with self.assertRaises(RuntimeError):
                release.validate_migration_delta(before, invalid)

    def test_business_table_hashes_must_match_not_only_counts(self):
        before = {'sku': {'count': 933, 'hash': 'old'}, 'migrations': {'count': 218, 'hash': 'before'}}
        after = {**before, 'migrations': {'count': 219, 'hash': 'after'},
                 'mini_app_web_post_handoffs': {'count': 0, 'hash': 'empty'}}
        release.assert_business_unchanged(before, after)
        for invalid in ({**after, 'sku': {'count': 933, 'hash': 'modified'}},
                        {**after, 'other': {'count': 0}},
                        {**after, 'mini_app_web_post_handoffs': {'count': 1}},
                        {**after, 'migrations': {'count': 220}}):
            with self.assertRaises(RuntimeError):
                release.assert_business_unchanged(before, invalid)

    def test_overlay_three_way_preserves_both_edits(self):
        base = b'<?php\n// base\n// middle\n// tail\n'
        runtime = base.replace(b'// base', b'// runtime fix')
        candidate = base.replace(b'// tail', b'use Modules\\Warehouse\\Support\\P13WebPostHandoff;\nP13WebPostHandoff::record(1);')
        with patch.dict(release.green.OVERLAYS, {release.STAGING: release.sha(runtime)}):
            value = release.merge_staging(runtime, base, candidate)
        self.assertIn(b'// runtime fix', value)
        self.assertIn(b'P13WebPostHandoff::record(1)', value)

    def test_overlay_drift_or_conflict_blocks(self):
        with self.assertRaisesRegex(RuntimeError, 'drift'):
            release.merge_staging(b'wrong', b'base', b'source')
        base, runtime, candidate = b'one\n', b'runtime\n', b'candidate\n'
        with patch.dict(release.green.OVERLAYS, {release.STAGING: release.sha(runtime)}):
            with self.assertRaisesRegex(RuntimeError, 'conflicts'):
                release.merge_staging(runtime, base, candidate)

    def test_environment_preserves_secrets_and_flags(self):
        before = 'PRIVATE=unchanged\nP11_SECURITY_COMMANDS_ENABLED=false\nP14_SHIFT_ENABLED=true\n'
        value = release.tc12.environment(before, {'APP_BUILD_SHA': release.SOURCE, 'AUTO_MIGRATE': 'false'})
        self.assertTrue(value.startswith(before))
        self.assertIn('AUTO_MIGRATE=false', value)


if __name__ == '__main__':
    unittest.main()
