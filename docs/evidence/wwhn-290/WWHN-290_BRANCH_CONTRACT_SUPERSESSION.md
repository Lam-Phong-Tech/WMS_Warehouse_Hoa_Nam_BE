# WWHN-290 — Branch and contract supersession record

- Date: 2026-08-18
- Jira: `WWHN-290` — `[BE] Khắc phục lỗi dữ liệu/báo cáo/tính năng` (parent `WWHN-77`)
- Closes review items: `DEV290-P1-02`, `DEV290-P1-03`, `DEV290-P2-01`
- Machine-readable pins: `docs/evidence/wwhn-290/WWHN-290_PINS.json`
- Format follows the SRS v1.12 §1.6 *Supersession Register* convention (old source → new controlling source → status).

## 1. The reported conflict does not exist — it is a fast-forward lineage

Review item `DEV290-P1-02` records a **SOURCE CONFLICT**: the project pins BE2 canonical branch `m1-be-2`,
while the WWHN-290 closure package declares `m1` only, without a formal branch supersession. The reviewer
asked for either (a) porting/merging the closure source into `m1-be-2`, or (b) a formal statement that `m1`
is canonical.

Measured against the repository, the two branches are **not divergent**:

| Measurement | Command | Result |
|---|---|---|
| Ancestry | `git merge-base --is-ancestor origin/m1-be-2 m1` | exit `0` → **`m1-be-2` is a strict ancestor of `m1`** |
| Merge base | `git merge-base m1 origin/m1-be-2` | `60816499066f9f493596e326dc41116ef32f5c4b` |
| `m1-be-2` HEAD | `git rev-parse origin/m1-be-2` | `60816499066f9f493596e326dc41116ef32f5c4b` |
| `m1` ahead of `m1-be-2` | `git rev-list --count origin/m1-be-2..m1` | `669` |
| `m1` behind `m1-be-2` | `git rev-list --count m1..origin/m1-be-2` | `0` |

The merge base **equals the `m1-be-2` HEAD itself**. Therefore `m1` already contains every commit that
exists on `m1-be-2`, and `m1-be-2` contains nothing that `m1` lacks.

**Consequence for option (a):** there is nothing to port or merge. A merge of `m1-be-2` into `m1` would be a
no-op, and pushing WWHN-290 evidence onto `m1-be-2` would create a *second, older* copy of the closure source —
the exact duplicate-source risk the review is trying to prevent. Option (a) is therefore rejected on evidence,
not on preference.

**Consequence for option (b):** `m1` is canonical by containment. What was genuinely missing is the formal
record, which is §3 below, plus a named PM/Tech Lead sign-off on the ticket.

## 2. `m1-be-2` HEAD content check

`m1-be-2` HEAD (`6081649`) is `docs(WWHN-151): pin schema api release manifest`. It carries **no WWHN-290
artifact** (`git ls-tree -r --name-only origin/m1-be-2 | grep -i 290` returns nothing), so it is not and never
was a WWHN-290 closure source.

## 3. Supersession Register entries — SRS v1.12 §1.6 format

| Old source/statement | New controlling source/decision | Status |
|---|---|---|
| BE2 implementation branch `m1-be-2` @ `60816499066f9f493596e326dc41116ef32f5c4b` | BE implementation branch `m1` (repo `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`), which strictly contains it | **SUPERSEDED BY CONTAINMENT, effective 2026-08-18**; requires PM/Tech Lead confirmation on WWHN-290 |
| OpenAPI `info.version = 1.0.0` as published on `m1-be-2` | OpenAPI `info.version = 2.0.0` on `m1`, namespace `/api/v1` | **HISTORICAL / SUPERSEDED**; `1.0.0` is an ancestor revision of `2.0.0`, not a competing contract |
| Review reference `FE/tree/m1ee/m1` (`DEV290-P2-01`) | BE canonical `WMS_Warehouse_Hoa_Nam_BE@m1`; FE `WMS_Warehouse_Hoa_Nam_FE@m1` is **consumer evidence only** | **REMOVED / NO ARTIFACT** — branch `m1ee` does not exist in either repository |
| Two different FE consumer revisions quoted by the BE register (`9076cbd…`) and the FE handoff (`6ffab3a…`) | Single FE consumer revision `6ed417e9291297a20505a45909102ed3349b2de0` recorded once in `WWHN-290_PINS.json` | **SUPERSEDED / CONTRADICTION RESOLVED** |

### 3.1 Branch reference correction — `DEV290-P2-01`

`git ls-remote --heads origin` on the FE repository returns exactly `cross`, `dev`, `m1`. There is no `m1ee`.
The FE repository is a **consumer**: it holds contract-handoff evidence and screen consumers, never BE runtime
implementation. Any WWHN-290 review must therefore be performed against the BE repo/branch above.

## 4. Single approved contract source — `DEV290-P1-03`

- Approved contract target: **`storage/api-docs/api-docs.json` on `m1`, `openapi` `3.0.0`, `info.version` `2.0.0`, namespace `/api/v1`.**
- `1.0.0` on `m1-be-2` is superseded by lineage (§1): it is an older state of the same file on an ancestor commit,
  so there is no "two approved contracts" situation to arbitrate.
- FE / QA / Postman consumers pin the same target version on the same branch. FE1 has confirmed consumer
  compatibility on the ticket; the FE handoff now quotes the pins from `WWHN-290_PINS.json`.

### 4.1 Why WWHN-290 pins a range invariant instead of one frozen blob

The earlier package pinned the blob `c262a0d3…`. That pin **rotted within the same day**: `m1` is a shared
branch and `WWHN-328` legitimately edited the same file to publish dashboard data-scope fields, moving the blob
to `50b81fd5…` while keeping `info.version` at `2.0.0`. An absolute blob pin on a shared branch therefore cannot
survive, and a reviewer checking the register against `m1` would have found a SHA that no longer resolves.

WWHN-290 pins what WWHN-290 can actually guarantee:

| Pinned as | Claim | How it is verified |
|---|---|---|
| Live invariant | approved target is `openapi 3.0.0` / `info.version 2.0.0` / `/api/v1` | `Wwhn290ClosureEvidenceTest` asserts against the working tree on every run |
| **Range invariant** | WWHN-290's own commits change the contract file **zero** times | `git diff --name-only 57370ed…f894232 -- storage/api-docs/api-docs.json` returns empty — permanently true regardless of later tickets |
| Lineage record | blob `c262a0d3…` (sha256 `f91b786c…`) observed at pin time; moved to `50b81fd5…` by WWHN-328, version unchanged | recorded in `WWHN-290_PINS.json` `contract.lineage_note` |

This is the root fix for the defect class, not a restatement: **no WWHN-290 pin is hand-copied any more**.
Every SHA/version quoted in the markdown must equal `WWHN-290_PINS.json`, and the test enforces that equality,
so a stale pin now fails the build instead of silently misleading a reviewer.

## 5. Independent verification — one command

```bash
bash scripts/verify-wwhn290-closure.sh
```

It re-derives ancestry, change-range scope, contract target and cross-document pin equality from the live
repository and exits non-zero on any mismatch. A reviewer does not have to trust this document's prose.

## 6. What still requires a human decision

This document supplies the *evidence*. It does not self-approve:

- **PM / Tech Lead** must confirm on WWHN-290 that `m1` is the canonical BE2 branch effective 2026-08-18, superseding `m1-be-2` @ `6081649`, and that the `m1ee` reference is void.
- **Tech Lead** must confirm OpenAPI `2.0.0` on `m1` is the single approved contract source.

DEV does not sign either gate.
