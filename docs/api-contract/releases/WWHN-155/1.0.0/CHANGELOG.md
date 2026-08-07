# WWHN-155 Health Contract — Changelog

## 1.0.0 — 2026-08-07

### Added

- Canonical public readiness endpoint `GET /api/v1/health`.
- Explicit HTTP `200` and sanitized HTTP `503` response schemas.
- Required fields: `version`, `environment`, `server_time`, `request_id`, component checks and response metadata.
- Seed markers for admin, baseline warehouse and baseline PV product.
- `X-Request-ID` propagation and invalid-ID replacement tests.
- `Cache-Control: no-store` readiness policy.
- Reproducible PostgreSQL evidence workflow with raw logs, HTTP samples, DB marker output, JUnit and SHA-256 manifest.

### Removed

- Legacy endpoint `/api/health`. It must return HTTP `404` and must not be used by FE/QA/DevOps.

### Security

- Degraded responses expose status only and do not expose credentials, DSN, SQLSTATE, exception class, file, line or stack trace.

### Compatibility

- Contract ID remains `WWHN-156-health-v1`.
- FE base URL remains `/api/v1`; FE health path remains `/health`.
