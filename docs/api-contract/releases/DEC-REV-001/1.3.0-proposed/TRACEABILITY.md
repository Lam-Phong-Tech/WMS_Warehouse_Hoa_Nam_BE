# DEC-REV-001 — Traceability matrix

| Jira | Baseline/rule to pin | Immutable evidence | Independent owner | Required result |
|---|---|---|---|---|
| WWHN-17 | Append-only reversal; line-level `PENDING_RETURN`; BRD v3.11 controls | `openapi.yaml`, `reversal.schema.json`, DEC-REV-001 §§2–5 | BAS/BA | `TRACEABILITY-PINNED` |
| WWHN-31 | Canonical endpoint, schemas, 401/403/404/409/422/428, permission and version/hash | `openapi.yaml`, `RELEASE_MANIFEST.json`, `CHANGELOG.md` | Tech Lead | `PUBLISHED-VERIFIED` |
| WWHN-33 | Positive/negative/RBAC/idempotency/replay/conflict/race/rollback/audit/reconciliation scope | `postman_collection.json`, DEC-REV-001 §8 | QA | `REVIEWED-SUFFICIENT` |
| WWHN-35 | Backend permission/data scope, audit fields, no-stack-trace error envelope | `openapi.yaml`, `reversal.schema.json` | QA/Tech Lead | `REVIEWED-SUFFICIENT` |
| WWHN-40 | FE/BE/QA contract gate, payload and compatibility/adoption gap | `openapi.yaml`, `CHANGELOG.md` | FE + BA | `REVIEWED-SUFFICIENT` |
| WWHN-136 | Pin all rows above with reviewer/date/result and exact SHA/hash | This matrix and Jira comment IDs | BAS | `TRACEABILITY-PINNED` |

## Runtime divergence disposition

At parent commit `7d7091c48798114b5bbf7ff4c0b58617d177c737`, runtime routes remain document-specific and use `stock_document.reverse`. The immutable release defines the target proposal only. No ticket may infer that `/api/v1/reversals` is deployed until a separate implementation ticket and exact-SHA QA evidence prove it.
