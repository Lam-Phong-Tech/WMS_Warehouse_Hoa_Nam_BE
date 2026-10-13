"""Reject missing classes, skips and failed cases; never substitute old CI evidence."""
import hashlib
import json
import os
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

suite = sys.argv[1]
assert suite in {"core", "extra", "cached", "race"}
path = Path("backlog-results") / (suite + ".xml")
root = ET.parse(path).getroot()
cases = list(root.iter("testcase"))
expected = {Path(p).stem for p in path.with_name(suite + "-expected.txt").read_text().splitlines()}
observed = {c.get("class", c.get("classname", "")).split("\\")[-1] for c in cases}
counts = {"tests": len(cases), "assertions": sum(int(c.get("assertions", "0")) for c in cases)}
counts.update({key: len(list(root.iter(key))) for key in ["failure", "error", "skipped"]})
missing = sorted(expected - observed)
ok = bool(cases) and not missing and not any(counts[k] for k in ["failure", "error", "skipped"])
if suite in {"core", "cached"}:
    required = "test_record_handoff_on_real_warranty_route_is_atomic_and_cannot_be_cancelled_or_repeated"
    ok = ok and len(cases) >= 191 and required in {c.get("name") for c in cases}
report = {
    "sha": os.environ["BACKLOG_SOURCE_SHA"], "database": os.environ["DB_CONNECTION"],
    "suite": suite, "counts": counts, "missing_classes": missing, "passed": bool(ok),
    "junit_sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
    "live_verified": False, "production_activation": False,
}
path.with_suffix(".json").write_text(json.dumps(report, indent=2) + "\n")
print(json.dumps(report, sort_keys=True))
for case in cases:
    for child in case:
        if child.tag in {"failure", "error"}:
            print("FAILED_CASE", case.get("name", ""), (child.text or "")[:3000])
if not ok:
    raise SystemExit("Missing or unsuccessful exact-source integration evidence")
