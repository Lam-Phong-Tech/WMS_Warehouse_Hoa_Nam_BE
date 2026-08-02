# HN-DEVOPS-001 FE1 Env/Build/Health Evidence

Parent Temp Key: HN-DEVOPS-001  
Epic Temp Key: HN-EPIC-03  
Dependency: HN-DEVOPS-001-ST01  
Role: FE1  
Assignee Placeholder: HN-FE-01  
Output: Deployable frontend

## Scope

Implemented the frontend baseline for environment, build metadata, and health visibility without changing business API contracts.

## Traceability

| Acceptance area | Evidence |
|---|---|
| Deployable frontend reviewed | `npm run build` produces Vite assets from `resources/css/app.css` and `resources/js/app.js`. |
| Env contract standardized | `.env.example` now includes `VITE_APP_ENV`, `VITE_API_BASE_URL`, `VITE_HEALTH_ENDPOINT`, `VITE_BUILD_VERSION`, and `VITE_BUILD_SHA`. |
| Health visibility | The root screen calls the configured `VITE_HEALTH_ENDPOINT`, defaulting to backend `/api/health`. |
| CI-safe build | Remote build-time font fetching was removed from Vite; the shell uses system font fallback. |
| UI states | The health screen includes loading, success, error, empty, disabled refresh, and an RBAC/permission note. |
| RBAC/API contract | No FE-only authorization is introduced. The screen explicitly states backend API middleware enforces RBAC. |
| No hard-coded business data | Displayed values come from Vite env and the health response only. |
| Lint/build/test | `npm run lint`, `npm run test`, `npm run health:contract`, and `npm run build` are the FE1 verification commands. |

## VPS Domain Evidence

Verified on 2026-07-30:

| Item | Evidence |
|---|---|
| Domain DNS | `khohoanamdev.lptech.info.vn` resolves to VPS `103.97.134.164`. |
| HTTPS URL | `https://khohoanamdev.lptech.info.vn` is enabled with Let's Encrypt. |
| HTTP redirect | `http://khohoanamdev.lptech.info.vn/api/health` returns `301` to HTTPS. |
| Health smoke | `GET https://khohoanamdev.lptech.info.vn/api/health` returns `success=true`, `status=ok`. |
| Login smoke | `POST /api/v1/auth/login` with the approved UAT smoke credential from secret store returns token. |
| Master API smoke | `GET /api/v1/warehouses?keyword=KHO-TONG-HN` returns `success=true` and 5 baseline warehouse rows. |
| Swagger mixed content | `GET /api/documentation` renders Swagger CSS, JS, `api-docs.json`, and OAuth callback URLs with `https://khohoanamdev.lptech.info.vn`; no `http://khohoanamdev.lptech.info.vn` remains in the HTML. |
| Swagger server URL | Public `docs?api-docs.json` contains OpenAPI server `https://khohoanamdev.lptech.info.vn` with description `Configured API server`. |

## DO Gap Closure Evidence

Updated on 2026-07-31 for `[DO] Provision môi trường, pipeline, secrets và smoke`:

| Evidence | Location |
|---|---|
| DO gap closure pack | `docs/devops/HN-DEVOPS-001_DO_DEV_UAT_CICD/README.md` |
| Gap-by-gap disposition | `docs/devops/HN-DEVOPS-001_DO_DEV_UAT_CICD/00-gap-closure-matrix.md` |
| DEV/UAT smoke | `docs/devops/HN-DEVOPS-001_DO_DEV_UAT_CICD/01-dev-uat-environment-smoke-evidence.md` |
| CI/CD activation pack | `docs/devops/HN-DEVOPS-001_DO_DEV_UAT_CICD/02-cicd-pipeline-activation-pack.md` |
| Candidate manifest | `docs/devops/HN-DEVOPS-001_DO_DEV_UAT_CICD/03-release-candidate-manifest-wwhn-154.md` |
| Backup/restore/rollback drill | `docs/devops/HN-DEVOPS-001_DO_DEV_UAT_CICD/04-backup-restore-rollback-drill.md` |
| Monitoring/alert evidence | `docs/devops/HN-DEVOPS-001_DO_DEV_UAT_CICD/05-monitoring-alert-evidence.md` |
| Security scan disposition | `docs/devops/HN-DEVOPS-001_DO_DEV_UAT_CICD/06-security-scan-disposition.md` |
| Reviewer rerun checklist | `docs/devops/HN-DEVOPS-001_DO_DEV_UAT_CICD/07-reviewer-rerun-checklist.md` |

## Environment Variables

| Key | Purpose | Local default |
|---|---|---|
| `VITE_APP_NAME` | Display application name | `${APP_NAME}` |
| `VITE_APP_ENV` | Display environment and guard production-like metadata | `${APP_ENV}` |
| `VITE_API_BASE_URL` | Approved API base for FE integrations | `${APP_URL}/api/v1` |
| `VITE_HEALTH_ENDPOINT` | Health endpoint called by FE shell | `${APP_URL}/api/health` |
| `VITE_BUILD_VERSION` | Release/version label for QA evidence | `local-dev` |
| `VITE_BUILD_SHA` | Commit SHA for deploy traceability | `local` |

## Open Points / Blockers

No P0/P1 FE1 blocker remains for env/build/health. Business-module UI integration still depends on approved endpoint/payload details from the relevant feature tasks.
