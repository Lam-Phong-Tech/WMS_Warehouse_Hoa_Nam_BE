#!/usr/bin/env python3
"""Fail-closed verifier for WWHN-128 DEC-INV-001 R7 governance evidence.

The verifier proves repository-side completeness and integrity only. It must not
convert pending BA/BAS/Tech Lead/QA/PM decisions into acceptance.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
TASK = ROOT / "docs/docs_task_jira/WWHN-128.md"
SOURCE = ROOT / "docs/docs_task_jira/WWHN-128-source-register.json"
PROTOCOL = ROOT / "docs/evidence/WWHN-128/REVIEW_AND_APPROVAL_PROTOCOL.md"
CLOSURE = ROOT / "docs/evidence/WWHN-128/INV_P1_P2_CLOSURE_REGISTER.json"
DOWNSTREAM = ROOT / "docs/evidence/WWHN-128/DOWNSTREAM_CONTRACT_GATE.json"
WORKFLOW = ROOT / ".github/workflows/wwhn-128-inventory-decision-contract.yml"

EXPECTED_FINDINGS = {
    "INV-P1-01", "INV-P1-02", "INV-P1-03", "INV-P1-04",
    "INV-P1-05", "INV-P1-06", "INV-P1-07", "INV-P1-08",
    "INV-P2-01",
}
PENDING_ROLE_STATES = {
    "PENDING_INDEPENDENT_DISPOSITION",
    "PENDING_EXACT_SHA_ARTIFACT_REVIEW",
    "PENDING_FORMAL_DECISION",
}

class VerificationError(RuntimeError):
    pass

def require(condition: bool, message: str) -> None:
    if not condition:
        raise VerificationError(message)

def load_json(path: Path) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise VerificationError(f"Invalid JSON {path.relative_to(ROOT)}: {exc}") from exc

def verify_files_exist() -> None:
    for path in (TASK, SOURCE, PROTOCOL, CLOSURE, DOWNSTREAM, WORKFLOW):
        require(path.is_file(), f"Missing required file: {path.relative_to(ROOT)}")

def verify_closure() -> dict[str, Any]:
    closure = load_json(CLOSURE)
    require(closure.get("ticket") == "WWHN-128", "Closure ticket mismatch")
    require(closure.get("decision_id") == "DEC-INV-001", "Closure decision mismatch")
    require(closure.get("document_revision") == "1.7-R7", "Closure revision mismatch")
    findings = closure.get("findings")
    require(isinstance(findings, list), "Closure findings must be a list")
    ids = {row.get("id") for row in findings}
    require(ids == EXPECTED_FINDINGS, f"Finding set mismatch: {ids ^ EXPECTED_FINDINGS}")
    for row in findings:
        require(row.get("severity") in {"P1", "P2"}, f"Unsupported severity for {row.get('id')}")
        require(row.get("repo_status") in {"COMPLETE", "CLOSED"}, f"Repo control incomplete for {row.get('id')}")
        require(bool(row.get("closure_evidence")), f"Missing closure evidence for {row.get('id')}")

    comments = closure.get("canonical_jira_comments", {})
    require(comments.get("primary_closure_text") == [14742, 14761], "Canonical closure comments changed")
    require(comments.get("excluded_as_evidence") == [14762], "Blob-only exclusion changed")

    artifacts = closure.get("current_jira_artifacts", [])
    require([a.get("attachment_id") for a in artifacts] == [11647, 11646], "Current Jira attachment IDs mismatch")
    require(all(a.get("exact_jira_bytes_verified") is False for a in artifacts),
            "Exact Jira-byte verification must remain false until independently verified")

    ci = closure.get("ci_truth", {})
    require(ci.get("r6_run_id") == 31082380025, "R6 run ID mismatch")
    require(ci.get("latest_attempt") == 2, "R6 attempt mismatch")
    require(ci.get("latest_job_id") == 92570314627, "R6 attempt-2 job mismatch")
    require(ci.get("runner_allocated") is False and ci.get("steps_started") is False,
            "Pre-runner billing blocker truth changed")
    require(ci.get("artifact_count") == 0, "R6 must not claim an artifact that does not exist")
    require(ci.get("code_or_test_failure_claimed") is False, "Billing block must not be called a code failure")

    policy = closure.get("claim_policy", {})
    for field in ("approved", "baselined", "effective", "qa_pass"):
        require(policy.get(field) is False, f"False closure claim: {field}")
    require(policy.get("formal_closure") == "NOT_CLAIMED", "Formal closure must remain NOT_CLAIMED")

    roles = closure.get("independent_roles", {})
    require(set(roles) == {"BA", "BAS", "TECH_LEAD", "QA1", "PM_APPROVER"}, "Independent role set mismatch")
    require(all(role.get("status") in PENDING_ROLE_STATES for role in roles.values()),
            "Independent roles must remain pending until their own Jira dispositions")
    return closure

def verify_source_register() -> None:
    source = load_json(SOURCE)
    require(source.get("ticket") == "WWHN-128", "Source register ticket mismatch")
    require(source.get("document_revision") == "1.7-R7", "Source register revision mismatch")
    require(source.get("status") == "REPO_CONTROLS_COMPLETE_EXTERNAL_GATES_PENDING",
            "Source register status must remain fail-closed")
    current = source.get("current_jira_artifacts", [])
    require([a.get("attachment_id") for a in current] == [11647, 11646],
            "Source register current Jira attachment IDs mismatch")
    r6 = source.get("r6_ci_observation", {})
    require(r6.get("run_id") == 31082380025 and r6.get("artifact_count") == 0,
            "Source register R6 CI truth mismatch")
    require(r6.get("classification") == "BLOCKED_BEFORE_RUNNER_BY_BILLING_OR_SPENDING_LIMIT",
            "R6 blocker classification mismatch")

def verify_downstream() -> None:
    gate = load_json(DOWNSTREAM)
    require(gate.get("ticket") == "WWHN-128", "Downstream ticket mismatch")
    require(gate.get("gate_status") == "FAIL_CLOSED_PENDING_INDEPENDENT_DISPOSITIONS",
            "Downstream gate must remain fail-closed")
    sources = gate.get("repository_contract_sources", [])
    require(len(sources) >= 6, "Downstream contract source list incomplete")
    for item in sources:
        path = ROOT / item["path"]
        require(path.is_file(), f"Missing downstream artifact: {item['path']}")
        expected_blob = item.get("git_blob_sha_at_base")
        if expected_blob is not None:
            require(re.fullmatch(r"[0-9a-f]{40}", expected_blob) is not None,
                    f"Invalid Git blob SHA for {item['path']}")
    jira = gate.get("jira_gates", {})
    require(set(jira) == {"WWHN-31", "WWHN-40", "WWHN-180"}, "Downstream Jira gate set mismatch")
    require(all(v.get("status_observed") == "IN_PROGRESS" for v in jira.values()),
            "A downstream Jira status changed without updating the gate")

def verify_governance_text() -> None:
    task = TASK.read_text(encoding="utf-8")
    protocol = PROTOCOL.read_text(encoding="utf-8")
    workflow = WORKFLOW.read_text(encoding="utf-8")
    for marker in (
        "1.7-R7", "INV-P1-01", "INV-P1-08", "INV-P2-01",
        "11647", "11646", "31082380025", "92570314627",
        "14742", "14761", "14762",
        "NOT APPROVED / NOT BASELINED / NOT EFFECTIVE",
    ):
        require(marker in task, f"Task marker missing: {marker}")
    for marker in (
        "INV-P1-01..08", "INV-P2-01", "11647", "11646",
        "APPROVED WITH CONDITIONS", "ACCEPTED WITH CONDITIONS",
    ):
        require(marker in protocol, f"Protocol marker missing: {marker}")
    for marker in (
        "scripts/verify_wwhn128_closure.py",
        "INV_P1_P2_CLOSURE_REGISTER.json",
        "DOWNSTREAM_CONTRACT_GATE.json",
        '"document_revision": "1.7-R7"',
    ):
        require(marker in workflow, f"Workflow marker missing: {marker}")

def main() -> int:
    try:
        verify_files_exist()
        closure = verify_closure()
        verify_source_register()
        verify_downstream()
        verify_governance_text()
    except VerificationError as exc:
        print(f"WWHN-128 closure verification FAILED: {exc}", file=sys.stderr)
        return 1

    result = {
        "ticket": "WWHN-128",
        "decision_id": "DEC-INV-001",
        "revision": "1.7-R7",
        "finding_count": len(closure["findings"]),
        "repo_controlled_findings_complete": True,
        "formal_closure": "NOT_CLAIMED",
        "status": "PASS",
    }
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
