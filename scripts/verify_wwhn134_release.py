#!/usr/bin/env python3
"""Verify the immutable WWHN-134 DEC-REV-001 proposal release.

This verifier intentionally checks artifact integrity and governance only.
It does not claim runtime implementation, deployment or independent acceptance.
"""

from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
RELEASE_VERSION = "1.3.0-proposed"
RELEASE_DIR = ROOT / "docs/api-contract/releases/DEC-REV-001/1.3.0-proposed"
MANIFEST_PATH = RELEASE_DIR / "RELEASE_MANIFEST.json"
CHECKSUM_PATH = RELEASE_DIR / "SHA256SUMS"
SOURCE_REGISTER_PATH = ROOT / "docs/docs_task_jira/WWHN-134-source-register.json"
OPENAPI_ALIAS = ROOT / "docs/api-contract/WWHN-134-reversal-openapi.yaml"
POSTMAN_ALIAS = ROOT / "docs/postman/WWHN-134-reversal.postman_collection.json"
DECISION_RECORD = ROOT / "docs/decision-records/DEC-REV-001.md"
TASK_RECORD = ROOT / "docs/docs_task_jira/WWHN-134.md"


class VerificationError(RuntimeError):
    pass


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def load_json(path: Path) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise VerificationError(f"Invalid JSON {path.relative_to(ROOT)}: {exc}") from exc


def require(condition: bool, message: str) -> None:
    if not condition:
        raise VerificationError(message)


