# Hướng dẫn Kiến trúc & Phát triển WMS Hoa Nam

Phiên bản: v1.0
Cập nhật: 2026-07-31
Chủ sở hữu: FE1 / Tech Lead

## Giới thiệu

Tài liệu này mô tả kiến trúc, quy ước đặt tên, cấu trúc module, hợp đồng API,
RBAC, xử lý lỗi, tiêu chuẩn build/env/health và quy trình đóng góp cho
frontend WMS Hoa Nam. Mọi đóng góp code phải tuân theo hướng dẫn này.

## Kiến trúc Tổng quan

Frontend WMS là ứng dụng React/Vite/TypeScript SPA (Single Page Application)
cho Web Admin. Nó không phải là Next.js app và không yêu cầu SSR/SSG.

### Nguyên tắc Chính

1. **Module-based architecture**: Mỗi module nghiệp vụ độc lập trong `src/modules/<module>/`
2. **Shared utilities**: Code dùng chung đặt trong `src/shared/`
3. **API-first**: Tất cả communication với backend qua REST API với envelope chuẩn
4. **RBAC**: Phân quyền chi tiết theo permission code, không phải role name
5. **Type safety**: TypeScript strict mode, Zod validation
6. **Testing**: Unit tests (Vitest) + E2E tests (Playwright)

## Cấu trúc Thư mục

```
src/
├── app/                      # App shell, routing, providers
│   ├── layouts/             # Shared layouts (MainLayout, SideNav, Header, Footer)
│   ├── providers/           # AppProviders (Query, Theme, Auth, Toast)
│   └── router/              # Route definitions, guards, access control
├── modules/                  # Feature modules (business logic)
│   ├── master-data/         # SKU, Item, Physical Code management
│   │   ├── components/
│   │   ├── hooks/
│   │   ├── pages/
│   │   ├── services/
│   │   ├── schemas/
│   │   └── types/
│   ├── inbound/             # Inbound documents, receiving, putaway
│   ├── outbound/            # Outbound documents, shipping, picking
│   ├── inventory/           # Inventory balances, movements
│   ├── scan/                # Scan operations, physical code classification
│   ├── stocktake/           # Stocktake sessions, counts, adjustments
│   ├── warranty/            # Warranty cases, events, defects
│   ├── reverse/             # Reversal documents, adjustments
│   ├── reports/             # Reports hub, exports
│   ├── dashboard/           # Dashboard KPI, quick stats
│   ├── audit-logs/          # Audit trail, system logs
│   ├── roles/               # Role management, permission matrix
│   ├── employees/           # Employee management, org chart
│   ├── companies/           # Company management
│   ├── departments/         # Department management
│   ├── positions/           # Position management
│   └── component-catalog/   # Component showcase for BA/QA
├── shared/                   # Shared utilities (used by 2+ modules)
│   ├── api/                 # HTTP client, endpoints, interceptors
│   │   ├── http.ts          # Axios instance with interceptors
│   │   ├── endpoints.ts     # API endpoint constants
│   │   ├── api-error.ts     # Error handling utilities
│   │   └── query-client.ts  # TanStack Query client config
│   ├── components/          # Reusable UI components
│   │   ├── layout/          # AppShell, MainLayout
│   │   ├── forms/           # FormInput, FormSelect, ScanInput
│   │   ├── data-display/    # TableContainer, DataTable, StatusTag
│   │   ├── feedback/        # ApiErrorAlert, NoPermissionState, ConfirmDialog
│   │   ├── permission/      # RequirePermission, PermissionGuard, ActionButton
│   │   ├── navigation/      # SideNav, Breadcrumb
│   │   └── ui-states/       # Loading, EmptyState, ErrorState
│   ├── hooks/               # Custom React hooks
│   │   ├── use-login.ts
│   │   ├── use-auth.ts
│   │   └── use-permissions.ts
│   ├── utils/               # Utility functions
│   │   ├── storage-url.ts
│   │   ├── formatters.ts
│   │   └── validators.ts
│   ├── types/               # Shared TypeScript types
│   │   ├── api-response.ts
│   │   ├── pagination.ts
│   │   └── common.ts
│   ├── constants/           # Shared constants
│   │   ├── permissions.ts   # PERMISSION_CATALOG
│   │   └── app-config.ts
│   └── store/               # Global state (Zustand)
│       └── auth.store.ts
├── assets/                   # Static assets (images, icons, fonts)
├── lib/                      # Third-party library configs
└── test/                     # Global test utilities
```

