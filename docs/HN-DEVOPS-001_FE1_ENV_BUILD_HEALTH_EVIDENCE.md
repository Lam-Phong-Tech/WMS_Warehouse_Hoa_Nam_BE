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
