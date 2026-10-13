"""Strict evidence for an isolated run; never labels a deployment or hardware PASS."""
import hashlib
import json
import os
import sys
from pathlib import Path
import xml.etree.ElementTree as ET

suite = sys.argv[1]
assert suite in ("lifecycle", "cached")
path = Path("p14-results") / (suite + ".xml")
root = ET.parse(path).getroot()
cases = list(root.iter("testcase"))
expected = set(path.with_name(suite + "-expected.txt").read_text().splitlines())
seen = {c.get("class", c.get("classname", "")).split("\\")[-1] for c in cases}
counts = {"tests": len(cases), "assertions": sum(int(c.get("assertions", "0")) for c in cases),
          **{k: len(list(root.iter(k))) for k in ("failure", "error", "skipped")}}
report = {"sha": os.environ["P14_SOURCE_SHA"], "database": os.environ["DB_CONNECTION"],
          "suite": suite, "counts": counts, "missing_classes": sorted(expected - seen),
          "junit_sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
          "network": "ISOLATED_PG_LOOPBACK_ONLY", "live_verified": False, "deployment": False}
print("P14_ISOLATED_EVIDENCE=" + json.dumps(report, sort_keys=True))
path.with_suffix(".json").write_text(json.dumps(report, indent=2) + "\n")
for case in cases:
    for child in case:
        if child.tag in ("failure", "error"):
            print("FAILED_CASE", case.get("name"), (child.text or "")[:3500])
if not cases or expected - seen or any(counts[k] for k in ("failure", "error", "skipped")):
    raise SystemExit("Missing or unsuccessful source evidence")