## Quy ước Module

Mỗi module nghiệp vụ phải có cấu trúc:

```
src/modules/<module>/
├── components/              # Components specific to this module
├── hooks/                   # Custom hooks for this module
├── pages/                   # Page components
├── services/                # API service functions
├── schemas/                 # Zod validation schemas
├── types/                   # TypeScript type definitions
└── index.ts                 # Public exports (optional)
```

### Quy tắc Đặt tên

- **Files**: camelCase cho hooks (`use-login.ts`), PascalCase cho components (`LoginPage.tsx`)
- **Components**: PascalCase (`LoginForm`, `SkuTable`)
- **Hooks**: camelCase với prefix `use-` (`use-login.ts`, `use-skus.ts`)
- **Services**: camelCase (`authService.ts`, `skuService.ts`)
- **Types/Interfaces**: PascalCase (`SkuPayload`, `LoginRequest`)
- **Constants**: UPPER_SNAKE_CASE (`API_ENDPOINTS`, `PERMISSIONS`)

## API Integration

### HTTP Client

Tất cả API calls phải đi qua `src/shared/api/http.ts`:

```typescript
// ✅ ĐÚNG - Sử dụng shared http client
import { http } from '@/shared/api/http';
import { DASHBOARD_KPI } from '@/shared/api/endpoints';

const { data } = await http.get(DASHBOARD_KPI);

// ❌ SAI - Không gọi axios trực tiếp
import axios from 'axios';
const { data } = await axios.get('/api/dashboard');
```

### API Envelope

Tất cả API responses follow envelope format:

**Success:**
```json
{
  "success": true,
  "message": "Operation successful",
  "data": {},
  "meta": {
    "request_id": "uuid",
    "timestamp": "2026-07-31T10:00:00+07:00"
  }
}
```

**Error:**
```json
{
  "success": false,
  "message": "Validation failed",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "field_name": ["Error message"]
  },
  "meta": {
    "request_id": "uuid",
    "timestamp": "2026-07-31T10:00:00+07:00"
  }
}
```

### Endpoint Constants

Tất cả endpoints phải được định nghĩa trong `src/shared/api/endpoints.ts`:

```typescript
export const ENDPOINTS = {
  AUTH: {
    LOGIN: '/auth/login',
    LOGOUT: '/auth/logout',
    ME: '/auth/me',
    REFRESH: '/auth/refresh',
  },
  SKUS: {
    LIST: '/skus',
    CREATE: '/skus',
    UPDATE: '/skus/:id',
    IMPORT: '/skus/import-excel',
  },
} as const;
```

## State Management

### Server State (TanStack Query)

Sử dụng TanStack Query cho tất cả server state:

```typescript
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { skuService } from './services/skuService';

// Query
const { data, isLoading, error } = useQuery({
  queryKey: ['skus', filters],
  queryFn: () => skuService.getSkus(filters),
});

// Mutation
const mutation = useMutation({
  mutationFn: skuService.createSku,
  onSuccess: () => {
    queryClient.invalidateQueries({ queryKey: ['skus'] });
    toast.success('SKU created successfully');
  },
});
```

### Client State (Zustand)

Sử dụng Zustand cho global client state (auth, UI preferences):

```typescript
import { useAuthStore } from '@/shared/store/auth.store';

const { user, permissions, logout } = useAuthStore();
```

## RBAC & Permissions

### Permission Codes

Permission codes tuân theo catalog:

