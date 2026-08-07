# WWHN-134 exact-SHA evidence and closure protocol

## Release under review

- Contract: `DEC-REV-001`
- Version: `1.3.0-proposed`
- Immutable path: `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/`
- Manifest SHA-256: `bd0e356e6301c4e7e142b57108c69684c2624a64c0ea2349a2be4b046472cf95`
- Checksum file SHA-256: `26e39d4de842f589fdecf797388fc819975f0cdfba18ae764568e01228b55c4f`
- Prior proposal commit: `f9c8c5ca74017c4269cf503bd7d6222b5ad3cc9c`
- Observed parent commit: `7d7091c48798114b5bbf7ff4c0b58617d177c737`

The exact release commit is the single commit that introduces this package. Record its full 40-character SHA in Jira after commit creation.

## Automated evidence

Workflow: `.github/workflows/wwhn-134-reversal-decision-contract.yml`

The workflow must:

1. check out the exact commit;
2. validate JSON and OpenAPI structural markers;
3. verify every static SHA-256;
4. verify canonical aliases are byte-identical to immutable artifacts;
5. collect exact commit metadata, diff/stat and source-tree digest;
6. upload `wwhn-134-contract-evidence-<exact-sha>`.

CI success proves artifact integrity only. It does not provide BA, Tech Lead, QA, FE, BAS or PM/PO acceptance.

## Finding closure map

| Finding | BE-controlled evidence | Remaining independent action |
|---|---|---|
| BE1-P1-01 | immutable release, manifest, exact-hash workflow and review template | Tech Lead/QA/FE disposition on exact SHA/hash |
| BE1-P1-02 | formal PM/PO template and fail-closed status | PM/PO outcome on WWHN-26 |
| BE1-P1-03 | traceability matrix/source register and exact comments to pin | BAS records `TRACEABILITY-PINNED` |
| BE1-P1-04 | versioned OpenAPI/Postman/schema, changelog, manifest and role separation | Tech Lead records `PUBLISHED-VERIFIED` |
| BE1-P1-05 | GitHub-verifiable commit, diff, static checksums and workflow artifact | Reviewer verifies exact artifact or Jira attachment |
| BE1-P2-01 | explicit runtime-evidence boundary and route/permission divergence | implementation/QA only when separate scope is delivered |
| BE1-P2-02 | complete sign-off ledger and allowed result vocabulary | independent reviewers fill their own rows |

## Prohibited closure shortcuts

- abbreviated SHA;
- moving branch URL as evidence;
- BE1 or CI signing for an independent role;
- claiming `/api/v1/reversals` is deployed from proposal documents;
- treating Postman contract cases as executed test evidence;
- changing `PROPOSED` to `APPROVED` without WWHN-26 formal outcome.
