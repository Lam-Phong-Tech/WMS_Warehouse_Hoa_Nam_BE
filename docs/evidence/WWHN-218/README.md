# WWHN-218 — canonical hardening evidence contract

This evidence gate is intentionally scoped to the canonical backend repository `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`, branch `m1`.

## What the CI gate proves

- exact Git SHA / run ID / run attempt / branch are captured in the artifact;
- PostgreSQL 16 is the runtime database for the gate, not SQLite;
- the permission catalog is regenerated from the currently seeded database and is not the stale 31-permission snapshot;
- `inventory.reversal.post` exists and is ACTIVE;
- inbound reverse, outbound reverse and reversal-document creation are mapped to `inventory.reversal.post`;
- no `apply-adjustment` or `stocktake` mutation route is exposed by the current permission-protected route set;
- two real concurrent HTTP requests race against the same inbound Post Receipt with different idempotency keys and the same `If-Match`; exactly one succeeds and the loser must prove optimistic concurrency with HTTP 412;
- two real concurrent HTTP requests race against the same controlled Reversal; exactly one can create the effective reversal and the final DB snapshot proves no duplicate stock effect;
- replaying the successful reversal with the same idempotency key returns `Idempotent-Replay: true` and leaves DB state unchanged;
- `WAREHOUSE_STAFF` receives backend HTTP 403 for `inbound.post` and `inventory.reversal.post` operations;
- scoped regression tests and immutable SHA-256 hashes are attached to the workflow artifact.

## Business disposition boundary

The technical gate does **not** create an `apply-adjustment` endpoint. Under the current MVP baseline, Posted stock documents remain immutable and corrections are represented by controlled Reversal/Correction with opposite immutable movements.

**Resolved.** The terminology disposition this section asked for now exists: `DISP-ADJ-001` in `docs/decision-records/DISPOSITION-APPLY-ADJUSTMENT.md` selects Option B — `apply-adjustment` is a business action name mapping to the append-only Reversal/Correction endpoints, with no independent endpoint. It is locked by `tests/Feature/Wwhn219ApplyAdjustmentDispositionTest.php`. This closes open action `BE1-OP-03`. BA acceptance on the Jira ticket is still required to move the disposition from `PENDING_BA_ACCEPT` to `ACCEPTED`.

## Human hard gates that CI must not impersonate

- QA1 must review/retest the exact CI SHA/artifact and record PASS/FAIL on WWHN-220.
- BA must formally accept `DISP-ADJ-001` on the Jira ticket. The mapping itself is now written and test-locked; what remains is the human accept, which CI must not impersonate.
- Tech Lead/PM must explicitly accept the direct-to-`m1` provenance exception when a PR cannot exist because remediation is restricted to the single `m1` branch. Exact SHA + CI run + immutable artifact are the replacement provenance evidence; this README does not self-approve that exception.
