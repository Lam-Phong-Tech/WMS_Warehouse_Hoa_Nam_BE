# DEC-REV-001 — Review and approval protocol

## 1. Scope

This protocol applies only to immutable release `1.3.0-proposed` under:

`docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/`

Reviewers must use the exact commit containing the release and verify the file SHA-256 values from `RELEASE_MANIFEST.json` and `SHA256SUMS`.

## 2. Independent roles

| Role | Required outcome | Jira target | May be self-signed by BE1 |
|---|---|---|---|
| BA | `REVIEWED-SUFFICIENT` or `CHANGES REQUIRED` | WWHN-134 | No |
| Tech Lead / independent technical publisher | `PUBLISHED-VERIFIED` or `CHANGES REQUIRED` | WWHN-134 and WWHN-31 | No |
| QA | `REVIEWED-SUFFICIENT` or `CHANGES REQUIRED` | WWHN-134 and WWHN-33 | No |
| FE consumer | `REVIEWED-SUFFICIENT` or `CHANGES REQUIRED` | WWHN-134 and WWHN-40 | No |
| BAS | `TRACEABILITY-PINNED` or `CHANGES REQUIRED` | WWHN-136 | No |
| PM/PO Decision Owner | `APPROVED`, `REJECTED` or `CHANGES REQUIRED` | WWHN-26 | No |

One person/account must not provide multiple supposedly independent signatures unless Jira governance explicitly authorizes the combination and records the exception.

## 3. Mandatory review comment fields

Every disposition must include:

- role and reviewer account/display name;
- exact 40-character commit SHA;
- release version `1.3.0-proposed`;
- immutable artifact path;
- SHA-256 of `RELEASE_MANIFEST.json`;
- review date in `YYYY-MM-DD`;
- one allowed result;
- scope reviewed and any conditions;
- evidence URL or attachment ID.

A branch URL, local path, abbreviated SHA or BE self-review is insufficient.

## 4. Comment templates

### BA / Tech Lead / QA / FE

```text
[DEC-REV-001 1.3.0-proposed INDEPENDENT REVIEW]
Role:
Reviewer:
Exact commit SHA:
Immutable release path: docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/
RELEASE_MANIFEST.json SHA-256:
Date:
Result: REVIEWED-SUFFICIENT | PUBLISHED-VERIFIED | CHANGES REQUIRED
Scope reviewed:
Conditions/findings:
Evidence URL or attachment ID:
```

### BAS traceability

```text
[DEC-REV-001 1.3.0-proposed TRACEABILITY]
Reviewer:
Exact commit SHA:
RELEASE_MANIFEST.json SHA-256:
Date:
Result: TRACEABILITY-PINNED | CHANGES REQUIRED
Pinned tickets: WWHN-17, WWHN-31, WWHN-33, WWHN-35, WWHN-40
Baseline: BRD v3.11, SRS/FRD v1.4, BPD v1.3
Superseded: BRD/Handoff v3.9
Evidence/comment IDs:
```

### PM/PO formal decision

```text
[DEC-REV-001 FORMAL OUTCOME]
Decision Owner:
Exact commit SHA:
Release version: 1.3.0-proposed
RELEASE_MANIFEST.json SHA-256:
Decision date:
Outcome: APPROVED | REJECTED | CHANGES REQUIRED
Effective scope/date:
Conditions:
Evidence URL or attachment ID:
```

## 5. Closure policy

BE-controlled documentation and verification can be complete while the overall decision remains pending. WWHN-134 and WWHN-26 must not be presented as fully accepted until all mandatory independent dispositions are recorded on the exact artifact.
