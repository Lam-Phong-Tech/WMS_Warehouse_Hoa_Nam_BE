import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location('tc12', Path(__file__).with_name('deploy-green-tc12.py'))
tc12 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tc12)


class Tc12DeployTest(unittest.TestCase):
    def test_target_is_pinned_to_green_and_tested_source(self):
        self.assertEqual(tc12.green.DATABASE, 'wms_hoanam_green')
        self.assertEqual(tc12.green.ROOT.as_posix(), '/opt/hoa-nam-green')
        self.assertEqual(len(tc12.green.OVERLAYS), 14)
        self.assertEqual(tc12.TESTED, '1375dfaa58dc212f4ca4ff52fe8ca68a2fb28a43')

    def test_environment_does_not_change_secrets_flags_or_duplicate_keys(self):
        old = 'PRIVATE=unchanged\nP14_SHIFT_ENABLED=true\nAPP_BUILD_SHA=old\n'
        value = tc12.environment(old, {'APP_BUILD_SHA': 'new', 'AUTO_MIGRATE': 'false'})
        self.assertIn('PRIVATE=unchanged\nP14_SHIFT_ENABLED=true\n', value)
        self.assertEqual(value.count('APP_BUILD_SHA='), 1)
        self.assertIn('AUTO_MIGRATE=false', value)
        with self.assertRaisesRegex(RuntimeError, 'Duplicate'):
            tc12.environment('APP_BUILD_SHA=a\nAPP_BUILD_SHA=b\n', {'APP_BUILD_SHA': 'c'})

    def test_cleanup_rejects_protected_tagged_recent_or_partial_ids(self):
        image = 'sha256:' + 'a' * 64
        base = {'Id': image, 'RepoTags': [], 'Created': '2026-01-01T00:00:00Z', 'Size': 100}
        cases = [({image}, base, image), (set(), dict(base, RepoTags=['rollback:old']), image),
                 (set(), dict(base, Created='2999-01-01T00:00:00Z'), image), (set(), base, 'a' * 12)]
        for protected, row, requested in cases:
            with self.subTest(requested=requested, row=row), \
                    patch.object(tc12, 'inspect_images', return_value=(protected, [row])), \
                    patch.object(tc12.green, 'run') as run:
                with self.assertRaises(RuntimeError):
                    tc12.cleanup_images([requested])
                run.assert_not_called()

    def test_cleanup_uses_nonforced_exact_image_only_after_recheck(self):
        image = 'sha256:' + 'b' * 64
        row = {'Id': image, 'RepoTags': [], 'Created': '2026-01-01T00:00:00Z', 'Size': 100}
        with patch.object(tc12, 'inspect_images', return_value=(set(), [row])), \
                patch.object(tc12.green, 'run', return_value='') as run, patch.object(tc12.green, 'emit'):
            tc12.cleanup_images([image])
            self.assertEqual(run.call_args_list[0].args[0], ['docker', 'ps', '-aq', '--filter', 'ancestor=' + image])
            self.assertEqual(run.call_args_list[1].args[0], ['docker', 'image', 'rm', image])

    def test_package_blocks_wrong_sha_before_writing_archive(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            with self.assertRaises(RuntimeError):
                tc12.package(folder, 'not-a-sha', folder / 'out')
            self.assertFalse((folder / 'out').exists())


if __name__ == '__main__':
    unittest.main()
