#!/usr/bin/env python3
"""Run approved TEST owner commands without exposing credentials or editing old rows."""

import argparse
import importlib.util
import json
import os
from pathlib import Path
import subprocess

spec = importlib.util.spec_from_file_location('release', Path(__file__).with_name('deploy-green-future-evidence.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
green, require = release.green, release.require
STATE = green.ROOT / 'backups/tc26-owner-state.json'
PROOF = green.ROOT / 'backups/tc26-historical-proof.json'
CRITICAL = {'skus', 'sku', 'item_instances', 'physical_codes', 'stock_documents', 'stock_document_lines',
            'warranty_cases', 'warranty_events', 'inventory_balances', 'inventory_movements', 'stock_movements',
            'outbound_requests', 'outbound_request_lines', 'containers', 'container_items'}


def sql(query):
    return green.run(['docker', 'exec', '-i', 'hoa_nam_green-postgres-1', 'psql', '-X', '-q', '-At',
                      '-U', 'postgres', '-d', green.DATABASE, '-v', 'ON_ERROR_STOP=1'], input_data=query).strip()


def historical_keys():
    rows = json.loads(sql("SELECT json_agg(json_build_object('table',t.tablename,'key',a.attname)) "
        "FROM pg_tables t JOIN pg_class c ON c.relname=t.tablename JOIN pg_namespace n ON n.oid=c.relnamespace AND n.nspname=t.schemaname "
        "JOIN pg_index i ON i.indrelid=c.oid AND i.indisprimary AND cardinality(i.indkey::smallint[])=1 "
        "JOIN pg_attribute a ON a.attrelid=c.oid AND a.attnum=ANY(i.indkey) WHERE t.schemaname='public'"))
    result = {}
    for row in rows:
        table, key = row['table'], row['key']
        if table not in CRITICAL:
            continue
        require(table.replace('_', '').isalnum() and key.replace('_', '').isalnum(), 'Unsafe identifier')
        ids = json.loads(sql(f'SELECT coalesce(json_agg("{key}"::text),\'[]\'::json) FROM "{table}"'))
        require(all(isinstance(i, str) and all(c in '0123456789abcdefABCDEF-' for c in i) for i in ids), 'Unexpected business key')
        result[table] = {'key': key, 'ids': ids}
    require({'skus', 'stock_documents', 'item_instances', 'warranty_cases', 'inventory_balances'}.issubset(result), 'Historical scope incomplete')
    return result


def fingerprints(keys):
    result = {}
    for table, value in keys.items():
        literal = ','.join("'" + i + "'" for i in value['ids']) or "''"
        result[table] = json.loads(sql(f"SELECT json_build_object('count',count(*),'hash',md5(coalesce(string_agg(to_jsonb(t)::text,'' ORDER BY to_jsonb(t)::text),''))) "
            f"FROM \"{table}\" t WHERE \"{value['key']}\"::text IN ({literal})"))
    return result


def main(checkout):
    os.umask(0o077)
    policy = release.app_release.configuration()
    require(policy['sha'] == release.SOURCE and policy['environment'] == 'testing'
            and policy['database'] == green.DATABASE and policy['protected'] and not policy['allow_seed']
            and not policy['p11'] and not policy['p19'], 'DEV policy guard failed')
    require(not STATE.exists() and not PROOF.exists(), 'Existing fixture/private journal; no automatic reprovision/login/replay')
    keys = historical_keys()
    before = fingerprints(keys)
    PROOF.write_text(json.dumps({'keys': keys, 'before': before, 'stage': 'BEFORE_TEST_FIXTURE'}))
    os.chmod(PROOF, 0o600)
    try:
        secret = green.run(['docker', 'exec', '-i', '-u', 'www-data', '-e', 'FUTURE_FIXTURE_CONFIRM=TC26-TEST-ONLY-APPROVED',
                           'hoa_nam_green-backend-1', 'php'], input_data=(checkout / 'tools/deploy/prepare-tc26-fixtures.php').read_text())
        values = json.loads(secret)
        with STATE.open('x') as stream:
            json.dump(values, stream)
        os.chmod(STATE, 0o600)
        env = {**os.environ, 'EXPECTED_SOURCE_SHA': release.SOURCE}
        # Only the minimized TEST HTTP evidence is emitted by the child, never auth bodies.
        child = subprocess.run(['node', str(checkout / 'tools/deploy/tc26-owner-fixtures.mjs'), str(STATE)],
                               env=env, capture_output=True, text=True, timeout=600)
        if child.returncode:
            green.emit('tc26_smoke_incomplete', {'exit': child.returncode, 'state_retained': str(STATE),
                'policy': 'CHECK_ONLY_NO_RELOGIN_REPAIR_OR_RESTORE', 'error': child.stderr[-600:] if 'Error:' in child.stderr else 'Read private coordinator state'})
            raise RuntimeError('TC26 owner smoke failed; retain journal and investigate')
        lines = [json.loads(line) for line in child.stdout.splitlines() if line.startswith('{')]
        require(lines and lines[-1]['status'] == 'TC26_OWNER_FIXTURES_PASS', 'Fixture result incomplete')
        public = lines[-1]
        target = green.ROOT / 'backups/tc26-redacted-live.json'
        target.write_text(json.dumps(public, ensure_ascii=False, indent=2))
        os.chmod(target, 0o600)
        green.emit('tc26_fixture_live', public)
    finally:
        after = fingerprints(keys)
        preserved = before == after
        proof = {'before': before, 'after': after, 'historical_business_rows_unchanged': preserved,
                 'baseline_key_counts': {name: len(row['ids']) for name, row in keys.items()},
                 'schema_repair_restore': False, 'production_activation': False}
        PROOF.write_text(json.dumps(proof, indent=2))
        green.emit('tc26_historical_verification', proof)
        require(preserved, 'Historical row/field change detected; no automatic repair or restore')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--checkout', required=True)
    args = parser.parse_args()
    main(Path(args.checkout))
