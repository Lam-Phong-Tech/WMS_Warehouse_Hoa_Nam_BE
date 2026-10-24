import importlib.util
from pathlib import Path
import unittest


spec = importlib.util.spec_from_file_location('timeline', Path(__file__).with_name('deploy-green-timeline.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class TimelineReleaseTest(unittest.TestCase):
    def test_only_reviewed_runtime_delta_is_permitted(self):
        old = {'old.php': 'same', **{p: 'old' for p in release.APPLICATION_DELTA | set(release.NGINX)}}
        new = {**old, **{p: 'new' for p in release.APPLICATION_DELTA}, **release.NGINX}
        release.validate_delta(old, new)
        for value in ({**new, 'config/new.php': 'x'}, {**new, 'old.php': 'changed'},
                      {**new, next(iter(release.NGINX)): 'wrong'}):
            with self.assertRaises(RuntimeError):
                release.validate_delta(old, value)

    def test_schema_is_inspected_never_applied(self):
        good = {'ran': [str(i) for i in range(219)], 'pending': [Path(release.app_release.P11).stem], 'orphaned': []}
        release.schema_guard(good)
        for value in ({**good, 'ran': good['ran'][:-1]}, {**good, 'pending': []}, {**good, 'orphaned': ['x']}):
            with self.assertRaises(RuntimeError):
                release.schema_guard(value)
        code = Path(release.__file__).read_text()
        for forbidden in ("'migrate'", 'db:seed', 'migrate:fresh', "'--clean'", "'volume', 'rm'", "'image', 'rm'"):
            self.assertNotIn(forbidden, code)

    def test_row_field_and_file_equality_not_only_count(self):
        before = {'sku': {'count': 933, 'hash': 'original'}}
        release.assert_unchanged(before, before.copy(), 'data')
        with self.assertRaises(RuntimeError):
            release.assert_unchanged(before, {'sku': {'count': 933, 'hash': 'changed'}}, 'data')

    def test_etag_overlays_and_retained_schema_are_pinned(self):
        self.assertEqual(len(release.OVERLAYS), 14)
        self.assertEqual(release.OVERLAYS[release.app_release.STAGING],
                         '06211cb3e0c532951f005f49aa882b6fcf3d08ac5fedcf3a20296a7b80d93767')
        root = Path(__file__).resolve().parents[2]
        for name, expected in release.NGINX.items():
            self.assertEqual(release.green.digest(root / name), expected)
        self.assertEqual(release.TLS_SHA256, '373414205df3f1604349736a51050d1f6f3c0922a0b6790837ec149fd0d168f9')
        self.assertEqual(release.SOURCE, '184bd43d95804a0c6f42d47ca64dfa39db40d9ae')

    def test_workflow_is_operation_only_without_auth_or_business_smoke(self):
        root = Path(__file__).resolve().parents[2]
        workflow = (root / '.github/workflows/hn-devops-ci-cd.yml').read_text(encoding='utf-8')
        job = workflow.split('  devtest-timeline:\n', 1)[1].split('  devtest-p14-etag:\n', 1)[0]
        self.assertIn('TIMELINE-INSPECT-184bd43d', job)
        self.assertIn('TIMELINE-DEPLOY-184bd43d', job)
        self.assertNotIn('npm ', job)
        self.assertNotIn('composer ', job)
        self.assertIn('test "$RUN_SEED" = false', job)

    def test_nested_read_only_vendor_mount_has_precreated_mountpoint(self):
        code = Path(release.__file__).read_text()
        self.assertLess(code.index("(release / 'vendor').mkdir(mode=0o755)"),
                        code.index("'-v', vendor + ':/var/www/html/vendor:ro'"))
        self.assertIn('capture_output=True, text=True, timeout=120', code)
        self.assertNotIn('print(result.stderr)', code)


if __name__ == '__main__':
    unittest.main()
