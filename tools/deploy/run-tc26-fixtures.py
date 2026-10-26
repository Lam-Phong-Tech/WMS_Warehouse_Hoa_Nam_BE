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


def fixture_footprint():
    return json.loads(sql("SELECT json_build_object('actors',(SELECT count(*) FROM app_user WHERE email='tc26.fixture@wms-hoanam.test'),"
        "'roles',(SELECT count(*) FROM role WHERE role_code='TC26_FIXTURE'),"
        "'skus',(SELECT count(*) FROM sku WHERE sku_code IN ('TC26-PRODUCT','TC26-COMPONENT')),"
        "'suppliers',(SELECT count(*) FROM inbound_suppliers WHERE name='TC26 TEST SUPPLIER'))"))


def main(checkout, inspect=False, retry_empty=False):
    os.umask(0o077)
    policy = release.app_release.configuration()
    require(policy['sha'] == release.SOURCE and policy['environment'] == 'testing'
            and policy['database'] == green.DATABASE and policy['protected'] and not policy['allow_seed']
            and not policy['p11'] and not policy['p19'], 'DEV policy guard failed')
    if inspect:
        footprint = fixture_footprint()
        columns = json.loads(sql("SELECT json_agg(json_build_object('table',table_name,'column',column_name,'nullable',is_nullable)) "
            "FROM information_schema.columns WHERE table_schema='public' AND table_name IN ('role_permission','user_role','sku','inbound_suppliers')"))
        green.emit('tc26_read_only_diagnostic', {'state_exists': STATE.exists(), 'proof_exists': PROOF.exists(),
            'footprint': footprint, 'compatibility_columns': columns, 'policy': policy})
        if STATE.exists():
            state = json.loads(STATE.read_text())
            actor = state.get('actor_id', '')
            require(len(actor) == 36 and all(c in '0123456789abcdef-' for c in actor), 'Invalid TEST actor identity')
            operations = state.get('operations', {})
            # Only minimized, previously captured TEST HTTP is exported. Never auth responses/private secrets.
            evidence = [e for e in state.get('evidence', []) if not e['path'].startswith('/auth/')]
            notifications = json.loads(sql("SELECT coalesce(json_agg(row_to_json(q)),'[]'::json) FROM ("
                "SELECT n.id AS notification_id,n.subject_id,n.event_key,s.job_title,s.capture_policy,s.captured_at,"
                "(SELECT count(*) FROM notification_recipients r WHERE r.notification_id=n.id) AS recipient_count,"
                "(SELECT count(*) FROM notification_recipients r WHERE r.notification_id=n.id AND r.user_id=n.actor_id) AS self_recipient_count "
                "FROM notifications n LEFT JOIN notification_source_snapshots s ON s.notification_id=n.id "
                "WHERE n.actor_id='" + actor + "' ORDER BY n.created_at,n.id) q"))
            def data(name):
                return operations.get(name, {}).get('data', {})
            fixture = {
                'tc04': {'document_id': data('tc04-source-create').get('document_id'), 'source': 'OUTBOUND'},
                'tc07': {'draft_id': state.get('keys', {}).get('tc07-draft'), 'raw_code': 'HN1|SKU=TC26-PRODUCT|ITEM=TC26-RECEIPTABLE',
                         'supplier_id': state.get('supplier_id'), 'quantity_limit': 1},
                'tc11': {'source_document_id': data('tc11-source-create').get('document_id'),
                         'source_version': data('tc11-source-create').get('version'), 'raw_code': 'HN1|SKU=TC26-PRODUCT|ITEM=TC26-OUTBOUND'},
                'tc12': {'case_id': data('tc12-create').get('warranty_case_id'), 'component_code': 'TC26-COMPONENT',
                         'status': data('tc12-checking').get('receipt', {}).get('status')},
            }
            report = {'status': 'EXISTING_TEST_OWNER_EVIDENCE_READ_ONLY', 'source_sha': policy['sha'], 'fixture': fixture,
                'operations': {k: v.get('phase') for k, v in operations.items()}, 'notifications_persisted': notifications,
                'evidence': evidence, 'new_login': False, 'writes_replayed': False, 'private_drafts_not_transferable': True}
            public = green.ROOT / 'backups/tc26-existing-redacted-live.json'
            public.write_text(json.dumps(report, ensure_ascii=False, indent=2))
            os.chmod(public, 0o600)
            green.emit('tc26_existing_owner_evidence', report)
        return
    if retry_empty:
        require(not STATE.exists() and PROOF.exists() and not any(fixture_footprint().values()),
                'Retry requires absent private state AND zero TEST footprint; no command replay')
        previous = json.loads(PROOF.read_text())
        require(previous.get('historical_business_rows_unchanged') is True,
                'Previous failed provisioning does not prove unchanged history')
        # Preserve the failed attempt; never overwrite its proof or reset a command journal.
        import datetime
        archived = PROOF.with_name('tc26-historical-proof-empty-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '.json')
        require(not archived.exists(), 'Proof archive collision')
        PROOF.rename(archived)
        green.emit('tc26_empty_provision_retry', {'prior_proof': str(archived), 'zero_fixture_footprint': True,
                                                'no_prior_login_or_owner_journal': True})
    require(not STATE.exists() and not PROOF.exists(), 'Existing fixture/private journal; no automatic reprovision/login/replay')
    keys = historical_keys()
    before = fingerprints(keys)
    PROOF.write_text(json.dumps({'keys': keys, 'before': before, 'stage': 'BEFORE_TEST_FIXTURE'}))
    os.chmod(PROOF, 0o600)
    try:
        provision = subprocess.run(['docker', 'exec', '-i', '-u', 'www-data', '-e', 'FUTURE_FIXTURE_CONFIRM=TC26-TEST-ONLY-APPROVED',
                           'hoa_nam_green-backend-1', 'php'], input=(checkout / 'tools/deploy/prepare-tc26-fixtures.php').read_text(),
                           text=True, capture_output=True, timeout=120)
        if provision.returncode:
            for line in provision.stderr.splitlines():
                if line.startswith('TC26_PROVISION_FAILED:'):
                    detail = json.loads(line.split(':', 1)[1])
                    green.emit('tc26_provision_error', {k: detail[k] for k in ('stage', 'class', 'sqlstate')})
            raise RuntimeError('TEST provisioning failed; no owner command executed')
        secret = provision.stdout
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
    parser.add_argument('--inspect', action='store_true')
    parser.add_argument('--retry-empty', action='store_true')
    args = parser.parse_args()
    main(Path(args.checkout), args.inspect, args.retry_empty)
