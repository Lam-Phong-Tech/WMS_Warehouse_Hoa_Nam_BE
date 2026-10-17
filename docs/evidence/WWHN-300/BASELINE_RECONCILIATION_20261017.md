# GoLive Source Baseline Reconciliation

Date: 2026-10-17, Asia/Ho_Chi_Minh.
Reviewed source: e2d63af608b9525deeff93bb4acbc587fe320be3.
Scope: the seven Wwhn300BackendGoLiveCheckTest failures in CI 39022788425.

## Status And Authority

This is a technical reconciliation requested by the repository user, not a
fabricated Tech Lead, BA or QA signature. Runtime acceptance remains PENDING.
The existing contract amendment approvals and feature activation flags are not
changed. No production activation, serving-DB migration/seed or restore is part
of this change. Passing a testing fixture is not runtime release acceptance.

## Fail-Forward Decisions

The four migration implementations are unchanged. Their empty down methods are
intentional data-preservation boundaries, not missing destructive teardown.
They join the exact-name allowlist, with individual reasons rather than a count
threshold or a wildcard. A new unlisted fail-forward migration must still FAIL.

| Migration | Retained invariant | Rejected rollback |
| --- | --- | --- |
| 2026_09_15_120000_fix_idempotency_record_mirror_scope_key_upsert | Logical UNIQUE(scope, idempotency_key) mirror and stable receipt identity | Restoring id-only upsert reintroduces SQLSTATE 23505 |
| 2026_10_08_130000_register_p08_history_read_permissions | Capability names and administrator grants after deployment | Deleting permissions/grants without an authorized IAM decision |
| 2026_10_08_140000_create_p09_warranty_requests_table | Original command keys, terminal receipts and UNKNOWN reconciliation | Dropping receipts and enabling duplicate commands after code rollback |
| 2026_10_15_060000_create_warranty_handovers_table | Encrypted receiver/note, entered date, checkbox, actor and request | Silently deleting business handover evidence |

Prefer a forward correction that preserves evidence. A code-only rollback needs
an explicitly compatible build. Restore is a separate DevOps decision requiring
a verified backup and reconciliation of writes after that snapshot; never run it
to make GoLive pass. Do not disable triggers or rewrite POSTED documents.

## Route Review

The pinned route map moves from 376 to 430 existing runtime operations. The 54
additions match amendments AMD-API-029 through AMD-API-042 in
docs/contracts/HoaNam_Current_Baseline_Contract.json; their live approvals remain
PENDING. The only changed existing entry is dashboard/summary, whose source
already permits warranty.view or warranty.manage as well as inbound/outbound
read. Source/warehouse checks continue inside the owner; no runtime ACL changes.

Reviewed boundaries:

- P03/P04/P05 context and draft reads/writes: exact controller actions declared
  in PermissionEnforcementMap; operation rights plus actor/session/warehouse.
- P06: sku.view and item_instance.view; stock adds inventory.view; timelines add
  inventory.trace or warranty read/manage. Context only returns capabilities.
- P08/P12/P23: authenticated source reads, scoped before count/paging, not writers.
- P07: physical_code.assign_rfid / inventory.trace and original owner identity.
- P09/P20: warranty read/manage, with document/component and warehouse guards in
  the owner before projection or receipt lookup.
- P18: parent ACL is checked before actor/session/warehouse/key/file-hash receipt
  lookup. UNKNOWN does not grant upload retry.
- P14: shift rights, owner tracking, close-check and local CLEAN remain required.
  Anonymous recovery is support intake only, flag-gated and capability-bound by
  X-Recovery-Proof; it cannot grant login/reset authority.
- P19: warranty.component_issue and canonical command scope/recovery gates remain.

No prefix-wide exemption, permission middleware removal, automatic role grant,
new route, mutation, migration edit, seeder edit or .env change is introduced.

## Regression Requirements

Run all Wwhn300BackendGoLiveCheckTest cases, not just the seven formerly failing
assertions. Keep negative coverage for wrong write permissions, missing policy
files, missing unique indexes, audit/reconciliation loss and production defaults.
New probes also require an otherwise protected unpinned route and an unlisted
empty-down migration to fail. GoLiveOwnerGuardInventoryTest keeps exact owner
action binding, including replacement-handler denial.
