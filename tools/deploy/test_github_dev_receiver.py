import importlib.util
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import types
import unittest
from unittest import mock


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


class ReviewedAdditiveMigrationTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.base = Path(self.directory.name)
        self.old = self.base / 'releases' / ('a' * 40)
        self.new = self.base / 'releases' / ('b' * 40)
        self.old.mkdir(parents=True)
        self.new.mkdir(parents=True)
        (self.old / '.ci-source-sha').write_text('a' * 40)
        (self.new / '.ci-source-sha').write_text('b' * 40)
        self.existing = 'database/migrations/2026_01_01_existing.php'
        self.added = 'Modules/Auth/database/migrations/2026_11_04_profile.php'
        self.write(self.old, self.existing, '<?php // existing')
        self.write(self.new, self.existing, '<?php // existing')
        self.write(self.new, self.added, '<?php // reviewed additive migration')
        self.receipt = self.base / 'operations' / 'reviewed-migrations' / ('b' * 40 + '.json')

    def write(self, root, relative, content):
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)

    def approved(self):
        return {'schema': 'hoanam-reviewed-additive-migrations-v1', 'release_sha': 'b' * 40,
                'base_sha': 'a' * 40, 'base_release': str(self.old.resolve()), 'status': 'APPLIED',
                'added_migrations': {self.added: receiver.sha256(self.new / self.added)},
                'applied_at': '2026-11-04T00:00:00Z', 'verification': 'MANUAL_MIGRATION_SUCCEEDED'}

    def save(self, receipt=None):
        self.receipt.parent.mkdir(parents=True, exist_ok=True)
        self.receipt.write_text(json.dumps(self.approved() if receipt is None else receipt))

    def secure_metadata(self, path, *, directory):
        # Real temporary files on Windows and non-root CI runners cannot be root
        # owned. Simulate only POSIX trust metadata; actual contents/identity/open
        # and all approval comparisons still execute normally.
        result = list(os.lstat(path))
        result[0] = stat.S_IFDIR | 0o755 if directory else stat.S_IFREG | 0o600
        result[4] = 0
        return os.stat_result(result)

    def secured(self, mutation=None):
        original_fstat = os.fstat

        def metadata(path, *, directory):
            result = self.secure_metadata(path, directory=directory)
            if mutation is not None:
                result = mutation(Path(path), directory, result)
            return receiver.check_reviewed_path_stat(path, result, directory=directory)

        def fstat(fd):
            result = list(original_fstat(fd))
            result[0] = stat.S_IFREG | 0o600
            result[4] = 0
            return os.stat_result(result)

        return mock.patch.multiple(receiver, trusted_reviewed_path_stat=metadata,
                                   reviewed_file_stat=fstat)

    def verify(self):
        return receiver.verify_reviewed_additive_migrations(self.base, self.old, self.new, 'b' * 40)

    def test_missing_receipt_cannot_be_supplied_by_artifact(self):
        self.write(self.new, 'operations/reviewed-migrations/' + 'b' * 40 + '.json', json.dumps(self.approved()))
        with self.secured(), self.assertRaisesRegex(ValueError, 'receipt'):
            self.verify()

    def test_receipt_location_cannot_be_redirected_by_relative_base_or_sha(self):
        with self.assertRaisesRegex(ValueError, 'path or release identity'):
            receiver.read_reviewed_migration_receipt(Path('relative-base'), 'b' * 40)
        with self.assertRaisesRegex(ValueError, 'path or release identity'):
            receiver.read_reviewed_migration_receipt(self.base, '../artifact-approval')

    def test_explicit_applied_receipt_matches_exact_additions_and_base_identity(self):
        self.save()
        before = self.receipt.read_bytes()
        with self.secured():
            result = self.verify()
        self.assertEqual(result['added_migrations'], self.approved()['added_migrations'])
        self.assertEqual(result['receipt_sha256'], receiver.sha256(self.receipt))
        self.assertEqual(self.receipt.read_bytes(), before, 'Receiver must never mark a migration as applied.')

    def test_unchanged_migration_set_needs_no_receipt(self):
        (self.new / self.added).unlink()
        self.assertIsNone(self.verify())

    def test_changed_or_deleted_existing_migration_is_never_approved(self):
        self.save()
        self.write(self.new, self.existing, '<?php // changed')
        with self.secured(), self.assertRaisesRegex(ValueError, 'changed or removed'):
            self.verify()
        (self.new / self.existing).unlink()
        with self.secured(), self.assertRaisesRegex(ValueError, 'changed or removed'):
            self.verify()

    def test_wrong_sha_base_status_hash_or_extra_keys_are_rejected(self):
        mutations = [
            ('release_sha', 'c' * 40), ('base_sha', 'c' * 40), ('base_release', str(self.base)),
            ('status', 'PENDING'), ('verification', 'REQUESTED'), ('applied_at', 'not-a-date'),
            ('added_migrations', {self.added: 'c' * 64}),
            ('added_migrations', {}), ('added_migrations', {**self.approved()['added_migrations'], 'extra.php': 'd' * 64}),
            ('artifact_can_approve', True),
        ]
        for field, value in mutations:
            with self.subTest(field=field, value=value):
                receipt = self.approved()
                receipt[field] = value
                self.save(receipt)
                with self.secured(), self.assertRaises(ValueError):
                    self.verify()

    def test_existing_base_requires_its_own_exact_build_marker(self):
        self.save()
        (self.old / '.ci-source-sha').write_text('invalid')
        with self.secured(), self.assertRaisesRegex(ValueError, 'base'):
            self.verify()

    def test_untrusted_receipt_and_directory_metadata_are_rejected(self):
        self.save()
        cases = [('file mode', False, 0, stat.S_IFREG | 0o644, 1),
                 ('file owner', False, 1000, stat.S_IFREG | 0o600, 1),
                 ('file symlink', False, 0, stat.S_IFLNK | 0o600, 1),
                 ('file hardlink', False, 0, stat.S_IFREG | 0o600, 2),
                 ('directory mode', True, 0, stat.S_IFDIR | 0o777, 1),
                 ('directory owner', True, 1000, stat.S_IFDIR | 0o755, 1),
                 ('directory symlink', True, 0, stat.S_IFLNK | 0o755, 1)]
        for label, is_directory, uid, mode, links in cases:
            with self.subTest(case=label):
                def mutate(path, directory, result):
                    if directory == is_directory and (not directory or path == self.receipt.parent):
                        changed = list(result)
                        changed[0], changed[3], changed[4] = mode, links, uid
                        return os.stat_result(changed)
                    return result
                with self.secured(mutate), self.assertRaisesRegex(ValueError, 'trusted|root|receipt'):
                    self.verify()

    def test_duplicate_json_keys_are_rejected(self):
        self.save()
        self.receipt.write_text(self.receipt.read_text()[:-1] + ', "status": "APPLIED"}')
        with self.secured(), self.assertRaisesRegex(ValueError, 'duplicate'):
            self.verify()

    def test_opened_file_identity_must_match_validated_file(self):
        self.save()
        original = os.fstat
        with self.secured():
            def substituted(fd):
                result = list(original(fd))
                result[0], result[4], result[1] = stat.S_IFREG | 0o600, 0, result[1] + 1
                return os.stat_result(result)
            with mock.patch.object(receiver, 'reviewed_file_stat', substituted), self.assertRaisesRegex(ValueError, 'identity'):
                self.verify()


if __name__ == '__main__':
    unittest.main()
