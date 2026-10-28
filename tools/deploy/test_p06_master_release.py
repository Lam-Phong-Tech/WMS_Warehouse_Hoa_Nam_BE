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

    def test_no_audit_waiver_can_activate_and_every_severity_must_be_zero(self):
        valid = {'npm': {'metadata': {'vulnerabilities': {'total': 0, 'high': 0}}, 'vulnerabilities': {}},
                 'composer': {'advisories': [], 'abandoned': []}}
        release.audit_gate(valid)
        for key in valid:
            bad = {k: v for k, v in valid.items() if k != key}
            with self.assertRaises(RuntimeError):
                release.audit_gate(bad)
        with self.assertRaises(RuntimeError):
            release.audit_gate({'status': 'APPROVED_BY_USER_FOR_DEV_ONLY'})
        with self.assertRaises(RuntimeError):
            release.audit_gate({**valid, 'npm': {'metadata': {'vulnerabilities': {'total': 1, 'low': 1}}, 'vulnerabilities': {}}})
        code = Path(release.__file__).read_text()
        self.assertLess(code.index("audit_gate(proof['audits'])"), code.index("green.artisan('down'"))
        self.assertIn("folder / 'assets.tar.gz'", code)

    def test_existing_schema_and_pending_p11_are_not_migrated(self):
        valid = {'ran': list(range(220)), 'pending': [Path(release.app_release.P11).stem], 'orphaned': []}
        release.schema_guard(valid)
        with self.assertRaises(RuntimeError):
            release.schema_guard({**valid, 'pending': []})

    def test_only_spreadsheet_dependency_can_change(self):
        old = {'packages': [{'name': 'old/unchanged', 'version': '1'}, {'name': 'phpoffice/phpspreadsheet', 'version': '5.9.0'}], 'packages-dev': []}
        new = {'packages': [{'name': 'old/unchanged', 'version': '1'}, {'name': 'phpoffice/phpspreadsheet', 'version': '5.10.0'}], 'packages-dev': []}
        release.validate_dependency_delta(old, new)
        new['packages'][0]['version'] = '2'
        with self.assertRaises(RuntimeError):
            release.validate_dependency_delta(old, new)


if __name__ == '__main__':
    unittest.main()