```typescript
// src/shared/constants/permissions.ts
export const PERMISSION_CATALOG = {
  // Master Data
  SKU_MANAGE: 'sku.manage',
  MASTER_MANAGE: 'master.manage',
  
  // Inbound
  INBOUND_CREATE: 'inbound.create',
  INBOUND_POST: 'inbound.post',
  
  // Outbound
  OUTBOUND_CREATE: 'outbound.create',
  OUTBOUND_POST: 'outbound.post',
  
  // Inventory
  INVENTORY_VIEW: 'inventory.view',
  
  // Scan
  SCAN_CLASSIFY: 'scan.classify',
  INVENTORY_TRACE: 'inventory.trace',
  
  // Stocktake
  STOCKTAKE_COUNT: 'stocktake.count',
  
  // Warranty
  WARRANTY_MANAGE: 'warranty.manage',
  
  // Reverse
  INVENTORY_REVERSAL_POST: 'inventory.reversal.post',
  
  // Reports
  REPORT_VIEW: 'report.view',
  
  // Audit
  AUDIT_LOG_VIEW: 'AUDIT_LOG_VIEW',
} as const;
```

### Route Protection

```typescript
// src/app/router/access.ts
export const ROUTE_PERMISSIONS: Record<string, string | string[]> = {
  '/master-data': ['sku.manage', 'master.manage'],
  '/inbound': ['inbound.create', 'inbound.post'],
  '/outbound': ['outbound.create', 'outbound.post'],
  '/inventory': 'inventory.view',
  '/scan': ['scan.classify', 'inventory.trace'],
  '/stocktake': 'stocktake.count',
  '/warranty': 'warranty.manage',
  '/reverse': 'inventory.reversal.post',
  '/reports': 'report.view',
  '/audit-logs': 'AUDIT_LOG_VIEW',
};
```

### Component-level Permission

```typescript
import { RequirePermission } from '@/shared/components/permission/RequirePermission';
import { ActionButton } from '@/shared/components/permission/ActionButton';

// Route guard
<RequirePermission permission="sku.manage">
  <SkuManagementPage />
</RequirePermission>

// Action guard
<ActionButton 
  permission="sku.delete" 
  onConfirm={handleDelete}
>
  Delete
</ActionButton>
```

## Error Handling

### API Errors

Tất cả API errors được xử lý qua `src/shared/api/api-error.ts`:

```typescript
import { getApiErrorMessage, extractFieldErrors } from '@/shared/api/api-error';

try {
  await skuService.createSku(data);
} catch (error) {
  const message = getApiErrorMessage(error);
  toast.error(message);
  
  // Field-level errors for forms
  const fieldErrors = extractFieldErrors(error);
  if (fieldErrors) {
    setErrors(fieldErrors);
  }
}
```

### Error Categories

1. **401 Unauthorized**: Token expired → auto refresh → retry
2. **403 Forbidden**: 
   - `PASSWORD_CHANGE_REQUIRED` → redirect to `/change-password`
   - Permission denied → show `NoPermissionState`
3. **422 Validation**: Field errors → display in form
4. **409 Conflict**: Duplicate/conflict → show error message
5. **500 Server Error`: Generic error → show `ApiErrorAlert`

## Forms & Validation

### React Hook Form + Zod

```typescript
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const skuSchema = z.object({
  code: z.string().min(1, 'SKU code is required'),
  name: z.string().min(1, 'Name is required'),
  status: z.enum(['ACTIVE', 'INACTIVE']),
});

type SkuFormData = z.infer<typeof skuSchema>;

const SkuForm = () => {
  const { control, handleSubmit, formState: { errors } } = useForm<SkuFormData>({
    resolver: zodResolver(skuSchema),
  });
  
  // ...
};
```

## Testing

### Unit Tests (Vitest)

```typescript
import { describe, it, expect } from 'vitest';
import { formatSkuCode } from '@/shared/utils/formatters';

describe('formatSkuCode', () => {
  it('should format SKU code correctly', () => {
    expect(formatSkuCode('ABC123')).toBe('ABC-123');
  });
});
```

### E2E Tests (Playwright)

```typescript
import { test, expect } from '@playwright/test';

