import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('p06', Path(__file__).with_name('deploy-green-p06-master.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class P06MasterReleaseTest(unittest.TestCase):
    def test_only_three_reviewed_runtime_files_may_change(self):
        old = {'old': 'same', **{k: 'before' for k in release.APPLICATION_DELTA}, **release.NGINX}
        new = {**old, **{k: 'after' for k in release.APPLICATION_DELTA}}
        release.validate_delta(old, new)
        for wrong in ({**new, 'old': 'changed'}, {**new, next(iter(release.NGINX)): 'bad'}, {**new, 'new_file': 'extra'}):
            with self.assertRaises(RuntimeError):
                release.validate_delta(old, wrong)

    def test_no_migration_or_data_mutator_is_invoked(self):
        code = Path(release.__file__).read_text()
        for text in ("'artisan', 'migrate'", 'db:seed', 'migrate:fresh', "'volume', 'rm'", "'image', 'rm'"):
            self.assertNotIn(text, code)
        self.assertIn("assert_unchanged(before_data, after_data, 'Full database fingerprints including migrations')", code)
        self.assertIn('new_migrations == old_migrations', code)
        self.assertIn('after_schema == before_schema', code)

    def test_missing_or_broad_audit_approval_cannot_activate(self):
        valid = {'status': 'APPROVED_BY_USER_FOR_DEV_ONLY', 'approved_by': 'USER', 'source_sha': release.SOURCE,
                 'environment': 'DEV_TEST_ONLY', 'npm_audit_status': 'OPEN_NOT_PASS', 'dependency_changes': False,
                 'production_activation': False, 'migration_seed_box_assignment': False}
        release.audit_gate(valid)
        for key in valid:
            bad = {k: v for k, v in valid.items() if k != key}
            with self.assertRaises(RuntimeError):
                release.audit_gate(bad)
        with self.assertRaises(RuntimeError):
            release.audit_gate({**valid, 'production_activation': True})
        code = Path(release.__file__).read_text()
        self.assertLess(code.index("audit_gate(proof['dev_audit_decision'])"), code.index("green.artisan('down'"))

    def test_existing_schema_and_pending_p11_are_not_migrated(self):
        valid = {'ran': list(range(220)), 'pending': [Path(release.app_release.P11).stem], 'orphaned': []}
        release.schema_guard(valid)
        with self.assertRaises(RuntimeError):
            release.schema_guard({**valid, 'pending': []})


if __name__ == '__main__':
    unittest.main()