def parse_checksum_file(path: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    for line_number, raw_line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        line = raw_line.strip()
        if not line:
            continue
        match = re.fullmatch(r"([0-9a-f]{64})\s{2}(.+)", line)
        require(match is not None, f"Malformed checksum line {line_number}: {raw_line!r}")
        digest, relative_name = match.groups()
        require(relative_name not in result, f"Duplicate checksum entry: {relative_name}")
        result[relative_name] = digest
    return result


def verify_manifest() -> tuple[dict[str, Any], dict[str, str]]:
    manifest = load_json(MANIFEST_PATH)
    require(manifest.get("contract_id") == "DEC-REV-001", "Manifest contract_id mismatch")
    require(manifest.get("ticket") == "WWHN-134", "Manifest ticket mismatch")
    require(manifest.get("release_version") == RELEASE_VERSION, "Manifest version mismatch")
    require(manifest.get("release_status") == "PROPOSED", "Manifest must remain PROPOSED")
    require(manifest.get("implementation_status") == "NOT_CLAIMED", "Implementation must remain NOT_CLAIMED")
    require(
        manifest.get("runtime_divergence", {}).get("disposition")
        == "FAIL_CLOSED_SEPARATE_IMPLEMENTATION_REQUIRED",
        "Runtime divergence must remain fail-closed",
    )
    roles = manifest.get("publishers_and_reviewers", {})
    require(roles.get("BE1_AUTHOR", {}).get("status") == "PROPOSED", "BE1 author state mismatch")
    for role in (
        "TECH_LEAD_INDEPENDENT_PUBLISHER",
        "QA_TESTABILITY",
        "FE_CONSUMER",
        "BAS_TRACEABILITY",
        "PM_PO_DECISION",
    ):
        require(roles.get(role, {}).get("status") == "PENDING", f"{role} must remain independently pending")

    artifacts = manifest.get("artifacts")
    require(isinstance(artifacts, list) and len(artifacts) >= 6, "Manifest artifact list is incomplete")
    manifest_hashes: dict[str, str] = {}
    for artifact in artifacts:
        relative_name = artifact.get("relative_path")
        expected_hash = artifact.get("sha256")
        require(isinstance(relative_name, str) and relative_name, "Artifact relative_path missing")
        require(isinstance(expected_hash, str) and re.fullmatch(r"[0-9a-f]{64}", expected_hash) is not None,
                f"Artifact hash invalid for {relative_name}")
        artifact_path = RELEASE_DIR / relative_name
        require(artifact_path.is_file(), f"Missing artifact: {artifact_path.relative_to(ROOT)}")
        actual_hash = sha256(artifact_path)
        require(actual_hash == expected_hash, f"SHA-256 mismatch for {relative_name}")
        manifest_hashes[relative_name] = expected_hash

    checksums = parse_checksum_file(CHECKSUM_PATH)
    expected_checksum_names = set(manifest_hashes) | {"RELEASE_MANIFEST.json"}
    require(set(checksums) == expected_checksum_names, "SHA256SUMS entries do not match manifest payload")
    for name, expected_hash in checksums.items():
        actual_hash = sha256(RELEASE_DIR / name)
        require(actual_hash == expected_hash, f"SHA256SUMS mismatch for {name}")
    return manifest, manifest_hashes


def verify_aliases(manifest_hashes: dict[str, str]) -> None:
    immutable_openapi = RELEASE_DIR / "openapi.yaml"
    immutable_postman = RELEASE_DIR / "postman_collection.json"
    require(OPENAPI_ALIAS.read_bytes() == immutable_openapi.read_bytes(), "OpenAPI alias differs from immutable release")
    require(POSTMAN_ALIAS.read_bytes() == immutable_postman.read_bytes(), "Postman alias differs from immutable release")
    require(sha256(OPENAPI_ALIAS) == manifest_hashes["openapi.yaml"], "OpenAPI alias hash mismatch")
    require(sha256(POSTMAN_ALIAS) == manifest_hashes["postman_collection.json"], "Postman alias hash mismatch")


def verify_contract_structure() -> None:
    openapi_text = (RELEASE_DIR / "openapi.yaml").read_text(encoding="utf-8")
    required_markers = (
        "openapi: 3.0.3",
        "version: 1.3.0-proposed",
        "x-contract-status: PROPOSED",
        "x-implementation-status: NOT_CLAIMED",
        "  /reversals:",
        "x-permission: stock.reversal.create",
        "Idempotency-Key",
        "IDEMPOTENCY_ERROR",
        "CONCURRENT_MODIFICATION",
        "IDEMPOTENCY_KEY_REQUIRED",
        "stock_document.reverse",
        "Fail closed",
    )
    for marker in required_markers:
        require(marker in openapi_text, f"OpenAPI marker missing: {marker}")

    schema = load_json(RELEASE_DIR / "reversal.schema.json")
    require(schema.get("x-version") == RELEASE_VERSION, "Standalone schema version mismatch")
    defs = schema.get("$defs", {})
    for name in ("CreateReversalLine", "CreateReversalRequest", "ReversalResponse", "ErrorResponse"):
        require(name in defs, f"Schema definition missing: {name}")

    postman = load_json(RELEASE_DIR / "postman_collection.json")
    info = postman.get("info", {})
    require(info.get("version") == {"major": 1, "minor": 3, "patch": 0}, "Postman version mismatch")
    items = postman.get("item", [])
    require(isinstance(items, list) and len(items) >= 8, "Postman contract cases are incomplete")
    names = "\n".join(str(item.get("name", "")) for item in items)
    for expected_case in ("first write 201", "replay 200", "different payload - 409", "Missing Idempotency-Key - 428",
                          "Unauthenticated - 401", "permission or warehouse scope - 403"):
        require(expected_case in names, f"Postman case missing: {expected_case}")


def verify_source_register(manifest: dict[str, Any]) -> None:
    register = load_json(SOURCE_REGISTER_PATH)
    require(register.get("release_version") == RELEASE_VERSION, "Source register version mismatch")
    require(register.get("be_controlled_completeness") == "COMPLETE", "BE-controlled completeness mismatch")
    require(register.get("overall_closure") == "PENDING_EXTERNAL_INDEPENDENT_ACTIONS",
            "Overall closure must remain externally pending")
    require(register.get("manifest", {}).get("sha256") == sha256(MANIFEST_PATH), "Source register manifest hash mismatch")
    require(register.get("checksum_file", {}).get("sha256") == sha256(CHECKSUM_PATH),
            "Source register checksum-file hash mismatch")
    require(register.get("runtime_divergence") == manifest.get("runtime_divergence"),
            "Runtime divergence differs between manifest and source register")


def verify_governance_text() -> None:
    decision = DECISION_RECORD.read_text(encoding="utf-8")
    task = TASK_RECORD.read_text(encoding="utf-8")
    for marker in (
        "PROPOSED — PENDING INDEPENDENT SIGN-OFF",
        "Runtime divergence and adoption gate",
        "BE1 and CI must not sign for independent reviewers",
        "Actual PHPUnit/Newman/API/DB/race/reconciliation evidence",
    ):
        require(marker in decision, f"Decision Record governance marker missing: {marker}")
    for marker in (
        "BE1-controlled scope is complete",
        "overall ticket/decision must remain pending",
        "PUBLISHED-VERIFIED",
        "TRACEABILITY-PINNED",
    ):
        require(marker in task, f"Task record governance marker missing: {marker}")


def verify_secret_hygiene() -> None:
    forbidden_patterns = (
        r"-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----",
        r"\bAKIA[0-9A-Z]{16}\b",
        r"gh[pousr]_[A-Za-z0-9]{20,}",
        r"(?i)\b(password|secret|token)\s*[:=]\s*[\"'][^\"']{8,}[\"']",
    )
    for path in RELEASE_DIR.iterdir():
        if not path.is_file():
            continue
        text = path.read_text(encoding="utf-8")
        for pattern in forbidden_patterns:
            require(re.search(pattern, text) is None, f"Potential secret in {path.relative_to(ROOT)}")


def main() -> int:
    try:
        manifest, manifest_hashes = verify_manifest()
        verify_aliases(manifest_hashes)
        verify_contract_structure()
        verify_source_register(manifest)
        verify_governance_text()
        verify_secret_hygiene()
    except VerificationError as exc:
        print(f"WWHN-134 release verification FAILED: {exc}", file=sys.stderr)
        return 1

    result = {
        "ticket": "WWHN-134",
        "contract_id": "DEC-REV-001",
        "release_version": RELEASE_VERSION,
        "release_status": "PROPOSED",
        "implementation_status": "NOT_CLAIMED",
        "manifest_sha256": sha256(MANIFEST_PATH),
        "checksum_file_sha256": sha256(CHECKSUM_PATH),
        "artifact_count": len(manifest_hashes),
        "status": "PASS",
    }
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