test('login flow', async ({ page }) => {
  await page.goto('/login');
  await page.fill('[name="email"]', 'admin@hoanam.com');
  await page.fill('[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL('/dashboard');
});
```

## Build & Deployment

### Build Commands

```bash
# Development
yarn dev

# Production build
yarn build

# Preview production build
yarn preview

# Type check
yarn typecheck

# Lint
yarn lint

# Format check
yarn format:check

# Tests
yarn test
yarn test:e2e
```

### Environment Variables

| Variable | Description | Required | Default |
| --- | --- | --- | --- |
| `VITE_APP_ENV` | Environment name | Yes | `local` |
| `VITE_API_BASE_URL` | API base URL | Yes | `http://localhost:8000/api/v1` |
| `VITE_API_TIMEOUT_MS` | API timeout in ms | No | `10000` |
| `VITE_API_WITH_CREDENTIALS` | Send credentials | No | `false` |
| `VITE_STORAGE_URL` | Storage URL (optional) | No | - |
| `VITE_API_HEALTH_PATH` | Health check path | No | `/health` |

### Health Check

Frontend build produces:
- `dist/index.html` - Main entry point
- `dist/health.json` - Health artifact for deployment verification

```bash
# Verify health artifact
yarn health:check
```

## CI/CD Pipeline

### Required Checks

Mỗi PR phải vượt qua:

1. **Lint**: `yarn lint` - ESLint checks
2. **Format**: `yarn format:check` - Prettier checks
3. **Type Check**: `yarn typecheck` - TypeScript compilation
4. **Unit Tests**: `yarn test` - Vitest tests
5. **Build**: `yarn build` - Production build
6. **Health Check**: `yarn health:check` - Verify artifacts
7. **API Mapping**: `yarn check:api-mapping` - Verify endpoint usage
8. **E2E Tests**: `yarn test:e2e` - Playwright tests (if UI changes)

### GitHub Actions

CI workflow định kỳ chạy trên mỗi PR và push đến `main`/`m1`.

## Performance

### Code Splitting

- Route-based splitting: Mỗi route được lazy load
- Component-based splitting: Large components được split
- Vendor splitting: React, Ant Design được split riêng

### Optimization

- TanStack Query caching giảm duplicate requests
- Memoization cho expensive computations
- Virtual scrolling cho large lists (Ant Design Table)
- Image optimization qua `src/assets/`

## Security

### Authentication

- Access token: Lưu trong memory only (không persist)
- Refresh token: HttpOnly cookie (backend managed)
- Session bootstrap: `bootstrapAuth()` trên app startup
- Auto logout: Token expired → redirect to `/login`

### Authorization

- Route-level: `RequirePermission` component
- Action-level: `ActionButton` component
- API-level: Backend enforce tất cả permissions
- FE chỉ là UX gate, không phải security boundary

### XSS Prevention

- React auto-escape JSX
- Không sử dụng `dangerouslySetInnerHTML` trừ khi cần thiết
- Sanitize user input trước khi render

## Troubleshooting

### Common Issues

1. **Module not found**: Kiểm tra import alias `@/` trỏ đến `src/`
2. **API 401**: Token expired → check refresh token flow
3. **API 403**: Check permission codes trong `ROUTE_PERMISSIONS`
4. **Build fails**: Run `yarn install` và clear `node_modules/.vite`

### Debug Mode

```bash
# Enable debug logs
VITE_APP_ENV=development yarn dev
```

## Contributing

### Workflow

1. Tạo branch từ `main` hoặc `m1`
2. Implement feature/fix với tests
3. Đảm bảo tất cả checks pass
4. Tạo PR với mô tả rõ ràng
5. Code review bởi ít nhất 1 FE team member
6. Merge sau khi approved

### Commit Convention

Sử dụng Conventional Commits:

```
feat: add SKU import functionality
fix: resolve token refresh loop
docs: update API documentation
test: add unit tests for auth service
refactor: simplify permission checking logic
```

## Tài liệu Liên quan

- `docs/WMS_FRONTEND_STANDARDIZATION.md` - Frontend standards
- `docs/design-system/DESIGN_TOKENS.md` - Design tokens
- `docs/design-system/COMPONENT_INVENTORY.md` - Component catalog
- `docs/evidence/` - Implementation evidence và acceptance criteria
- `system-design.md` - System design rules cho code review

## Liên hệ

- **Tech Lead**: Quang Nguyen Thanh
- **FE Team**: HN-FE-01, HN-FE-02
- **Slack**: #wms-frontend

---

**Last Updated**: 2026-07-31
**Maintained By**: FE1 Team
**Review Cycle**: Mỗi sprint