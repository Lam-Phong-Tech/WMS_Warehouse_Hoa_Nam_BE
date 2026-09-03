// Danh mục luồng đọc của WMS Hoà Nam, kèm trọng số phản ánh tần suất thật.
//
// Mỗi mục: { group, name, path, roles }
//  - group : nhóm nghiệp vụ, dùng để gắn tag và tách chỉ số theo màn hình.
//  - path  : đường dẫn tương đối (đã kèm query mặc định giống FE gọi).
//  - roles : 'any'   -> mọi tài khoản đều gọi được (không cần permission riêng)
//            'admin' -> chỉ dùng token SUPER_ADMIN để tránh 403 làm nhiễu số đo.
//
// TẤT CẢ endpoint ở đây là GET/đọc. Kịch bản GHI nằm riêng trong writes.js và
// mặc định tắt (ENABLE_WRITES=false) để không sinh dữ liệu rác.

const TODAY = '2026-08-31';
const MONTH_AGO = '2026-07-01';

export const READ_FLOWS = [
  // --- Bảng điều khiển: màn hình đầu tiên mọi người mở sau khi đăng nhập ---
  { group: 'dashboard', weight: 8, path: '/api/v1/reports/dashboard-quick', roles: 'admin' },
  { group: 'dashboard', weight: 7, path: '/api/v1/reports/dashboard-overview', roles: 'admin' },
  { group: 'dashboard', weight: 5, path: '/api/v1/reports/hub', roles: 'admin' },

  // --- Tồn kho: nhóm truy vấn nặng và được mở nhiều nhất trong ca ---
  { group: 'inventory', weight: 8, path: '/api/v1/inventory?page=1&per_page=20', roles: 'admin' },
  { group: 'inventory', weight: 7, path: '/api/v1/inventory/balances?page=1&per_page=20', roles: 'admin' },
  { group: 'inventory', weight: 6, path: '/api/v1/stock-movements?page=1&per_page=20', roles: 'admin' },
  { group: 'inventory', weight: 4, path: '/api/v1/item-instances?page=1&per_page=20', roles: 'admin' },

  // --- Dữ liệu nền: SKU, sản phẩm, kho, nhãn hiệu ---
  { group: 'master', weight: 6, path: '/api/v1/skus?page=1&per_page=20', roles: 'admin' },
  { group: 'master', weight: 4, path: '/api/v1/products?page=1&per_page=20', roles: 'admin' },
  { group: 'master', weight: 2, path: '/api/v1/warehouses', roles: 'admin' },
  { group: 'master', weight: 2, path: '/api/v1/brands', roles: 'admin' },
  { group: 'master', weight: 2, path: '/api/v1/product-categories', roles: 'admin' },
  { group: 'master', weight: 2, path: '/api/v1/agencies', roles: 'admin' },

  // --- Chứng từ nhập/xuất/bảo hành ---
  { group: 'documents', weight: 6, path: '/api/v1/inbound-documents?page=1&per_page=20', roles: 'admin' },
  { group: 'documents', weight: 6, path: '/api/v1/outbound-documents?page=1&per_page=20', roles: 'admin' },
  { group: 'documents', weight: 4, path: '/api/v1/outbound-requests?page=1&per_page=20', roles: 'admin' },
  { group: 'documents', weight: 3, path: '/api/v1/warranty-cases?page=1&per_page=20', roles: 'admin' },

  // --- Báo cáo nặng: quét nhiều dòng, hay bị nghẽn nhất khi tải cao ---
  { group: 'reports', weight: 3, path: '/api/v1/reports/inventory-balance', roles: 'admin' },
  { group: 'reports', weight: 3, path: `/api/v1/reports/inventory-xnt?from_date=${MONTH_AGO}&to_date=${TODAY}`, roles: 'admin' },
  { group: 'reports', weight: 2, path: '/api/v1/reports/inventory-ledger', roles: 'admin' },
  { group: 'reports', weight: 2, path: '/api/v1/reports/movement-trace', roles: 'admin' },
  { group: 'reports', weight: 2, path: '/api/v1/reports/document-history', roles: 'admin' },

  // --- Quản trị người dùng / nhật ký ---
  { group: 'iam', weight: 2, path: '/api/v1/users?page=1&per_page=20', roles: 'admin' },
  { group: 'iam', weight: 1, path: '/api/v1/roles', roles: 'admin' },
  { group: 'iam', weight: 1, path: '/api/v1/audit-logs?page=1&per_page=20', roles: 'admin' },

  // --- Chuông thông báo: FE gọi định kỳ cho MỌI người đang mở app ---
  { group: 'notification', weight: 8, path: '/api/v1/notifications/unread-count', roles: 'any' },
  { group: 'notification', weight: 4, path: '/api/v1/notifications?page=1&per_page=10', roles: 'any' },

  // --- Phiên đăng nhập: FE gọi khi tải lại trang / khôi phục phiên ---
  { group: 'session', weight: 5, path: '/api/v1/auth/me', roles: 'any' },

  // --- Cổng công khai (không cần đăng nhập) ---
  { group: 'public', weight: 2, path: '/api/v1/public/home', roles: 'public' },
  { group: 'public', weight: 2, path: '/api/v1/public/products?page=1&per_page=20', roles: 'public' },
  { group: 'public', weight: 1, path: '/api/v1/pv/products', roles: 'public' },
];

const TOTAL_WEIGHT = READ_FLOWS.reduce((sum, f) => sum + f.weight, 0);

/** Chọn một luồng theo trọng số. */
export function pickFlow(rand) {
  let cursor = rand * TOTAL_WEIGHT;
  for (const flow of READ_FLOWS) {
    cursor -= flow.weight;
    if (cursor <= 0) return flow;
  }
  return READ_FLOWS[READ_FLOWS.length - 1];
}

export { TOTAL_WEIGHT };
