// ---------------------------------------------------------------------------
// Đầu dò ĐỒNG THỜI — tách "chậm vì tải" khỏi "chậm vì tầng nào".
//
//   MSYS_NO_PATHCONV=1 docker compose -f load/docker-compose.k6.yml run --rm k6 run \
//     -e VUS=40 -e DURATION=40s -e TARGET=health /scripts/probe-concurrency.js
//   ... -e TARGET=products      # endpoint có auth + DB + cache
//
// Closed model (constant-vus): VUS người dùng bấm liên tục không nghỉ. Nếu
// p95 của /health (không DB, không auth) đã bung ở 40 VU thì nghẽn nằm ở tầng
// nginx ↔ php-fpm / runtime, không phải ở logic ứng dụng.
// ---------------------------------------------------------------------------
import http from 'k6/http';
import { check } from 'k6';
import { CONFIG, authHeaders } from './lib/config.js';

const VUS = Number(__ENV.VUS || 40);
const DURATION = __ENV.DURATION || '40s';
const TARGET = __ENV.TARGET || 'health';

export const options = {
  scenarios: { probe: { executor: 'constant-vus', vus: VUS, duration: DURATION } },
  thresholds: { http_req_failed: ['rate<0.01'] },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  if (TARGET === 'health') return { token: null };
  const res = http.post(
    `${CONFIG.baseUrl}/api/v1/auth/login`,
    JSON.stringify({ email: __ENV.LOGIN_EMAIL || 'admin-01@gmail.com', password: CONFIG.password }),
    { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } },
  );
  const body = res.json();
  const token = body?.data?.access_token || body?.access_token;
  if (!token) throw new Error('login failed: ' + res.status + ' ' + String(res.body).slice(0, 200));
  return { token };
}

const PATHS = {
  health: '/api/v1/health',
  products: '/api/v1/products?page=1&per_page=20',
  inventory: '/api/v1/inventory?page=1&per_page=20',
  hub: '/api/v1/reports/hub',
};

export default function (data) {
  const url = `${CONFIG.baseUrl}${PATHS[TARGET] || PATHS.health}`;
  const params = data.token ? authHeaders(data.token) : { headers: { Accept: 'application/json' } };
  const res = http.get(url, params);
  check(res, { 'HTTP 2xx': (r) => r.status >= 200 && r.status < 300 });
}
