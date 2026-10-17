import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location('green', Path(__file__).with_name('deploy-green-verified.py'))
green = importlib.util.module_from_spec(spec)
spec.loader.exec_module(green)


class GreenPromotionTest(unittest.TestCase):
    def test_command_errors_do_not_expose_process_output(self):
        result = subprocess.CompletedProcess(['example'], 1, 'private-token', 'private-password')
        with patch.object(green.subprocess, 'run', return_value=result):
            with self.assertRaisesRegex(RuntimeError, '^example exited 1$'):
                green.run(['example'])

    def test_fingerprints_use_read_only_transaction(self):
        with patch.object(green, 'pg', side_effect=['["sku"]', '{"sku":{"count":1,"hash":"digest"}}']) as pg:
            result = green.fingerprints()
        self.assertEqual(result['sku']['count'], 1)
        query = pg.call_args_list[1].args[0]
        self.assertIn('REPEATABLE READ READ ONLY', query)
        self.assertIn('ORDER BY to_jsonb(t)::text', query)
        self.assertTrue(query.endswith('COMMIT;'))

    def test_fingerprints_reject_unexpected_identifiers(self):
        with patch.object(green, 'pg', return_value=json.dumps(['not-a-safe-identifier'])):
            with self.assertRaisesRegex(RuntimeError, 'Unexpected database identifier'):
                green.fingerprints()

    def test_file_manifests_detect_content_drift(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            media = root / 'storage/app/image.bin'
            media.parent.mkdir(parents=True)
            media.write_bytes(b'before')
            before = green.media_manifest(root)
            media.write_bytes(b'after')
            self.assertNotEqual(before, green.media_manifest(root))
            migration = root / 'Modules/Warehouse/database/migrations/example.php'
            migration.parent.mkdir(parents=True)
            migration.write_text('<?php // before')
            before = green.migrations(root)
            migration.write_text('<?php // after')
            self.assertNotEqual(before, green.migrations(root))

    def test_target_and_overlay_scope_are_pinned(self):
        self.assertEqual(green.ROOT.as_posix(), '/opt/hoa-nam-green')
        self.assertEqual(green.DATABASE, 'wms_hoanam_green')
        self.assertEqual(len(green.OVERLAYS), 14)
        self.assertNotEqual(green.SOURCE_SHA, green.PREVIOUS_SHA)


if __name__ == '__main__':
    unittest.main()
