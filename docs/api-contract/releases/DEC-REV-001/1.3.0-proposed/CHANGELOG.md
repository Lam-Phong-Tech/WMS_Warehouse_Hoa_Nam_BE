# DEC-REV-001 contract changelog

## 1.3.0-proposed — 2026-08-06

### Added

- Immutable release directory for OpenAPI, Postman, standalone JSON Schema, manifest and checksums.
- Explicit publisher/reviewer separation and anti-impersonation gate.
- Runtime divergence register between the target `/api/v1/reversals` contract and current document-specific reverse routes.
- Exact permission/data-scope metadata, transaction boundary and idempotency semantics.
- Contract cases for 201, replay 200, changed-payload 409, missing-key 428, invalid-key 422, 401 and 403.
- CI release verifier and exact-SHA evidence artifact workflow.
- Source register and traceability matrix for WWHN-17/31/33/35/40/136.

### Changed

- Contract version from `1.2.0-proposed` to `1.3.0-proposed`.
- Canonical alias files are required to be byte-identical to immutable release artifacts.
- Closure wording is fail-closed: documentation completeness does not imply runtime implementation or independent acceptance.

### Compatibility and adoption

Current observed runtime at parent commit `7d7091c48798114b5bbf7ff4c0b58617d177c737` exposes:

- `POST /api/v1/inbound-documents/{id}/reverse`
- `POST /api/v1/outbound-documents/{id}/reverse`
- permission `stock_document.reverse`

The target proposal remains:

- `POST /api/v1/reversals`
- permission `stock.reversal.create`

No compatibility alias or implementation is claimed by this release. Adoption requires a separate implementation change, exact-SHA runtime evidence and independent FE/QA acceptance.

## 1.2.0-proposed — 2026-08-05

- Addressed BA changes for BRD v3.11 / SRS-FRD v1.4 / BPD v1.3.
- Standardized `IDEMPOTENCY_ERROR`, line-level `PENDING_RETURN`, affected tickets and open-point disposition.
