\pset format unaligned
\pset fieldsep '|'
\echo === A. scan_events la BANG VAT LY hay VIEW ===
SELECT c.relname, CASE c.relkind WHEN 'r' THEN 'TABLE' WHEN 'v' THEN 'VIEW' ELSE c.relkind::text END AS kind
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relname IN ('scan_events','scan_event','scan_batch','scan_batches')
ORDER BY c.relname;

\echo === B. Cot scan_source theo ERD bang 25 ===
SELECT column_name, data_type, character_maximum_length, is_nullable
FROM information_schema.columns
WHERE table_schema='public' AND table_name='scan_events'
  AND column_name IN ('scan_source','context_type','context_id','code_value','result')
ORDER BY column_name;

\echo === C. CHECK constraint cua scan_source ===
SELECT con.conname, pg_get_constraintdef(con.oid) AS definition
FROM pg_constraint con
JOIN pg_class rel ON rel.oid=con.conrelid
JOIN pg_namespace n ON n.oid=rel.relnamespace
WHERE n.nspname='public' AND rel.relname='scan_events' AND con.contype='c'
  AND pg_get_constraintdef(con.oid) ILIKE '%scan_source%';

\echo === D. FK cua scan evidence (physical_code repoint) ===
SELECT con.conname, pg_get_constraintdef(con.oid) AS definition
FROM pg_constraint con
JOIN pg_class rel ON rel.oid=con.conrelid
JOIN pg_namespace n ON n.oid=rel.relnamespace
WHERE n.nspname='public' AND rel.relname='scan_events' AND con.contype='f'
ORDER BY con.conname;

\echo === E. physical_codes.code_value do dai that ===
SELECT table_name, column_name, data_type, character_maximum_length
FROM information_schema.columns
WHERE table_schema='public' AND column_name='code_value' AND table_name IN ('physical_codes','physical_code','scan_events')
ORDER BY table_name;

\echo === F. label_snapshots: 01 nhan cho moi phieu xuat ===
SELECT con.conname, pg_get_constraintdef(con.oid) AS definition
FROM pg_constraint con
JOIN pg_class rel ON rel.oid=con.conrelid
JOIN pg_namespace n ON n.oid=rel.relnamespace
WHERE n.nspname='public' AND rel.relname='label_snapshots' AND con.contype IN ('u','c')
ORDER BY con.conname;

\echo === G. Trigger dang suy luan scan_source ===
SELECT tgname FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
WHERE c.relname IN ('scan_event','scan_events') AND NOT t.tgisinternal ORDER BY tgname;

\echo === H. Tong so migration da chay ===
SELECT count(*) AS migrations_applied FROM migrations;
