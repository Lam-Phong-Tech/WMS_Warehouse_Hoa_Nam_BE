import hashlib
import json
import os
from pathlib import Path
import xml.etree.ElementTree as ET

suite = os.environ['TIMESTAMP_SUITE']
path = Path('timestamp-results') / (suite + '.xml')
if not path.is_file():
    raise SystemExit('Missing JUnit: ' + suite)
root = ET.parse(path).getroot()
cases = list(root.iter('testcase'))
counts = {'tests': len(cases), 'assertions': sum(int(c.get('assertions', '0')) for c in cases)}
counts.update({key: len(list(root.iter(key))) for key in ['failure', 'error', 'skipped']})
expected = [Path(line).stem for line in path.with_name(suite + '-expected.txt').read_text().splitlines()]
classes = ' '.join(c.get('class', '') + ' ' + c.get('classname', '') for c in cases)
missing = [name for name in expected if name not in classes]
report = {'sha': os.environ['TIMESTAMP_SOURCE_SHA'], 'database': os.environ['DB_CONNECTION'],
          'suite': suite, 'legacy_storage': os.environ['TIMESTAMP_STORAGE'], 'counts': counts,
          'missing_classes': missing, 'junit_sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
          'live_http_pairs': False, 'deployment': False}
path.with_suffix('.json').write_text(json.dumps(report, indent=2) + '\n')
print('TIMESTAMP_TEST_EVIDENCE=' + json.dumps(report, sort_keys=True))
for c in cases:
    for failure in c:
        if failure.tag in ('failure', 'error'):
            print(c.get('name', '') + '\n' + (failure.text or '')[:3500])
if not cases or missing or any(counts[k] for k in ['failure', 'error', 'skipped']):
    raise SystemExit('Incomplete or failed timestamp evidence')
