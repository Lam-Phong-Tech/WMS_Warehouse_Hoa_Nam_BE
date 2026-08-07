#!/usr/bin/env python3
"""Fail-closed repository verifier for WWHN-128 DEC-INV-001 R8."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
REVISION = "1.8-R8"
CONTRACT_VERSION = "1.7.0"
PATHS = {
    "task": ROOT / "docs/docs_task_jira/WWHN-128.md",
    "source": ROOT / "docs/docs_task_jira/WWHN-128-source-register.json",
    "closure": ROOT / "docs/evidence/WWHN-128/INV_P1_P2_CLOSURE_REGISTER.json",
    "downstream": ROOT / "docs/evidence/WWHN-128/DOWNSTREAM_CONTRACT_GATE.json",
    "ledger": ROOT / "docs/evidence/WWHN-128/G1_SIGNOFF_LEDGER.json",
    "qa2": ROOT / "docs/evidence/WWHN-128/QA2_EXECUTION_MATRIX.json",
    "protocol": ROOT / "docs/evidence/WWHN-128/REVIEW_AND_APPROVAL_PROTOCOL.md",
    "minutes": ROOT / "docs/evidence/WWHN-128/G1_MINUTES_TEMPLATE.md",
    "workflow": ROOT / ".github/workflows/wwhn-128-inventory-decision-contract.yml",
}
FINDINGS = {"BE1-P0-01", "BE1-P0-02", "BE1-P1-01", "BE1-P1-02", "BE1-P1-03", "BE1-P1-04", "BE1-P1-05", "BE1-P2-01"}
GATES = {"WWHN-181", "WWHN-182", "WWHN-183", "WWHN-40"}

class VerificationError(RuntimeError):
    pass

def require(condition: bool, message: str) -> None:
    if not condition:
        raise VerificationError(message)

def load_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise VerificationError(f"Invalid JSON {path.relative_to(ROOT)}: {exc}") from exc
    require(isinstance(value, dict), f"JSON root must be object: {path.relative_to(ROOT)}")
    return value

def verify_version(document: dict[str, Any], name: str) -> None:
    require(document.get("document_revision") == REVISION, f"{name} revision drift")
    require(document.get("contract_version") == CONTRACT_VERSION, f"{name} contract version drift")

def verify() -> tuple[dict[str, Any], dict[str, Any]]:
    for name, path in PATHS.items():
        require(path.is_file(), f"Missing {name}: {path.relative_to(ROOT)}")

    source = load_json(PATHS["source"])
    closure = load_json(PATHS["closure"])
    gate = load_json(PATHS["downstream"])
    ledger = load_json(PATHS["ledger"])
    qa2 = load_json(PATHS["qa2"])
    for name, doc in (("source", source), ("closure", closure), ("downstream", gate), ("ledger", ledger), ("qa2", qa2)):
        verify_version(doc, name)

    require(source.get("status") == "REPO_R8_CONTROLS_COMPLETE_EXTERNAL_G1_PENDING", "source status must remain fail-closed")
    invariants = source.get("version_invariants", {})
    for key in ("task_markdown_revision", "source_register_revision", "closure_register_revision", "downstream_gate_revision", "signoff_ledger_revision", "qa2_matrix_revision", "workflow_revision"):
        require(invariants.get(key) == REVISION, f"version invariant drift: {key}")
    require(invariants.get("canonical_contract_version") == CONTRACT_VERSION, "canonical contract invariant drift")
    fe = source.get("frontend_binding", {})
    require(re.fullmatch(r"[0-9a-f]{40}", str(fe.get("observed_commit", ""))) is not None, "invalid FE commit")
    require(re.fullmatch(r"[0-9a-f]{40}", str(fe.get("baseline_git_blob_sha", ""))) is not None, "invalid FE baseline blob")

    ids = {row.get("id") for row in closure.get("findings", [])}
    require(ids == FINDINGS, f"finding set mismatch: {ids ^ FINDINGS}")
    for row in closure["findings"]:
        require(row.get("severity") in {"P0", "P1", "P2"}, f"invalid severity: {row.get('id')}")
        require(str(row.get("repo_status", "")).startswith(("REPO_CONTROL_COMPLETE", "CLOSED")), f"repo control incomplete: {row.get('id')}")
        require(bool(row.get("formal_closure_evidence")), f"missing closure evidence: {row.get('id')}")
    policy = closure.get("claim_policy", {})
    require(policy.get("formal_closure") == "NOT_CLAIMED", "formal closure must remain NOT_CLAIMED")
    require(policy.get("approved") is False and policy.get("qa2_pass") is False and policy.get("g1_go") is False, "false approval claim")
    require(set(closure.get("role_owned_gates", {})) == GATES, "closure gate set mismatch")

    require(gate.get("gate_status") == "FAIL_CLOSED_PENDING_WWHN_181_182_183_AND_WWHN_40", "downstream gate must remain fail-closed")
    require(set(gate.get("jira_gates", {})) == GATES, "downstream Jira gate set mismatch")
    for item in gate.get("repository_contract_sources", []):
        require((ROOT / item["path"]).is_file(), f"missing contract source: {item['path']}")
        sha = item.get("git_blob_sha_at_observation")
        if sha is not None:
            require(re.fullmatch(r"[0-9a-f]{40}", sha) is not None, f"invalid blob: {item['path']}")

    require(ledger.get("state") == "FAIL_CLOSED_PENDING_ROLE_OWNED_SIGNATURES", "ledger state mismatch")
    signatures = ledger.get("signatures", {})
    require(set(signatures) == {"FE1", "QA2", "BA"}, "signature role set mismatch")
    for role, record in signatures.items():
        require(record.get("status") == "PENDING_ROLE_SIGNATURE", f"{role} must remain pending")
        require(record.get("reviewer_account") is None and record.get("result") is None, f"fabricated {role} signature")
    anti = ledger.get("anti_impersonation", {})
    require(anti.get("BE1_may_sign_for_FE1_QA2_BA") is False and anti.get("CI_may_replace_role_signature") is False, "anti-impersonation disabled")

    require(qa2.get("execution_status") == "NOT_EXECUTED_BY_QA2", "QA2 status pre-claimed")
    cases = qa2.get("cases", [])
    require(len(cases) >= 16 and all(case.get("result") == "NOT_RUN" for case in cases), "QA2 matrix incomplete or fabricated")
    areas = {case.get("area") for case in cases}
    required = {"artifact-integrity", "happy-stock-effect", "idempotency", "double-post", "race", "rollback", "reconciliation", "rbac", "data-scope", "db-before-after"}
    require(required.issubset(areas), f"QA2 coverage missing: {required - areas}")

    task = PATHS["task"].read_text(encoding="utf-8")
    workflow = PATHS["workflow"].read_text(encoding="utf-8")
    for marker in (REVISION, CONTRACT_VERSION, "BE1-P0-01", "BE1-P0-02", "WWHN-181", "WWHN-182", "WWHN-183", "WWHN-40", "NOT APPROVED / NOT BASELINED / NOT EFFECTIVE"):
        require(marker in task, f"task marker missing: {marker}")
    for marker in (REVISION, CONTRACT_VERSION, "postgres:16", "newman.raw.log", "postgresql/junit.xml", "G1_SIGNOFF_LEDGER.json", "QA2_EXECUTION_MATRIX.json", "SHA256SUMS.sha256"):
        require(marker in workflow, f"workflow marker missing: {marker}")
    return source, closure

def main() -> int:
    try:
        source, closure = verify()
    except VerificationError as exc:
        print(f"WWHN-128 R8 verification FAILED: {exc}", file=sys.stderr)
        return 1
    print(json.dumps({
        "ticket": "WWHN-128",
        "decision_id": "DEC-INV-001",
        "document_revision": REVISION,
        "contract_version": CONTRACT_VERSION,
        "repo_controlled_findings_complete": True,
        "formal_closure": closure["claim_policy"]["formal_closure"],
        "external_gates": source["g1"],
        "status": "PASS"
    }, ensure_ascii=False, indent=2))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
